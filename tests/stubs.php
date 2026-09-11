<?php declare(strict_types = 1);

/**
 * Minimal stand-ins for the Zabbix frontend globals the pure-logic classes touch, so
 * they can be exercised outside a running frontend.
 */

if (!function_exists("_")) { function _(string $s): string { return $s; } }

if (!function_exists("_s")) { function _s(string $s, ...$args): string {
	foreach ($args as $i => $arg) {
		$s = str_replace('%'.($i + 1).'$s', (string) $arg, $s);
	}

	return $s;
} }

define('ITEM_VALUE_TYPE_FLOAT', 0);
define('ITEM_VALUE_TYPE_STR', 1);
define('ITEM_VALUE_TYPE_LOG', 2);
define('ITEM_VALUE_TYPE_UINT64', 3);
define('ITEM_VALUE_TYPE_TEXT', 4);
define('ZBX_PREPROC_MULTIPLIER', 1);
define('ZBX_PREPROC_DELTA_SPEED', 10);
define('ZBX_PREPROC_FAIL_DEFAULT', 0);

require_once __DIR__.'/../includes/COid.php';
require_once __DIR__.'/../includes/CVarbind.php';
require_once __DIR__.'/../includes/CWalkParser.php';
require_once __DIR__.'/../includes/CWalkDiff.php';
require_once __DIR__.'/../includes/CTypeMapper.php';
require_once __DIR__.'/../includes/CMibLookup.php';
require_once __DIR__.'/../includes/CMibIndex.php';
require_once __DIR__.'/../includes/CMibNull.php';
require_once __DIR__.'/../includes/CTableAnalyzer.php';

define('ITEM_TYPE_SNMP', 20);
define('ITEM_STATUS_ACTIVE', 0);
define('ITEM_STATUS_DISABLED', 1);
define('ITEM_STATE_NOTSUPPORTED', 1);

require_once __DIR__.'/../includes/CCoverage.php';

define('ZABBIX_EXPORT_VERSION', '7.4');

if (!function_exists('zbx_date2str')) {
	function zbx_date2str($format, $time = null): string { return 'a date'; }
}

define('DATE_TIME_FORMAT', 'Y-m-d H:i');

require_once __DIR__.'/../includes/CYaml.php';
require_once __DIR__.'/../includes/CTemplateExport.php';
