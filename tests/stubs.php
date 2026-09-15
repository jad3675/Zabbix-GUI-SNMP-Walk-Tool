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

define('SNMP_V1', 1);
define('SNMP_V2C', 2);
define('SNMP_V3', 3);
define('SNMP_BULK_ENABLED', 1);
define('SNMP_BULK_DISABLED', 0);
define('INTERFACE_TYPE_SNMP', 2);
define('INTERFACE_USE_IP', 1);
define('INTERFACE_PRIMARY', 1);
define('ITEM_SNMPV3_SECURITYLEVEL_NOAUTHNOPRIV', 0);
define('ITEM_SNMPV3_SECURITYLEVEL_AUTHNOPRIV', 1);
define('ITEM_SNMPV3_SECURITYLEVEL_AUTHPRIV', 2);
define('ZBX_MACRO_TYPE_TEXT', 0);
define('ZBX_MACRO_TYPE_SECRET', 1);
define('ZBX_MACRO_TYPE_VAULT', 2);

/**
 * Just enough of the API facade for CHostContext's macro handling. Only
 * UserMacro()->get() is reachable from the parts under test; anything else throws
 * rather than quietly returning an empty array, so a test that wanders into the real
 * API surface fails loudly instead of passing for the wrong reason.
 */
if (!class_exists('API')) {
	class API {

		/** Global macros the stubbed usermacro.get returns. */
		public static array $global_macros = [];

		public static function UserMacro(): object {
			return new class {
				public function get(array $options): array {
					return API::$global_macros;
				}
			};
		}

		public static function __callStatic(string $name, array $arguments) {
			throw new \RuntimeException('unstubbed API call: '.$name);
		}
	}
}

require_once __DIR__.'/../includes/CHostContext.php';
