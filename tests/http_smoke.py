"""HTTP export/import round trip; run exclusively against the disposable test site."""
import argparse
import io
import html as html_module
import json
import re
import subprocess
import time
import zipfile
import xml.etree.ElementTree as ET
import requests

parser = argparse.ArgumentParser()
parser.add_argument('--url', default='http://127.0.0.1:8097')
parser.add_argument('--fixtures', required=True)
parser.add_argument('--container', default='cherry-compat-web')
args = parser.parse_args()
fixture = json.load(open(args.fixtures, encoding='utf-8-sig'))
session = requests.Session()
session.get(args.url + '/wp-login.php', timeout=30).raise_for_status()
result = session.post(args.url + '/wp-login.php', data={'log': 'cherryadmin', 'pwd': 'Cherry-Disposable-Test-2026!', 'wp-submit': 'Log In', 'testcookie': '1'}, timeout=60)
assert any('wordpress_logged_in' in cookie.name for cookie in session.cookies), 'Login failed'
html = session.get(args.url + '/wp-admin/admin.php?page=import-page&import=true', timeout=60).text
nonce = re.search(r'var import_ajax\s*=\s*(\{.*?\});', html)
assert nonce, 'Import nonce missing'
nonce = json.loads(nonce.group(1))['nonce']
result = session.post(args.url + '/wp-admin/admin-ajax.php', data={'action': 'export_content'}, timeout=90)
result.raise_for_status()
root = ET.fromstring(result.text)
download = root.find(".//response[@action='export_content_2']/response_data")
if download is None:
    download = root.findall('.//response_data')[1]
archive = session.get(download.text, timeout=60)
archive.raise_for_status()
with zipfile.ZipFile(io.BytesIO(archive.content)) as exported:
    assert {'sample_data.xml', 'widgets.json', 'cherry-fixture.png'} <= set(exported.namelist()), exported.namelist()
    assert ET.fromstring(exported.read('sample_data.xml')).tag == 'rss'
    assert len(json.loads(exported.read('widgets.json'))) == 3
print('PASS: HTTP export and authenticated ZIP download')
subprocess.run(['docker', 'exec', '-e', 'CHERRY_TEST_WP_ROOT=/var/www/html', args.container, 'php', '/var/www/html/wp-content/plugins/cherry-plugin/tests/reset-fixtures.php'], check=True)

# Every request uses the same cookie jar, including the import PHP session.
action = 'import_xml'
steps = []
while action != 'import_json':
    assert action not in steps and len(steps) < 20, f'Import loop: {steps}, {action}'
    steps.append(action)
    data = {'action': action, 'nonce': nonce}
    if action == 'import_xml':
        data['file'] = fixture['xml']
    response = session.post(args.url + '/wp-admin/admin-ajax.php', data=data, timeout=90)
    response.raise_for_status()
    action = response.text.strip()
    assert re.fullmatch(r'[a-z_]+', action), f'{steps[-1]} failed: {response.text[:1000]}'
response = session.post(args.url + '/wp-admin/admin-ajax.php', data={'action': 'import_json', 'file': fixture['json'], 'nonce': nonce}, timeout=60)
assert response.text.strip() == 'import_end', response.text
print(f'PASS: HTTP XML import ({len(steps)} stages) and widget JSON import')
subprocess.run(['docker', 'exec', '-e', 'CHERRY_TEST_WP_ROOT=/var/www/html', args.container, 'php', '/var/www/html/wp-content/plugins/cherry-plugin/tests/verify-fixtures.php'], check=True)
upload_url = html_module.unescape(re.search(r"action_url\s*=\s*'([^']+)'", html).group(1))
with zipfile.ZipFile(io.BytesIO(archive.content)) as exported:
    partial = json.loads(exported.read('widgets.json'))
partial[2] = {'widget_responsive': [{'my-recent-comments-2': 'visible-all-devices'}]}
session.post(upload_url, files={'file': ('cherry-partial-widgets.json', json.dumps(partial), 'application/json')}, timeout=30).raise_for_status()
response = session.post(args.url + '/wp-admin/admin-ajax.php', data={'action': 'import_json', 'file': 'cherry-partial-widgets.json', 'nonce': nonce}, timeout=30)
assert response.text.strip() == 'import_end', response.text
print('PASS: multipart upload and import with partially populated widget rules')

maintenance = {'action': 'mtc_save', 'data[mtc_mode_on]': '1', 'data[mtc_mode_description]': 'Cherry maintenance test', 'data[date_ms]': str(int(time.time()) + 3600)}
session.post(args.url + '/wp-admin/admin-ajax.php', data=maintenance, timeout=30).raise_for_status()
try:
    guest = requests.get(args.url + '/', timeout=30)
    assert 'id="under_construction_page"' in guest.text and 'Cherry maintenance test' in guest.text, 'Guest maintenance page missing'
    assert 'id="under_construction_page"' not in session.get(args.url + '/', timeout=30).text, 'Authenticated user blocked'
finally:
    session.post(args.url + '/wp-admin/admin-ajax.php', data={'action': 'mtc_save', 'data[mtc_mode_description]': ''}, timeout=30).raise_for_status()
assert 'id="under_construction_page"' not in requests.get(args.url + '/', timeout=30).text, 'Maintenance mode did not turn off'
print('PASS: maintenance save, guest restriction, signed-in access and disable')

# Save cookies for the browser regression test, without exposing them in logs.
with open(args.fixtures + '.cookies.json', 'w', encoding='utf-8') as stream:
    json.dump([{'name': c.name, 'value': c.value, 'domain': c.domain, 'path': c.path} for c in session.cookies], stream)
