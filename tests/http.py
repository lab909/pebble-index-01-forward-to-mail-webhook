"""Exercise the public endpoint with isolated configuration and a local TLS SMTP stub."""
import json
import os
import pathlib
import re
import socket
import ssl
import subprocess
import tempfile
import threading
import time
from datetime import datetime, timezone
from todoist_stub import TodoistStub, TOKEN as TODOIST_TOKEN

ROOT = pathlib.Path(__file__).resolve().parents[1]

# Public compatibility expectations: (storage, email, Todoist, response flags).
FEATURE_COMBINATIONS = [
    (False, False, False, {'saved': False, 'emailSent': False, 'todoistSaved': False}),
    (False, True, False, {'saved': False, 'emailSent': True, 'todoistSaved': False}),
    (True, False, False, {'saved': True, 'emailSent': False, 'todoistSaved': False}),
    (True, True, False, {'saved': True, 'emailSent': True, 'todoistSaved': False}),
    (False, False, True, {'saved': False, 'emailSent': False, 'todoistSaved': True}),
    (False, True, True, {'saved': False, 'emailSent': True, 'todoistSaved': True}),
    (True, False, True, {'saved': True, 'emailSent': False, 'todoistSaved': True}),
    (True, True, True, {'saved': True, 'emailSent': True, 'todoistSaved': True}),
]


def assert_success(body, flags):
    expected_keys = {'ok', 'saved', 'emailSent', 'todoistSaved', 'operations', 'requestId'}
    if flags['saved']:
        expected_keys.add('folder')
        assert re.fullmatch(r'\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}-\d{3}_[0-9a-f]{6}', body['folder']), body
    if flags['todoistSaved']:
        expected_keys.update(['todoistProjectId', 'todoistTaskId'])
    assert set(body) == expected_keys, body
    assert body['ok'] is True and all(body[name] is value for name, value in flags.items()), body
    expected_operations = {id for field, id in [('saved', 'local_storage'), ('emailSent', 'email'), ('todoistSaved', 'todoist')] if flags[field]}
    assert set(body['operations']) == expected_operations
    assert all(result['status'] == 'success' for result in body['operations'].values())


def assert_info(info, audio_size):
    assert set(info) == {'receivedAt', 'recordedAt', 'timezone', 'postFields', 'audio', 'headers'}
    assert info['timezone'] == 'UTC'
    assert re.fullmatch(r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}\+00:00', info['receivedAt'])
    assert datetime.fromisoformat(info['receivedAt']).utcoffset().total_seconds() == 0
    assert info['recordedAt'] == datetime.fromtimestamp(1791536400, timezone.utc).isoformat()
    assert info['postFields'] == {'recordedAt': '1791536400000', 'transcription': 'HTTP test',
                                  'client': 'ring', 'extra': {'nested': 'preserved'}}
    assert info['audio'] == {'savedAs': 'audio.m4a', 'originalFilename': 'sample.m4a',
                             'reportedContentType': 'audio/mp4', 'sizeBytes': audio_size}
    assert set(info['headers']) == {'Content-Type', 'X-Audio-Size', 'User-Agent'}
    assert info['headers']['Content-Type'].startswith('multipart/form-data; boundary=')
    assert info['headers']['X-Audio-Size'] == str(audio_size)
    assert info['headers']['User-Agent'] == 'Pebble baseline test'



