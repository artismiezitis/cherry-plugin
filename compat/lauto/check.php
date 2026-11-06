<?php
error_reporting(E_ALL);
set_error_handler(static function($severity,$message,$file,$line) { if (error_reporting() & $severity) throw new ErrorException($message,0,$severity,$file,$line); });
if (PHP_SAPI !== 'cli' || empty($argv[1])) exit("Usage: php compat/lauto/check.php /path/to/wordpress\n");
$root=rtrim($argv[1], '/\\').'/wp-content';
require $root.'/themes/CherryFramework/includes/lessc.inc.php';
$less=new lessc();
$css=$less->compile('@color: #123456; .sample { color: @color; .nested { width: 2px + 3px; background: darken(@color, 10%); } }');
if (strpos($css,'#123456')===false || strpos($css,'width: 5px')===false || strpos($css,'darken(')!==false) throw new RuntimeException('LESS compilation mismatch');
require $root.'/plugins/codestyling-localization/includes/idna_convert.class.php';
$idn=new idna_convert();
$domain="mÄja.lv";
$encoded=$idn->encode($domain);
if ($idn->decode($encoded)!==$domain) throw new RuntimeException('IDNA round trip failed');
require $root.'/plugins/codestyling-localization/includes/class.parser.php';
$parser=new csp_l10n_parser('/','sample');
$parsed=$parser->parseString("<?php __('Sample message','sample');",'plugin');
if (strpos(json_encode($parsed),'Sample message')===false) throw new RuntimeException('Translation scanner did not find message');
echo "PASS: LESS compilation, Unicode IDNA round trip and PHP translation scanning\n";
