"""Rendered browser regression checks on the disposable test installation."""
import argparse
import json
from pathlib import Path
from playwright.sync_api import sync_playwright

parser = argparse.ArgumentParser()
parser.add_argument('--url', default='http://127.0.0.1:8097')
parser.add_argument('--fixtures', required=True)
parser.add_argument('--output', required=True)
args = parser.parse_args()
fixture = json.load(open(args.fixtures, encoding='utf-8-sig'))
cookies = json.load(open(args.fixtures + '.cookies.json', encoding='utf-8'))
output = Path(args.output)
output.mkdir(parents=True, exist_ok=True)
with sync_playwright() as p:
    browser = p.chromium.launch()
    context = browser.new_context(viewport={'width': 1440, 'height': 1000}, accept_downloads=True)
    context.add_cookies(cookies)
    # External maps, social APIs and CDNs are outside this local regression test.
    context.route('**/*', lambda route: route.continue_() if route.request.url.startswith(args.url + '/') else route.abort())
    page = context.new_page()
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))
    for slug in ['plugin-main-page', 'import-page&import=true', 'export-page', 'maintenance-mode-page', 'settings-page']:
        response = page.goto(args.url + '/wp-admin/admin.php?page=' + slug, wait_until='networkidle', timeout=90000)
        assert response.status == 200, (slug, response.status)
        assert page.locator('.cherry-plugin').count() == 1, slug
        assert 'critical error' not in page.locator('body').inner_text().lower(), slug
        page.screenshot(path=str(output / (slug.split('&')[0] + '.png')), full_page=True)
        if slug == 'settings-page':
            page.locator('#cp_ss_google_apikey').fill('cherry-test-key')
            page.get_by_role('button', name='Save Changes', exact=True).click()
            page.wait_for_url('**/admin.php?page=settings-page**')
            assert page.locator('#cp_ss_google_apikey').input_value() == 'cherry-test-key', 'Settings did not persist'
        if slug == 'export-page':
            with page.expect_download(timeout=90000) as download:
                page.locator('#export-content').click()
            assert download.value.suggested_filename.endswith('.zip')
            assert download.value.failure() is None
    response = page.goto(args.url + '/wp-admin/widgets.php', wait_until='domcontentloaded')
    assert response.status == 200
    page.locator('.edit-widgets-block-editor').wait_for(state='visible')
    close = page.locator('.components-modal__frame').get_by_role('button', name='Close', exact=True)
    if close.count():
        close.click()
    page.get_by_role('button', name='Add block', exact=True).first.wait_for(state='visible')
    page.screenshot(path=str(output / 'widgets.png'), full_page=True)
    page.goto(args.url + '/?page_id=' + str(fixture['showcase']), wait_until='networkidle')
    assert 'Cherry compatibility' in page.locator('body').inner_text()
    assert page.locator('.table th').count() == 2
    assert page.locator('.progress-success .bar').count() == 1
    page.screenshot(path=str(output / 'showcase-desktop.png'), full_page=True)
    page.set_viewport_size({'width': 390, 'height': 844})
    page.reload(wait_until='networkidle')
    page.screenshot(path=str(output / 'showcase-mobile.png'), full_page=True)
    assert not errors, errors
    print('PASS: five admin pages, browser ZIP export, widgets, desktop/mobile showcase; no JavaScript exceptions')
    browser.close()