def smtp_stub(listener, context, messages, reject_recipient):
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
                    elif command.startswith(b'RCPT TO:') and reject_recipient.is_set():
                        reply = b'550 5.7.1 Recipient rejected by diagnostic SMTP stub\r\n'
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
    todoist = TodoistStub()
    threading.Thread(target=todoist.server.serve_forever, daemon=True).start()
    # Inject local clients and a failing service logger; production has no endpoint override.
    endpoint = (ROOT / 'public/webhook.php').read_text().replace(
        '$bootstrap = new Bootstrap($projectDirectory);',
        'require $projectDirectory . "/hub-probe.php"; '
        '$bootstrap = new Bootstrap($projectDirectory, '
        'static fn (\\Pebble\\Config\\TodoistConfig $config): \\Pebble\\Services\\TodoistClient '
        f'=> new \\Pebble\\Services\\TodoistClient($config->apiToken, "{todoist.url}"), '
        'new class extends \\Psr\\Log\\AbstractLogger {'
        'public function log($level, string|\\Stringable $message, array $context = []): void {'
        'throw new \\RuntimeException("Simulated logger failure"); } });')
    # Instrument a disposable hub copy to assert actual PHP upload lifetime after execution.
    hub_source = (ROOT / 'src/WebhookHub.php').read_text()
    probe_target = '$services->outcomeRecorder->record($report, $config->debugMode);'
    assert probe_target in hub_source
    hub_source = hub_source.replace(probe_target,
        probe_target + ' if ($note?->audio !== null) { '
        '$note->audio->cleanup(); '
        'if ($note->audio->ownership !== \\Pebble\\Note\\AssetOwnership::PhpUpload '
        '|| !is_uploaded_file($note->audio->path)) { '
        'throw new \\RuntimeException("Shared PHP upload was lost."); } '
        '$path = $report->entries["local_storage"]->result->outputs["audioPath"] ?? null; '
        'if ($path !== null && file_get_contents($note->audio->path) !== file_get_contents($path)) { '
        'throw new \\RuntimeException("Shared PHP upload was modified."); } }')
    (project / 'hub-probe.php').write_text(hub_source)
    (project / 'public/webhook.php').write_text(endpoint)
    (project / 'src').symlink_to(ROOT / 'src', target_is_directory=True)
    (project / 'config').symlink_to(ROOT / 'config', target_is_directory=True)
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
    reject_recipient = threading.Event()
    thread = threading.Thread(target=smtp_stub, args=(listener, context, messages, reject_recipient), daemon=True)
    thread.start()
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    with open(project / 'server.log', 'w') as log:
        server = subprocess.Popen([os.environ.get('PHP_BINARY', 'php'), '-d', f'openssl.cafile={certificate}', '-S',
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

            def configure(store, mail, smtp=smtp_port, debug=False, save_todoist=False):
                values = {'WEBHOOK_TOKEN': 'local-test-token', 'APP_TIMEZONE': 'UTC',
                          'STORE_TO_FOLDER': str(store).lower(), 'FORWARD_TO_MAIL': str(mail).lower(),
                          'DEBUG_MODE': str(debug).lower(), 'SAVE_TO_TODOIST': str(save_todoist).lower(), 'LOG_ROTATION': 'none'}
                if save_todoist:
                    values.update(TODOIST_API_TOKEN=TODOIST_TOKEN, TODOIST_PARENT_PROJECT='parent-123')
                if store:
                    values['STORAGE_DIRECTORY'] = 'recordings'
                if mail:
                    values.update(SMTP_SCHEME='smtps', SMTP_HOST='localhost', SMTP_PORT=str(smtp),
                                  SMTP_USERNAME='test', SMTP_PASSWORD='test', MAIL_FROM='sender@example.com',
                                  MAIL_TO='recipient@example.com', MAIL_SUBJECT_PREFIX='Pebble test')
                (project / '.env').write_text(''.join(f"{key}='{value}'\n" for key, value in values.items()))

            request_ids = set()
            production_error_ids = []

            def request(args, debug=False):
                header_file = project / 'response-headers.txt'
                result = subprocess.run(['curl', '-sS', '--max-time', '10', '--dump-header', str(header_file), '-w', '\n%{http_code}', url, '-H', 'X-Request-Id: forged-id', '-H', 'X-Correlation-Id: forged-id'] + args,
                                        capture_output=True, text=True, check=True)
                body, status = result.stdout.rsplit('\n', 1)
                status = int(status)
                headers = header_file.read_text().lower()
                request_id = re.search(r'^x-request-id: ([0-9a-f]{32})$', headers, re.MULTILINE)
                assert request_id, headers
                request_id = request_id.group(1)
                assert request_id not in request_ids and request_id != 'forged-id'
                request_ids.add(request_id)
                if status == 405:
                    assert 'allow: post' in headers
                if status < 400 or debug:
                    assert 'content-type: application/json; charset=utf-8' in headers
                if status >= 400 and not debug:
                    assert body == '', (status, body)
                    production_error_ids.append(request_id)
                    return None, status
                parsed = json.loads(body)
                assert parsed['requestId'] == request_id
                assert str(project) not in body, 'Internal output paths must not be public'
                return parsed, status

            def upload(debug=False):
                return request(['-H', 'Authorization: Bearer local-test-token', '-F',
                                'recordedAt=1791536400000', '-F', 'transcription=HTTP test',
                                '-F', 'client=ring', '-F', 'extra[nested]=preserved',
                                '-H', f'X-Audio-Size: {sample.stat().st_size}',
                                '-H', 'User-Agent: Pebble baseline test',
                                '-H', 'X-Private-Test: must-not-be-saved',
                                '-F', f'audio=@{sample};type=audio/mp4'], debug=debug)

            configure(False, False)
            assert request([])[1] == 405
            assert request(['-F', 'recordedAt=1791536400000'])[1] == 401
            assert request(['-H', 'Authorization: Bearer wrong-secret', '-F', 'recordedAt=1'])[1] == 401
            assert request(['-H', 'Authorization: Bearer local-test-token', '-d', 'recordedAt=1'])[1] == 415
            assert request(['-H', 'Authorization: Bearer local-test-token', '-F', 'recordedAt=invalid'])[1] == 400
            assert not (project / 'recordings').exists()

            for store, mail, enabled, flags in FEATURE_COMBINATIONS[:4]:
                configure(store, mail)
                before = len(messages)
                body, status = upload()
                assert status == 200, (body, status, (project / 'server.log').read_text())
                assert_success(body, flags)
                assert len(messages) == before + int(mail)
                if store:
                    folder = project / 'recordings' / body['folder']
                    assert (folder / 'audio.m4a').read_bytes() == sample.read_bytes()
                    info = json.loads((folder / 'info.txt').read_text())
                    assert_info(info, sample.stat().st_size)
                    assert 'local-test-token' not in (folder / 'info.txt').read_text()
                    assert (folder / 'mail-status.txt').exists() == mail
                else:
                    assert 'folder' not in body and not (project / 'recordings').exists()
                if mail:
                    assert b'filename=info.txt' in messages[-1] and b'filename=audio.m4a' in messages[-1]

            # An invalid disabled destination must not be constructed or contacted.
            configure(False, False)
            with (project / '.env').open('a') as env:
                env.write("STORAGE_DIRECTORY='/invalid-storage'\nSMTP_SCHEME='invalid'\nSMTP_PORT='not-a-port'\n"
                          "SMTP_HOST='invalid host'\nSMTP_USERNAME=''\nSMTP_PASSWORD=''\n"
                          "MAIL_FROM='invalid address'\nMAIL_TO='invalid address'\n"
                          "TODOIST_API_TOKEN=''\nTODOIST_PARENT_PROJECT=''\n")
            before_messages, before_api = len(messages), len(todoist.requests)
            recordings_before = list((project / 'recordings').iterdir())
            body, status = upload()
            assert status == 200
            assert_success(body, FEATURE_COMBINATIONS[0][3])
            assert len(messages) == before_messages and len(todoist.requests) == before_api
            assert list((project / 'recordings').iterdir()) == recordings_before

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
            assert all(request_id in private_log + (project / 'server.log').read_text() for request_id in production_error_ids)
            assert 'forged-id' not in private_log
            records = [json.loads(line) for line in private_log.splitlines()]
            for status in [400, 401, 405, 415, 500, 502]:
                assert any(record['context'].get('httpStatus') == status for record in records)
            assert 'local-test-token' not in private_log and 'wrong-secret' not in private_log
            assert 'Use POST.' in private_log and 'Invalid authorization.' in private_log
            assert (project / 'logs/webhook.log').stat().st_mode & 0o777 == 0o600
            # Debug responses contain useful messages and never append to the private log.
            before_log = (project / 'logs/webhook.log').read_bytes()
            configure(False, False, debug=True)
            for args, status, message in [
                ([], 405, 'Use POST.'),
                (['-F', 'recordedAt=1'], 401, 'Invalid authorization.'),
                (['-H', 'Authorization: Bearer wrong-secret', '-F', 'recordedAt=1'], 401, 'Invalid authorization.'),
                (['-H', 'Authorization: Bearer local-test-token', '-d', 'recordedAt=1'], 415, 'multipart/form-data'),
                (['-H', 'Authorization: Bearer local-test-token', '-F', 'recordedAt=invalid'], 400, 'recordedAt'),
            ]:
                body, actual = request(args, debug=True)
                assert actual == status and message in body['error'], (body, actual)
                assert 'local-test-token' not in json.dumps(body) and 'wrong-secret' not in json.dumps(body)
            with (project / '.env').open('a') as env:
                env.write("STORE_TO_FOLDER='typo'\n")
            body, status = upload(debug=True)
            assert status == 500 and 'STORE_TO_FOLDER' in body['error']
            configure(True, False, debug=True)
            (project / 'blocked-storage').write_text('not a directory')
            with (project / '.env').open('a') as env:
                env.write("STORAGE_DIRECTORY='blocked-storage'\n")
            body, status = upload(debug=True)
            assert status == 500 and 'permissions' in body['error']
            for store in [False, True]:
                configure(store, True, smtp=1, debug=True)
                body, status = upload(debug=True)
                assert status == 502 and 'SMTP' in body['error'] and body['saved'] == store
                assert body['smtpDebug']['exceptions'][0]['message']
                assert 'class' in body['smtpDebug']['exceptions'][0]
                if store:
                    folder = project / 'recordings' / body['folder']
                    assert (folder / 'audio.m4a').exists()
                    assert not (folder / 'mail-status.txt').exists()
            configure(False, True, debug=True)
            reject_recipient.set()
            body, status = upload(debug=True)
            assert status == 502
            diagnostics = body['smtpDebug']['exceptions'][0]
            assert '550' in diagnostics['message'] and 'Recipient rejected' in diagnostics['message']
            assert 'RCPT TO:' in diagnostics['smtpTranscript']
            assert '550 5.7.1' in diagnostics['smtpTranscript']
            assert 'local-test-token' not in json.dumps(body)
            reject_recipient.clear()
            assert (project / 'logs/webhook.log').read_bytes() == before_log
            configure(False, False, debug=True)
            body, status = upload(debug=True)
            assert status == 200 and body['ok']
            configure(False, False)
            assert request([])[1] == 405
            assert (project / 'logs/webhook.log').read_bytes() != before_log
            assert not todoist.requests, 'Disabled Todoist must not make API requests'
            month = datetime.now(timezone.utc).strftime('%B %Y')
            # Same name under another parent must not be reused.
            todoist.projects.append({'id': 'wrong-parent', 'name': month, 'parent_id': 'other-parent'})
            todoist.projects.append({'id': 'archived-month', 'name': month, 'parent_id': 'parent-123', 'is_archived': True})
            configure(False, False, debug=True, save_todoist=True)
            recordings_before = list((project / 'recordings').iterdir())
            body, status = upload(debug=True)
            assert status == 200 and body['todoistSaved'] and not body['saved'] and not body['emailSent'], body
            monthly = todoist.projects[-1]
            assert monthly['name'] == month and monthly['parent_id'] == 'parent-123'
            assert body['todoistProjectId'] == monthly['id']
            task = todoist.tasks[-1]
            assert task['content'] == 'HTTP test' and task['project_id'] == monthly['id']
            assert all(not key.startswith('due') for key in task), task
            assert [upload['file_name'] for upload in todoist.uploads[-2:]] == ['audio.m4a', 'info.txt']
            assert todoist.uploads[-2]['data'] == sample.read_bytes()
            assert todoist.uploads[-2]['file_type'] == 'audio/mp4'
            info = json.loads(todoist.uploads[-1]['data'])
            assert_info(info, sample.stat().st_size)
            assert 'local-test-token' not in todoist.uploads[-1]['data'].decode()
            assert all(comment['task_id'] == task['id'] for comment in todoist.comments[-2:])
            assert 'folder' not in body and list((project / 'recordings').iterdir()) == recordings_before

            # Reuse the current month, including across paginated results and old recordings.
            project_count = len(todoist.projects)
            body, status = request(['-H', 'Authorization: Bearer local-test-token',
                                    '-F', 'recordedAt=0', '-F', 'transcription=Old note'], debug=True)
            assert status == 200 and body['todoistProjectId'] == monthly['id']
            assert len(todoist.projects) == project_count
            assert todoist.uploads[-1]['file_name'] == 'info.txt'
            assert any('cursor=1' in path for method, path in todoist.requests if method == 'GET')

            # The receipt month uses APP_TIMEZONE rather than the note's recordedAt value.
            fixture = project / 'month-boundary.php'
            fixture.write_text('''<?php
require __DIR__ . '/vendor/autoload.php';
$note = new Pebble\\Note\\Note('Timezone boundary test',
    ['transcription' => 'Timezone boundary test'], new DateTimeImmutable('2026-04-01T00:30:00+00:00'),
    new DateTimeImmutable('@0'), new DateTimeZone('America/Los_Angeles'), []);
$service = new Pebble\\Services\\TodoistService(new Pebble\\Services\\TodoistClient(
    'TOKEN', 'API_URL'), 'parent-123', new DateTimeZone('America/Los_Angeles'));
echo json_encode($service->save($note));
'''.replace('TOKEN', TODOIST_TOKEN).replace('API_URL', todoist.url))
            subprocess.run([os.environ.get('PHP_BINARY', 'php'), str(fixture)], check=True, capture_output=True)
            assert todoist.projects[-1]['name'] == 'March 2026'

            # Every storage/mail combination works with Todoist enabled.
            for store, mail, enabled, flags in FEATURE_COMBINATIONS[4:]:
                configure(store, mail, debug=True, save_todoist=enabled)
                recordings_before = list((project / 'recordings').iterdir())
                body, status = upload(debug=True)
                assert status == 200
                assert_success(body, flags)
                if store:
                    folder = project / 'recordings' / body['folder']
                    assert (folder / 'audio.m4a').read_bytes() == todoist.uploads[-2]['data']
                else:
                    assert list((project / 'recordings').iterdir()) == recordings_before

            # Validation and local storage failure must prevent all forwarding.
            configure(True, True, debug=True, save_todoist=True)
            before_messages, before_api = len(messages), len(todoist.requests)
            recordings_before = list((project / 'recordings').iterdir())
            for args, expected in [
                ([], 405),
                (['-F', 'recordedAt=1'], 401),
                (['-H', 'Authorization: Bearer local-test-token', '-F', 'recordedAt=invalid'], 400),
                (['-H', 'Authorization: Bearer local-test-token', '-F', 'recordedAt=1'], 400),
            ]:
                assert request(args, debug=True)[1] == expected
            for debug in [False, True]:
                configure(True, True, debug=debug, save_todoist=True)
                with (project / '.env').open('a') as env:
                    env.write("STORAGE_DIRECTORY='blocked-storage'\n")
                body, status = upload(debug=debug)
                assert status == 500
                if debug:
                    assert set(body) == {'error', 'operations', 'requestId'} and 'permissions' in body['error']
                    assert body['operations']['local_storage']['status'] == 'failure'
                    assert body['operations']['email']['status'] == body['operations']['todoist']['status'] == 'skipped'
            assert len(messages) == before_messages and len(todoist.requests) == before_api
            assert list((project / 'recordings').iterdir()) == recordings_before

            # A failing email does not prevent Todoist delivery.
            configure(False, True, smtp=1, debug=True, save_todoist=True)
            body, status = upload(debug=True)
            assert status == 502 and not body['emailSent'] and body['todoistSaved']

            # Missing transcription fails before side effects.
            configure(False, False, debug=True, save_todoist=True)
            requests_before = len(todoist.requests)
            body, status = request(['-H', 'Authorization: Bearer local-test-token', '-F', 'recordedAt=1'], debug=True)
            assert status == 400 and 'transcription' in body['error']
            assert len(todoist.requests) == requests_before
            assert body['operations']['todoist']['status'] == 'validation_failed'

            # Partial failures expose the created task and stage in debug mode, without the token.
            for failed_stage, expected_stage in [('audio.m4a', 'audio upload'), ('info.txt', 'info.txt upload'),
                                                 ('comments', 'audio comment creation'), ('info-comment', 'info.txt comment creation')]:
                configure(True, False, debug=True, save_todoist=True)
                todoist.fail = failed_stage
                before_log = (project / 'logs/webhook.log').read_bytes()
                tasks_before, comments_before = len(todoist.tasks), len(todoist.comments)
                body, status = upload(debug=True)
                assert status == 502 and body['saved'] and not body['todoistSaved']
                assert 'ok' not in body
                assert len(todoist.tasks) == tasks_before + 1
                assert body['todoistTaskId'] == todoist.tasks[-1]['id']
                assert body['todoistProjectId'] == monthly['id']
                completed = 1 if failed_stage in ['info.txt', 'info-comment'] else 0
                assert body['todoistDebug']['commentsCreated'] == completed
                assert len(todoist.comments) == comments_before + completed
                assert all(comment['task_id'] == body['todoistTaskId'] for comment in todoist.comments[comments_before:])
                assert body['todoistDebug']['stage'] == expected_stage, body
                operation = body['operations']['todoist']
                assert operation['status'] == 'partial' and operation['externalIds']['taskId'] == body['todoistTaskId']
                assert operation['progress']['commentsCreated'] == completed
                assert TODOIST_TOKEN not in json.dumps(body) and '[redacted]' in body['todoistDebug']['message']
                assert (project / 'logs/webhook.log').read_bytes() == before_log
                assert (project / 'recordings' / body['folder'] / 'audio.m4a').exists()

            # Rate-limit and project-access errors keep SMTP independent.
            configure(False, True, debug=True, save_todoist=True)
            todoist.fail = 'tasks'
            body, status = upload(debug=True)
            assert status == 502 and body['emailSent'] and not body['todoistSaved']
            assert '429' in body['todoistDebug']['message'] and 'todoistTaskId' not in body
            todoist.fail = 'projects'
            body, status = upload(debug=True)
            assert status == 502 and '403' in body['todoistDebug']['message']

            # Production mode logs sanitized failures and sends an empty response.
            configure(False, False, save_todoist=True)
            todoist.fail = 'info.txt'
            tasks_before = len(todoist.tasks)
            body, status = upload()
            assert status == 502 and body is None
            assert len(todoist.tasks) == tasks_before + 1
            private_log = (project / 'logs/webhook.log').read_text()
            assert 'Todoist' in private_log and TODOIST_TOKEN not in private_log
            assert todoist.tasks[-1]['id'] in private_log and 'info.txt upload' in private_log
            failures = [json.loads(line) for line in private_log.splitlines() if json.loads(line)['context'].get('event') == 'operation_failed' and json.loads(line)['context'].get('operationId') == 'todoist']
            assert failures[-1]['context']['providerStatus'] == 403
            assert failures[-1]['context']['externalIds']['taskId'] == todoist.tasks[-1]['id']
            todoist.fail = None

            # Disabled mode doesn't require Todoist credentials; enabled mode does.
            configure(False, False, debug=True)
            with (project / '.env').open('a') as env:
                env.write("SAVE_TO_TODOIST=true\n")
            body, status = upload(debug=True)
            assert status == 500 and 'TODOIST_API_TOKEN' in body['error']
            configure(False, False, debug=True)
            with (project / '.env').open('a') as env:
                env.write("SAVE_TO_TODOIST='typo'\n")
            assert upload(debug=True)[1] == 500
            print('HTTP checks passed: debug JSON errors without logging; silent errors and private logs; all eight destination combinations, disabled credentials, storage abort policy, metadata, attachments, validation, and SMTP failures; Todoist project reuse, tasks, uploads, and partial failures.')
        finally:
            server.terminate()
            server.wait(timeout=5)
            listener.close()
            todoist.server.shutdown()
            todoist.server.server_close()
