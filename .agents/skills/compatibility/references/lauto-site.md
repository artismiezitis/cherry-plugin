# Existing LAUTO site

The installation at `C:/xampp/htdocs/php8/wordpress_lauto` uses Cherry Plugin alongside the `CherryFramework` parent theme and `theme47828` child theme. A plugin cannot replace those themes. Preserve the existing theme selection, published content, menus, sidebar assignments, theme options and assets when applying this update.

## Authoritative compatibility files

[The Git patch](../../../../compat/lauto/php8.patch) and [manifest](../../../../compat/lauto/manifest.json) record nine modified files outside the plugin:

- CherryFramework's LESS compiler, cache integration, media-uploader object check and legacy admin event handlers.
- CodeStyling Localization's PHP 8 string offsets, parser constructor/properties, optional scan arguments and current WordPress JSON/event handling.
- Easy Logo Slider's shortcode initializer, which now uses the same generated ID as its container.

No independent updater or extra production storage was added. The site continues to use the same installed components. These patches are specific to the source hashes in the manifest, not a general upstream theme release. When applying the Git patch to matching originals, use `git -c core.autocrlf=false apply --check /path/to/php8.patch`, then the same command without `--check`. Validate the resulting SHA-256 hashes against `after_sha256`.

The child `style.less` is empty in the supplied site. Its existing `main-style.css` is the authoritative available design asset. The compile integration skips empty/missing input so opening Cherry Options cannot replace that CSS with an empty result. Moved LESS cache source paths are invalidated only for readable nonempty inputs. Obtaining the absent LESS source or enabling a complete style-rebuild workflow is separate work; do not claim it was recovered.

## Local configuration and delivery

The local site's private `wp-config.php` contains host-scoped overrides for `lauto.local` and `localhost/php8/wordpress_lauto`. Stored production `home`/`siteurl` values are unchanged. Local cron dispatch is disabled for those hosts. Do not ship this config or its secrets to a client as part of the plugin update.

Build artifacts with [package.py](../../../../compat/lauto/package.py). It verifies the nine installed site files against the manifest and creates a standard plugin ZIP plus a complete `wp-content` overlay ZIP. It copies only tracked plugin runtime files and the nine allowlisted compatibility files. It must never include the site's database, credentials, config, verification cookies or uploads. The [delivery instructions](../../../../compat/lauto/DELIVERY.txt) explain merging the overlay into an existing site rather than deleting/replacing its whole content directory.

Private backups and raw browser evidence are outside the repository under the path recorded in the [audit](audits/2026-11-06-lauto.md). The audit also records missing media that already affected the original local copy. Keep that distinction when reporting regressions: a missing local uploads archive is not a plugin change. Never run `tests/reset-fixtures.php` or the disposable import suite on this site.

## Verification

Run [check.php](../../../../compat/lauto/check.php) against the patched WordPress root. It treats PHP notices/deprecations as failures and exercises LESS output, Unicode IDNA round trips and gettext scanning without loading/mutating WordPress. The six-branch PHP component matrix and actual-site HTTP/browser evidence are recorded in the [dated audit](audits/2026-11-06-lauto.md). Preserve content/config snapshots and CSS hashes when comparing a new deployment. Revoke temporary verification login sessions after testing; do not change existing user credentials or submit real contact forms to prove rendering.
