"""Exercise early bootstrap failures against disposable projects, without external calls."""
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

with tempfile.TemporaryDirectory(prefix='pebble-bootstrap-') as temporary:
    root = Path(temporary)
    for name in ('normal', 'missing-vendor'):
        project = root / name
        (project / 'public').mkdir(parents=True)
        shutil.copy(ROOT / 'public/webhook.php', project / 'public/webhook.php')
        (project / 'src').symlink_to(ROOT / 'src', target_is_directory=True)
        if name == 'normal':
            (project / 'vendor').symlink_to(ROOT / 'vendor', target_is_directory=True)
    project = root / 'normal'
    base = "WEBHOOK_TOKEN=test-token\nAPP_TIMEZONE=UTC\nSTORE_TO_FOLDER=false\nFORWARD_TO_MAIL=false\nSAVE_TO_TODOIST=false\n"
    def configure(extra='', debug=False):
        (project / '.env').write_text(base + f'DEBUG_MODE={str(debug).lower()}\n' + extra)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    environment = os.environ.copy()
    environment.pop('DEBUG_MODE', None)
    with (root / 'server.log').open('w') as log:
        process = subprocess.Popen([PHP, '-S', f'127.0.0.1:{port}', '-t', str(root)], stdout=log, stderr=log, env=environment)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=0.1):
                        break
                except OSError:
                    time.sleep(0.02)
            else:
                raise RuntimeError('PHP test server did not start')
            def request(name='normal', post=True):
                arguments = ['curl', '-sS', '--max-time', '10', '-w', '\n%{http_code}',
                             f'http://127.0.0.1:{port}/{name}/public/webhook.php']
                if post:
                    arguments += ['-H', 'Authorization: Bearer test-token', '-F', 'transcription=Test']
                result = subprocess.run(arguments, check=True, capture_output=True, text=True)
                body, status = result.stdout.rsplit('\n', 1)
                return body, int(status)

            configure("LOG_DIRECTORY=private/logs\nLOG_FILENAME=errors.log\n")
            assert request(post=False) == ('', 405)
            custom_log = project / 'private/logs/errors.log'
            assert 'Use POST.' in custom_log.read_text()
            configure("LOG_DIRECTORY=public/logs\n")
            assert request() == ('', 500)
            assert request(post=False) == ('', 405)
            assert not (project / 'public/logs').exists()
            configure("LOG_DIRECTORY=public/logs\n", debug=True)
            body, status = request()
            assert status == 500 and 'outside public/' in json.loads(body)['error']
            assert not (project / 'logs').exists()
            configure("LOG_FILENAME=../secret-value\n", debug=True)
            body, status = request()
            assert status == 500 and 'LOG_FILENAME' in json.loads(body)['error'] and 'secret-value' not in body
            configure("LOG_DIRECTORY=private/logs\nLOG_FILENAME=errors.log\n", debug=True)
            before = custom_log.read_bytes()
            assert request(post=False)[1] == 405
            assert custom_log.read_bytes() == before
            (project / '.env').write_text("WEBHOOK_TOKEN='unterminated-secret-value\n")
            assert request() == ('', 500)
            assert request(post=False) == ('', 405)
            (project / '.env').unlink()
            assert request() == ('', 500)
            assert request('missing-vendor') == ('', 500)
            assert not (root / 'missing-vendor/logs').exists()
            assert not (project / 'logs').exists()
            server_log = (root / 'server.log').read_text()
            assert 'Pebble webhook: HTTP 500:' in server_log and 'unterminated-secret-value' not in server_log
            print('Bootstrap HTTP checks passed: custom private logs, unsafe settings, debug suppression, malformed/missing .env, and missing Composer dependencies.')
        finally:
            process.terminate()
            process.wait(timeout=10)
