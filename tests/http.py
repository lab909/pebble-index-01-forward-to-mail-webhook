"""Exercise the public endpoint with isolated configuration and a local TLS SMTP stub."""
import json
import pathlib
import socket
import ssl
import subprocess
import tempfile
import threading
import time

ROOT = pathlib.Path(__file__).resolve().parents[1]


def smtp_stub(listener, context, messages):
    while True:
        try:
            connection, _ = listener.accept()
        except OSError:
            return
        try:
            with context.wrap_socket(connection, server_side=True) as connection:
                stream = connection.makefile('rwb')
                stream.write(b'220 localhost test SMTP\r\n')
                stream.flush()
                while line := stream.readline():
                    command = line.upper()
                    if command.startswith(b'EHLO'):
                        reply = b'250-localhost\r\n250 SIZE 10000000\r\n'
                    elif command.startswith(b'DATA'):
                        stream.write(b'354 Send message\r\n')
                        stream.flush()
                        message = bytearray()
                        while (line := stream.readline()) not in (b'.\r\n', b''):
                            message.extend(line)
                        messages.append(bytes(message))
                        reply = b'250 Accepted\r\n'
                    elif command.startswith(b'QUIT'):
                        stream.write(b'221 Bye\r\n')
                        stream.flush()
                        break
                    else:
                        reply = b'250 OK\r\n'
                    stream.write(reply)
                    stream.flush()
        except (OSError, ssl.SSLError):
            continue


with tempfile.TemporaryDirectory(prefix='pebble-http-') as directory:
    project = pathlib.Path(directory)
    (project / 'public').mkdir()
    (project / 'public/webhook.php').write_text((ROOT / 'public/webhook.php').read_text())
    (project / 'src').symlink_to(ROOT / 'src', target_is_directory=True)
    (project / 'vendor').symlink_to(ROOT / 'vendor', target_is_directory=True)
    certificate, key = project / 'cert.pem', project / 'key.pem'
    subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
                    '-keyout', str(key), '-out', str(certificate), '-days', '1',
                    '-subj', '/CN=localhost', '-addext', 'subjectAltName=DNS:localhost'],
                   check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    listener = socket.socket()
    listener.bind(('127.0.0.1', 0))
    listener.listen()
    smtp_port = listener.getsockname()[1]
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(certificate, key)
    messages = []
    thread = threading.Thread(target=smtp_stub, args=(listener, context, messages), daemon=True)
    thread.start()
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    with open(project / 'server.log', 'w') as log:
        server = subprocess.Popen(['php', '-d', f'openssl.cafile={certificate}', '-S',
                                   f'127.0.0.1:{port}', '-t', str(project / 'public')],
                                  stdout=log, stderr=log)
        try:
            for attempt in range(50):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1):
                        break
                except OSError:
                    time.sleep(.05)
            url = f'http://127.0.0.1:{port}/webhook.php'
            sample = project / 'sample.m4a'
            sample.write_bytes(b'test audio upload')

            def configure(store, mail, smtp=smtp_port):
                values = {'WEBHOOK_TOKEN': 'local-test-token', 'APP_TIMEZONE': 'UTC',
                          'STORE_TO_FOLDER': str(store).lower(), 'FORWARD_TO_MAIL': str(mail).lower()}
                if store:
                    values['STORAGE_DIRECTORY'] = 'recordings'
                if mail:
                    values.update(SMTP_SCHEME='smtps', SMTP_HOST='localhost', SMTP_PORT=str(smtp),
                                  SMTP_USERNAME='test', SMTP_PASSWORD='test', MAIL_FROM='sender@example.com',
                                  MAIL_TO='recipient@example.com', MAIL_SUBJECT_PREFIX='Pebble test')
                (project / '.env').write_text(''.join(f"{key}='{value}'\n" for key, value in values.items()))

            def request(args):
                result = subprocess.run(['curl', '-sS', '--max-time', '10', '-w', '\n%{http_code}', url] + args,
                                        capture_output=True, text=True, check=True)
                body, status = result.stdout.rsplit('\n', 1)
                status = int(status)
                if status >= 400:
                    assert body == '', (status, body)
                    return None, status
                return json.loads(body), status

            def upload():
                return request(['-H', 'Authorization: Bearer local-test-token', '-F',
                                'recordedAt=1791536400000', '-F', 'transcription=HTTP test',
                                '-F', f'audio=@{sample};type=audio/mp4'])

            configure(False, False)
            assert request([])[1] == 405
            assert request(['-F', 'recordedAt=1791536400000'])[1] == 401
            assert request(['-H', 'Authorization: Bearer wrong-secret', '-F', 'recordedAt=1'])[1] == 401
            assert request(['-H', 'Authorization: Bearer local-test-token', '-d', 'recordedAt=1'])[1] == 415
            assert request(['-H', 'Authorization: Bearer local-test-token', '-F', 'recordedAt=invalid'])[1] == 400
            assert not (project / 'recordings').exists()

            for store, mail in [(False, False), (False, True), (True, False), (True, True)]:
                configure(store, mail)
                before = len(messages)
                body, status = upload()
                assert status == 200, (body, status, (project / 'server.log').read_text())
                assert body['saved'] == store and body['emailSent'] == mail, body
                assert len(messages) == before + int(mail)
                if store:
                    folder = project / 'recordings' / body['folder']
                    assert (folder / 'audio.m4a').read_bytes() == sample.read_bytes()
                    info = json.loads((folder / 'info.txt').read_text())
                    assert info['postFields']['transcription'] == 'HTTP test'
                    assert 'local-test-token' not in (folder / 'info.txt').read_text()
                    assert (folder / 'mail-status.txt').exists() == mail
                else:
                    assert 'folder' not in body and not (project / 'recordings').exists()
                if mail:
                    assert b'filename=info.txt' in messages[-1] and b'filename=audio.m4a' in messages[-1]

            for store in [False, True]:
                configure(store, True, smtp=1)
                before = list((project / 'recordings').iterdir())
                body, status = upload()
                assert status == 502 and body is None
                if store:
                    folder = next(folder for folder in (project / 'recordings').iterdir() if folder not in before)
                    assert (folder / 'audio.m4a').exists()
                    assert 'failed' in (folder / 'mail-status.txt').read_text()
                else:
                    assert list((project / 'recordings').iterdir()) == before

            configure(False, False)
            with (project / '.env').open('a') as env:
                env.write("STORE_TO_FOLDER='typo'\n")
            assert upload()[1] == 500
            assert request(['-F', 'recordedAt=1'])[1] == 401
            (project / '.env').unlink()
            assert upload()[1] == 500
            private_log = (project / 'logs/webhook.log').read_text()
            for status in [400, 401, 405, 415, 500, 502]:
                assert f'HTTP {status}:' in private_log
            assert 'local-test-token' not in private_log and 'wrong-secret' not in private_log
            assert 'Use POST.' in private_log and 'Invalid authorization.' in private_log
            assert (project / 'logs/webhook.log').stat().st_mode & 0o777 == 0o600
            print('HTTP checks passed: silent errors and private logs; all four setting combinations, attachments, validation, and SMTP failures.')
        finally:
            server.terminate()
            server.wait(timeout=5)
            listener.close()
