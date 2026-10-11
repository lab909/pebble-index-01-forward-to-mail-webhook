"""Verified local TLS SMTP, STARTTLS enforcement, and timeout failure isolation."""
import json
import os
from pathlib import Path
import shutil
import socket
import ssl
import subprocess
import tempfile
import threading
import time

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BINARY', 'php')
with tempfile.TemporaryDirectory(prefix='pebble-alert-http-') as temporary:
    root = Path(temporary)
    (root / 'public').mkdir()
    (root / 'bin').mkdir()
    shutil.copy(ROOT / 'bin/test-alert-email.php', root / 'bin/test-alert-email.php')
    for name in ('src', 'config', 'vendor'):
        (root / name).symlink_to(ROOT / name, target_is_directory=True)
    shutil.copy(ROOT / 'public/webhook.php', root / 'public/webhook.php')
    certificate, key = root / 'cert.pem', root / 'key.pem'
    subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', str(key), '-out', str(certificate),
                    '-days', '1', '-subj', '/CN=localhost', '-addext', 'subjectAltName=DNS:localhost'],
                   check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    tls.load_cert_chain(certificate, key)
    messages, commands = [], []
    listeners = []
    stop = threading.Event()
    def listener(mode):
        sock = socket.socket()
        sock.bind(('127.0.0.1', 0))
        sock.listen()
        sock.settimeout(.1)
        listeners.append(sock)
        def run():
            while not stop.is_set():
                try:
                    client, _ = sock.accept()
                except socket.timeout:
                    continue
                except OSError:
                    return
                if mode == 'stall':
                    stop.wait(3)
                    client.close()
                    continue
                try:
                    with (tls.wrap_socket(client, server_side=True) if mode == 'tls' else client) as connection:
                        connection.settimeout(3)
                        with connection.makefile('rwb') as stream:
                            stream.write(b'220 localhost ESMTP\r\n'); stream.flush()
                            while True:
                                line = stream.readline()
                                if not line:
                                    break
                                commands.append((mode, line))
                                command = line.upper()
                                if command.startswith(b'EHLO'):
                                    reply = b'250-localhost\r\n250 AUTH PLAIN\r\n'
                                elif command.startswith(b'AUTH PLAIN'):
                                    reply = b'235 Authenticated\r\n'
                                elif command.startswith(b'DATA'):
                                    stream.write(b'354 Send message\r\n'); stream.flush()
                                    lines = []
                                    while (line := stream.readline()) != b'.\r\n':
                                        if not line:
                                            raise RuntimeError('Unexpected EOF')
                                        lines.append(line)
                                    messages.append(b''.join(lines))
                                    reply = b'250 Accepted\r\n'
                                elif command.startswith(b'QUIT'):
                                    stream.write(b'221 Bye\r\n'); stream.flush(); break
                                else:
                                    reply = b'250 OK\r\n'
                                stream.write(reply); stream.flush()
                except (OSError, ssl.SSLError):
                    pass
        threading.Thread(target=run, daemon=True).start()
        return sock.getsockname()[1]
    tls_port, plain_port, stall_port = listener('tls'), listener('plain'), listener('stall')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
    def configure(scheme='smtps', smtp_port=tls_port, state='alerts'):
        values = {'WEBHOOK_TOKEN': 'test-token', 'APP_TIMEZONE': 'Europe/Rome', 'STORE_TO_FOLDER': 'true',
                  'STORAGE_DIRECTORY': 'public/forbidden', 'FORWARD_TO_MAIL': 'false', 'SAVE_TO_TODOIST': 'false',
                  'LOG_ROTATION': 'none', 'FAILURE_EMAIL_ENABLED': 'true', 'FAILURE_EMAIL_FROM': 'alerts@example.com',
                  'FAILURE_EMAIL_TO': 'one@example.com, two@example.com', 'FAILURE_EMAIL_STATE_DIRECTORY': state,
                  'FAILURE_EMAIL_SMTP_SCHEME': scheme, 'FAILURE_EMAIL_SMTP_HOST': 'localhost',
                  'FAILURE_EMAIL_SMTP_PORT': str(smtp_port), 'FAILURE_EMAIL_SMTP_USERNAME': 'alert-user',
                  'FAILURE_EMAIL_SMTP_PASSWORD': 'alert-secret', 'FAILURE_EMAIL_TIMEOUT_SECONDS': '1'}
        (root / '.env').write_text(''.join(f"{key}='{value}'\n" for key, value in values.items()))
    def request(token='test-token', timestamp='1791536400000'):
        result = subprocess.run(['curl', '-sS', '--max-time', '8', '-w', '\n%{http_code}', f'http://127.0.0.1:{port}/webhook.php',
                                 '-H', f'Authorization: Bearer {token}', '-F', f'recordedAt={timestamp}',
                                 '-F', 'transcription=PRIVATE NOTE'], capture_output=True, text=True, check=True)
        body, status = result.stdout.rsplit('\n', 1)
        if int(status) >= 400:
            assert body == ''
        else:
            assert json.loads(body)['ok']
        return int(status)
    with (root / 'server.log').open('w') as output:
        server = subprocess.Popen([PHP, '-d', f'openssl.cafile={certificate}', '-S', f'127.0.0.1:{port}', '-t', str(root / 'public')],
                                  stdout=output, stderr=output)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1): break
                except OSError: time.sleep(.02)
            configure()
            assert request(token='wrong') == 401 and request(timestamp='invalid') == 400
            assert len(messages) == 0 and not (root / 'alerts').exists()
            assert request() == 500 and len(messages) == 1
            assert request() == 500 and len(messages) == 1, 'Persistent cooldown'
            message = messages[0]
            assert b'one@example.com' in message and b'two@example.com' in message
            assert b'PRIVATE NOTE' not in message and b'alert-secret' not in message
            configure(state='bad-certificate')
            with (root / '.env').open('a') as env:
                env.write("FAILURE_EMAIL_SMTP_HOST='127.0.0.1'\n")
            assert request() == 500 and len(messages) == 1, 'Reject certificate hostname mismatch'
            configure('smtp', plain_port, 'no-tls')
            assert request() == 500 and len(messages) == 1, 'Require STARTTLS'
            assert not any(command.startswith(b'AUTH') for mode, command in commands if mode == 'plain'), 'Never send credentials without TLS'
            state = json.loads((root / 'no-tls/cooldown.json').read_text())
            assert all(not entry['delivered'] for entry in state.values())
            configure('smtp', stall_port, 'stalled')
            before = time.monotonic()
            assert request() == 500
            assert time.monotonic() - before < 3, 'Bound stalled SMTP read'
            before = time.monotonic()
            assert request() == 500 and time.monotonic() - before < .5, 'Failed-send cooldown avoids another SMTP timeout'
            logs = (root / 'logs/webhook.log').read_text()
            assert 'notification_sent' in logs and 'notification_suppressed' in logs and 'notification_failed' in logs
            assert 'alert-secret' not in logs and 'PRIVATE NOTE' not in logs
            # All combinations of note mail / alerts use independent settings.
            for note_enabled, alerts_enabled in [(False, False), (True, False), (True, True)]:
                configure(state=f'combination-{note_enabled}-{alerts_enabled}')
                with (root / '.env').open('a') as env:
                    env.write(f"STORE_TO_FOLDER=false\nFORWARD_TO_MAIL={str(note_enabled).lower()}\nFAILURE_EMAIL_ENABLED={str(alerts_enabled).lower()}\n")
                    if note_enabled:
                        env.write(f"SMTP_SCHEME=smtps\nSMTP_HOST=localhost\nSMTP_PORT={tls_port}\nSMTP_USERNAME=note-user\nSMTP_PASSWORD=note-secret\nMAIL_FROM=notes@example.com\nMAIL_TO=notes-recipient@example.com\nMAIL_SUBJECT_PREFIX=Note-test\n")
                before_messages = len(messages)
                assert request() == 200
                assert len(messages) == before_messages + int(note_enabled), 'Successful note never alerts'
                if note_enabled:
                    with (root / '.env').open('a') as env:
                        env.write(f'SMTP_PORT={plain_port}\n')
                    before_messages = len(messages)
                    assert request() == 502
                    assert len(messages) == before_messages + int(alerts_enabled), 'Broken note SMTP uses separate alert SMTP'
            configure(state='diagnostic-state')
            command = [PHP, '-d', f'openssl.cafile={certificate}', str(root / 'bin/test-alert-email.php')]
            before_messages = len(messages)
            result = subprocess.run(command + ['--check'], capture_output=True, text=True, check=True)
            assert 'No email sent' in result.stdout and len(messages) == before_messages
            assert not (root / 'diagnostic-state').exists(), 'Diagnostic check does not create cooldown state'
            subprocess.run(command + ['--send'], capture_output=True, text=True, check=True)
            assert len(messages) == before_messages + 1 and b'diagnostic' in messages[-1]
            assert not (root / 'diagnostic-state').exists(), 'Explicit diagnostic does not consume failure cooldown'
            before_messages = len(messages)
            assert subprocess.run(command + ['--bad'], capture_output=True).returncode == 2
            assert len(messages) == before_messages
            configure('smtp', plain_port, 'diagnostic-no-tls')
            result = subprocess.run(command + ['--send'], capture_output=True, text=True)
            assert result.returncode == 1 and 'alert-secret' not in result.stdout + result.stderr
            # Independent PHP processes contend on the same suppression store.
            worker = root / 'bin/concurrent.php'
            worker.write_text('''<?php
require dirname(__DIR__) . '/vendor/autoload.php';
$root = dirname(__DIR__);
$store = new Pebble\\Notification\\CooldownStore($root . '/concurrent-state');
echo $store->deliver(['signature'], time(), 300, static function () use ($root): bool {
    file_put_contents($root . '/ready', '1');
    usleep(400000);
    file_put_contents($root . '/deliveries', "delivered\\n", FILE_APPEND | LOCK_EX);
    return true;
});
''')
            with subprocess.Popen([PHP, str(worker)], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True) as first:
                for _ in range(100):
                    if (root / 'ready').exists(): break
                    time.sleep(.01)
                assert (root / 'ready').exists()
                second = subprocess.run([PHP, str(worker)], check=True, capture_output=True, text=True)
                assert second.stdout == 'suppressed'
                output, error = first.communicate(timeout=3)
                assert first.returncode == 0 and output == 'sent', error
            third = subprocess.run([PHP, str(worker)], check=True, capture_output=True, text=True)
            assert third.stdout == 'suppressed' and (root / 'deliveries').read_text().splitlines() == ['delivered']
            print('Notification HTTP checks passed: verified SMTPS, recipients, persistent suppression, rejection exclusion, enforced STARTTLS, stalled SMTP timeout, unchanged status, note/alert switch matrix, explicit CLI diagnostic, concurrent PHP processes, file-only outcomes.')
        finally:
            server.terminate(); server.wait(timeout=5)
            stop.set()
            for sock in listeners: sock.close()
