"""Create an isolated Docker site and run the PHP 8.0-8.5 compatibility matrix.

Requires Python 3.9+, Docker and, with --browser, tests/requirements.txt + Chromium.
All site content is disposable. Existing containers and WordPress sites are untouched.
"""
import argparse
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import time
import urllib.request
import uuid
import zipfile

parser = argparse.ArgumentParser()
parser.add_argument('--wordpress', default='latest')
parser.add_argument('--php', nargs='+', default=['8.0', '8.1', '8.2', '8.3', '8.4', '8.5'])
parser.add_argument('--browser', action='store_true', help='Also run HTTP and Chromium checks')
parser.add_argument('--port', type=int, default=8097)
args = parser.parse_args()
repo = Path(__file__).resolve().parents[1]
work = Path(tempfile.mkdtemp(prefix='cherry-compat-'))
prefix = 'cherry-test-' + uuid.uuid4().hex[:8]
db, web = prefix + '-db', prefix + '-web'
url = f'http://127.0.0.1:{args.port}'
plugin = '/var/www/html/wp-content/plugins/cherry-plugin'

def run(*command, capture=False):
    result = subprocess.run(command, text=True, encoding='utf-8', capture_output=capture)
    if result.returncode and capture:
        print(result.stdout, result.stderr, flush=True)
    result.check_returncode()
    return result.stdout if capture else None

def php(script, *arguments, capture=False):
    return run('docker', 'exec', '-e', 'CHERRY_TEST_WP_ROOT=/var/www/html', web, 'php', plugin + '/tests/' + script, *arguments, capture=capture)

print(f'Test artifacts: {work}', flush=True)
release = 'https://wordpress.org/latest.zip' if args.wordpress == 'latest' else f'https://downloads.wordpress.org/release/wordpress-{args.wordpress}.zip'
urllib.request.urlretrieve(release, work / 'wordpress.zip')
with zipfile.ZipFile(work / 'wordpress.zip') as archive:
    archive.extractall(work)
urllib.request.urlretrieve('https://downloads.wordpress.org/theme/twentytwentyone.latest-stable.zip', work / 'theme.zip')
with zipfile.ZipFile(work / 'theme.zip') as archive:
    archive.extractall(work / 'wordpress/wp-content/themes')
config = f"""<?php
define('DB_NAME', 'cherry_test'); define('DB_USER', 'root');
define('DB_PASSWORD', 'cherry-test'); define('DB_HOST', '{db}');
define('DB_CHARSET', 'utf8mb4'); define('DB_COLLATE', '');
define('AUTH_KEY', '{uuid.uuid4().hex}'); define('SECURE_AUTH_KEY', '{uuid.uuid4().hex}');
define('LOGGED_IN_KEY', '{uuid.uuid4().hex}'); define('NONCE_KEY', '{uuid.uuid4().hex}');
define('WP_HOME', '{url}'); define('WP_SITEURL', '{url}');
define('WP_DEBUG', true); define('WP_DEBUG_LOG', true); define('WP_DEBUG_DISPLAY', false);
define('WP_ENVIRONMENT_TYPE', 'local'); define('DISABLE_WP_CRON', true);
$table_prefix = 'wp_'; if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/'); require ABSPATH . 'wp-settings.php';
"""
(work / 'wordpress/wp-config.php').write_text(config, encoding='utf-8')
mounts = ['--mount', f'type=bind,source={work / "wordpress"},target=/var/www/html', '--mount', f'type=bind,source={repo},target={plugin}']
created_network = False
try:
    run('docker', 'network', 'create', prefix)
    created_network = True
    run('docker', 'run', '-d', '--name', db, '--network', prefix, '--tmpfs', '/var/lib/mysql', '-e', 'MARIADB_ROOT_PASSWORD=cherry-test', '-e', 'MARIADB_DATABASE=cherry_test', 'mariadb:11.4')
    for _ in range(60):
        if subprocess.run(['docker', 'exec', db, 'healthcheck.sh', '--connect', '--innodb_initialized'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0:
            break
        time.sleep(1)
    else:
        raise RuntimeError('Test database failed to start')
    run('docker', 'run', '-d', '--name', web, '--network', prefix, '-p', f'127.0.0.1:{args.port}:80', *mounts, 'wordpress:php8.5-apache')
    php('install.php')
    fixture = work / 'fixtures.json'
    fixture.write_text(php('fixtures.php', capture=True), encoding='utf-8')
    results = []
    for version in args.php:
        image = f'wordpress:php{version}-apache'
        # Lint all plugin PHP files, including code that individual fixtures do not execute.
        lint = run('docker', 'run', '--rm', '--entrypoint', 'php', *mounts, image, '-r', f'foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{plugin}")) as $f) {{ if($f->isFile() && $f->getExtension()==="php") {{ passthru("php -l ".escapeshellarg($f->getPathname()), $status); if($status) exit($status); }} }}', capture=True)
        (work / f'lint-{version}.txt').write_text(lint, encoding='utf-8')
        for mode in ['front', 'admin']:
            result = run('docker', 'run', '--rm', '--network', prefix, '--entrypoint', 'php', *mounts, '-e', 'CHERRY_TEST_WP_ROOT=/var/www/html', image, plugin + '/tests/integration.php', mode, capture=True)
            data = json.loads(result)
            results.append(data)
            print(f'PASS PHP {data["php"]} / WordPress {data["wordpress"]} / {mode}: {data["checks"]} checks', flush=True)
    (work / 'matrix.json').write_text(json.dumps(results, indent=2), encoding='utf-8')
    if args.browser:
        run(sys.executable, str(repo / 'tests/http_smoke.py'), '--url', url, '--fixtures', str(fixture), '--container', web)
        run(sys.executable, str(repo / 'tests/browser_smoke.py'), '--url', url, '--fixtures', str(fixture), '--output', str(work / 'screenshots'))
    log = work / 'wordpress/wp-content/debug.log'
    if log.exists() and log.read_text(encoding='utf-8').strip():
        raise RuntimeError('WordPress debug.log is not empty: ' + str(log))
    print(f'PASS: all requested checks. Artifacts: {work}', flush=True)
finally:
    for name in [web, db]:
        subprocess.run(['docker', 'rm', '-f', '-v', name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    if created_network:
        subprocess.run(['docker', 'network', 'rm', prefix], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
