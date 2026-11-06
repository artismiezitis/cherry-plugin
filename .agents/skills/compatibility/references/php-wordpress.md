# PHP and WordPress compatibility

## Supported contract

The plugin declares PHP >=8.0 and WordPress >=6.0. The verified matrix is maintained in the [dated audit](audits/2026-11-06.md); the declaration is not a claim that every intervening WordPress version has been executed. Frontend layout and Bootstrap interactions continue to belong to the Cherry-compatible theme. Do not silently disable the WordPress block editor or replace a site's theme to preserve a legacy plugin workflow.

## Bootstrap and rendering

`cherry_plugin_settings()` runs at `plugins_loaded` to define legacy constants, reading plugin metadata without translating it. Translation loading and component includes run on `init`. Shared functions and shortcodes load in front, admin and REST requests; admin-specific callbacks remain in `admin/admin.php`. This allows server-side editor previews to find shortcodes.

Maintain the 13 registered widget class names and their existing IDs. Rendering must tolerate absent instance values and posts without thumbnails. Unchecked HTML checkboxes are omitted from submitted forms; saves must preserve off/empty behavior without passing null into PHP string functions. New block-editor legacy-widget previews have no sidebar widget ID, so the visibility filter must leave those preview instances available.

`cherry_widget_condition_matches()` evaluates saved visibility rules with an explicit allowlist and WordPress conditional functions. Never evaluate stored rule text as PHP. Preserve the old comma-prefixed storage format. The old string-producing `cherry_condition()` remains for compatibility with external callers but is no longer used to execute widget rules. Existing author-rule semantics are retained (`is_author` AND `is_single`); redesigning that rule is outside this compatibility release. The extended visibility controls target the classic Widgets interface; this release does not add a block inspector equivalent.

## Import/export and images

Keep the existing options, widget JSON structure, WXR format and AJAX import stages. The stages reuse `cherry_plugin_import_session()`; never change a session's name while it is active. JSON imports initialize all optional rule groups before merging and write responsive rules to the existing `cherry_widget_responsive` option. Parser classes are prefixed `Cherry_WXR_Parser` so another importer can own the unprefixed WordPress importer class names. SimpleXML uses `LIBXML_NONET`; XML preparation rejects doctypes/missing namespace/malformed data and restores libxml error state. Expat uses object callables, declared properties and arrays rather than automatic false-to-array conversions. The regex fallback accepts multiline channel records from current WordPress exports. Menu metadata uses explicit variable-variable braces to preserve the intended indexing on PHP 8.

Export JavaScript uses core `wp-ajax-response` instead of a copied parser with removed jQuery methods. The legacy updater accepts only serialized arrays, handles offline/malformed responses and never advertises an older version as an update. Remote help metadata has local fallback links.

`aq_resize()` handles a false `image_resize_dimensions()` result before indexing it. Its temporary upscale filter is always removed with `finally`, including early failures.

## Test environment and limits

Use [README test instructions](../../../../README.md). `tests/run.py` downloads the requested WordPress release, starts uniquely named Docker resources and mounts only this repository into its disposable installation. It seeds Twenty Twenty-One, a media item, post/page, category/tag, comment, menu and widget settings. It then lints and executes front/admin tests on each requested PHP branch. With `--browser`, HTTP checks export a ZIP, remove only fixture content, import it through all AJAX stages and verify restored records; Chromium checks admin and public rendering.

Test credentials in the fixture scripts belong only to the temporary localhost site. Do not reuse them in a real deployment. Never run reset/import fixtures against user data. Browser screenshots are retained in the printed temporary output directory; they are evidence, not runtime assets.

Unresolved boundaries are recorded in the [audit](audits/2026-11-06.md): provider APIs/credentials, actual Cherry child-theme rendering and unspecified third-party combinations are not covered. This compatibility change is not a security certification or support promise for unreleased PHP versions.
