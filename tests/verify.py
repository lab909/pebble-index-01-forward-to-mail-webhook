"""Run isolated checks without loading real .env or calling production destinations."""
import os
from pathlib import Path
import shutil
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BINARY', 'php')
COMPOSER = os.environ.get('COMPOSER_BINARY', shutil.which('composer') or '/usr/local/bin/composer')
# Fixture .env values must win even when a CI/hosting shell exports real settings.
ISOLATED_ENVIRONMENT = {key: value for key, value in os.environ.items()
    if not key.startswith(('SMTP_', 'MAIL_', 'TODOIST_', 'FAILURE_EMAIL_', 'LOG_', 'EXAMPLE_RECEIPT_'))
    and key not in {'WEBHOOK_TOKEN', 'APP_TIMEZONE', 'DEBUG_MODE', 'STORE_TO_FOLDER',
                    'FORWARD_TO_MAIL', 'SAVE_TO_TODOIST', 'STORAGE_DIRECTORY'}}

def run(arguments, *, env=None):
    subprocess.run(arguments, cwd=ROOT, env=ISOLATED_ENVIRONMENT if env is None else env, check=True)

try:
    version = subprocess.check_output([PHP, '-r', 'echo PHP_VERSION;'], text=True, env=ISOLATED_ENVIRONMENT).strip()
    if tuple(int(part) for part in version.split('.')[:2]) < (8, 2):
        raise RuntimeError('PHP 8.2+ is required.')
    print(f'Verification interpreter: PHP {version}', flush=True)
    files = sorted(path for folder in ('src', 'public', 'config', 'bin', 'tests', 'examples')
                   for path in (ROOT / folder).rglob('*.php'))
    for path in files:
        result = subprocess.run([PHP, '-l', str(path)], text=True, capture_output=True, env=ISOLATED_ENVIRONMENT)
        if result.returncode:
            raise RuntimeError(result.stdout + result.stderr)
    print(f'Syntax checks passed for {len(files)} PHP files.', flush=True)
    run([PHP, COMPOSER, '--no-plugins', 'validate', '--no-check-publish'])
    run([PHP, COMPOSER, '--no-plugins', 'validate', '--working-dir', 'examples/receipt-operation', '--no-check-publish'])
    run([PHP, COMPOSER, '--no-plugins', 'check-platform-reqs'])
    for name in ('run', 'config', 'note', 'operations', 'destinations', 'hub', 'logging', 'notifications'):
        run([PHP, f'tests/{name}.php'])
    environment = ISOLATED_ENVIRONMENT.copy()
    environment.update(PHP_BINARY=PHP, COMPOSER_BINARY=COMPOSER)
    for name in ('http', 'bootstrap_http', 'logging_http', 'notifications_http', 'extensions'):
        run([sys.executable, f'tests/{name}.py'], env=environment)
    print('All isolated suites passed. No production deployment, account test, or inbox delivery is claimed.', flush=True)
except (OSError, RuntimeError, subprocess.CalledProcessError) as error:
    print(f'Verification failed: {error}', file=sys.stderr)
    sys.exit(1)
