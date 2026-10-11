"""Install the local example with real Composer in an isolated, offline project."""
import json
import os
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BINARY', 'php')
COMPOSER = os.environ.get('COMPOSER_BINARY', '/usr/local/bin/composer')
with tempfile.TemporaryDirectory(prefix='pebble-extension-') as temporary:
    project = Path(temporary)
    (project / 'public').mkdir()
    (project / 'config').mkdir()
    manifest = {'name': 'pebble-test/extension-host', 'require': {'php': '>=8.2', 'pebble-example/receipt-operation': '0.1.0'},
                'repositories': [{'type': 'path', 'url': str(ROOT / 'examples/receipt-operation'), 'options': {'symlink': False}},
                                 {'packagist.org': False}], 'config': {'platform': {'php': '8.2.0'}}}
    (project / 'composer.json').write_text(json.dumps(manifest))
    environment = os.environ.copy()
    environment.update(COMPOSER_HOME=str(project / 'composer-home'), COMPOSER_DISABLE_NETWORK='1')
    result = subprocess.run([PHP, COMPOSER, 'install', '--no-interaction', '--no-plugins', '--no-scripts', '--no-progress'],
                            cwd=project, env=environment, text=True, capture_output=True)
    if result.returncode:
        raise RuntimeError(result.stdout + result.stderr)
    assert (project / 'vendor/pebble-example/receipt-operation/src/ReceiptFactory.php').is_file()
    assert not (project / 'vendor/pebble-example/receipt-operation').is_symlink()
    subprocess.run([PHP, str(ROOT / 'tests/extensions.php'), str(project)], check=True)
    print('Composer extension installation passed: offline path library, copied package, PSR-4 autoload, no plugins/scripts or application dependency changes.')
