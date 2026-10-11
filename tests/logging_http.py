"""Check logging policy and runtime recovery with disposable HTTP endpoints."""
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BINARY', 'php')
with tempfile.TemporaryDirectory(prefix='pebble-logging-http-') as temporary:
    root = Path(temporary)
    (root / 'public').mkdir()
    for name in ('src', 'config', 'vendor'):
        (root / name).symlink_to(ROOT / name, target_is_directory=True)
    shutil.copy(ROOT / 'public/webhook.php', root / 'public/webhook.php')
    (root / 'public/runtime.php').write_text('''<?php
require dirname(__DIR__) . '/vendor/autoload.php';
$bootstrap = new Pebble\\Bootstrap(dirname(__DIR__));
$errors = new Pebble\\Http\\ErrorResponder('', false, str_repeat('a', 32));
$values = $bootstrap->loadEnvironment();
$errors->setDebugMode(Pebble\\Config\\WebhookConfig::debugModeFromEnvironment($values));
$bootstrap->configureLogging($values, $errors, str_repeat('a', 32));
switch ($_GET['case'] ?? '') {
    case 'warning': trigger_error('PRIVATE NOTE private-token', E_USER_WARNING); echo 'ok'; break;
    case 'exception': throw new RuntimeException('PRIVATE NOTE private-token');
    case 'fatal': trigger_error('PRIVATE NOTE private-token', E_USER_ERROR); break;
}
''')
    def configure(extra=''):
        (root / '.env').write_text('WEBHOOK_TOKEN=private-token\nAPP_TIMEZONE=Europe/Rome\n'
                                  'STORE_TO_FOLDER=false\nFORWARD_TO_MAIL=false\nSAVE_TO_TODOIST=false\n'
                                  'DEBUG_MODE=false\n' + extra)
    def request(path='webhook.php', post=True):
        args = ['curl', '-sS', '--max-time', '10', '-w', '\n%{http_code}', f'http://127.0.0.1:{port}/{path}']
        if post:
            args += ['-H', 'Authorization: Bearer private-token', '-F', 'transcription=PRIVATE NOTE', '-F', 'recordedAt=1791536400000']
        result = subprocess.run(args, capture_output=True, text=True, check=True)
        body, status = result.stdout.rsplit('\n', 1)
        return body, int(status)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    with (root / 'server.log').open('w') as output:
        server = subprocess.Popen([PHP, '-S', f'127.0.0.1:{port}', '-t', str(root / 'public')], stdout=output, stderr=output)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1):
                        break
                except OSError:
                    time.sleep(.02)
            configure()
            result = request()
            assert result[1] == 200, (result, (root / 'server.log').read_text(), [p.read_text() for p in (root / 'logs').glob('*')])
            files = list((root / 'logs').glob('webhook-*.log'))
            assert len(files) == 1
            records = [json.loads(line) for line in files[0].read_text().splitlines()]
            assert [r['context']['event'] for r in records] == ['request_accepted', 'hub_completed']
            assert records[0]['context']['requestId'] == records[1]['context']['requestId']
            assert request(post=False) == ('', 405)
            records = [json.loads(line) for line in files[0].read_text().splitlines()]
            assert records[-2]['level_name'] == 'WARNING' and records[-2]['context']['httpStatus'] == 405
            before = files[0].read_bytes()
            configure('LOG_ENABLED=false\n')
            assert request()[1] == 200 and request(post=False) == ('', 405)
            configure('DEBUG_MODE=true\n')
            assert request()[1] == 200 and request(post=False)[1] == 405
            assert files[0].read_bytes() == before
            configure('DEBUG_MODE=true\nLOG_IN_DEBUG=true\n')
            body, status = request(post=False)
            assert status == 405 and json.loads(body)['error'] == 'Use POST.'
            assert len(files[0].read_bytes()) > len(before)
            configure('LOG_LEVEL=error\n')
            before = files[0].read_bytes()
            assert request()[1] == 200 and request(post=False)[1] == 405
            assert files[0].read_bytes() == before
            configure('STORE_TO_FOLDER=true\nSTORAGE_DIRECTORY=recordings\n')
            body, status = request()
            assert status == 200 and json.loads(body)['saved']
            records = [json.loads(line) for line in files[0].read_text().splitlines()]
            lifecycle = records[-4:]
            assert [r['context']['event'] for r in lifecycle] == ['request_accepted', 'operation_started', 'operation_completed', 'hub_completed']
            assert lifecycle[2]['context']['operationId'] == 'local_storage'
            assert lifecycle[2]['context']['durationMs'] >= 0
            assert len({r['context']['requestId'] for r in lifecycle}) == 1
            (root / 'blocked').write_text('blocked directory')
            configure('STORE_TO_FOLDER=true\nSTORAGE_DIRECTORY=recordings\nLOG_DIRECTORY=blocked/logs\n')
            body, status = request()
            assert status == 200 and json.loads(body)['saved'], 'Logging failure must preserve delivery'
            configure('STORE_TO_FOLDER=true\nSTORAGE_DIRECTORY=blocked/storage\n')
            assert request() == ('', 500)
            records = [json.loads(line) for line in files[0].read_text().splitlines()]
            failures = [r for r in records if r['context'].get('event') == 'operation_failed']
            assert len(failures) == 1 and failures[0]['level_name'] == 'ERROR'
            configure()
            assert request('runtime.php?case=warning', post=False) == ('ok', 200)
            assert request('runtime.php?case=exception', post=False) == ('', 500)
            assert request('runtime.php?case=fatal', post=False) == ('', 500)
            records = [json.loads(line) for line in files[0].read_text().splitlines()]
            assert [r['context']['event'] for r in records[-3:]] == ['php_error', 'uncaught_exception', 'fatal_error']
            configure('DEBUG_MODE=true\n')
            before = files[0].read_bytes()
            body, status = request('runtime.php?case=exception', post=False)
            assert status == 500 and json.loads(body)['error'] == 'Unhandled application error.'
            assert files[0].read_bytes() == before
            combined = files[0].read_text() + (root / 'server.log').read_text()
            assert 'PRIVATE NOTE' not in combined and 'private-token' not in combined, combined
            print('Logging HTTP checks passed: lifecycle/correlation, silent responses, debug opt-in, disabled/filtered sinks, delivery through handler failures, central failure once, warnings/exceptions/fatals.')
        finally:
            server.terminate()
            server.wait(timeout=5)
