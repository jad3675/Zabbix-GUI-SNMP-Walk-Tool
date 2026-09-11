<?php declare(strict_types = 1);

/**
 * Check that every Zabbix constant the module names actually exists.
 *
 * This exists because CTypeMapper referenced ZBX_PREPROC_CHANGE_PER_SECOND, which is
 * the label shown in the UI rather than the name of the constant (it is
 * ZBX_PREPROC_DELTA_SPEED). PHP resolves an unknown constant at runtime, so the
 * mistake was invisible until someone clicked the button that reached that line, and
 * the unit tests actively hid it: stubs.php defined the made-up name, so everything
 * passed.
 *
 * zabbix-constants.txt is the list of names defined in ui/include/defines.inc.php.
 * Regenerate it when targeting a new Zabbix release:
 *
 *   grep -oP "(?<=define\(')[A-Z][A-Z0-9_]*(?=')" defines.inc.php | sort -u
 */

$root = dirname(__DIR__);

$known = array_flip(array_filter(array_map('trim',
	(array) file($root.'/tests/zabbix-constants.txt')
)));

/**
 * Constants that come from PHP itself or from an extension, not from Zabbix.
 */
$external = [
	'SNMP_OID_OUTPUT_NUMERIC', 'SNMP_VALUE_OBJECT', 'SNMP_VALUE_LIBRARY',
	'SNMP_VALUE_PLAIN', 'JSON_THROW_ON_ERROR', 'UPLOAD_ERR_OK', 'UPLOAD_ERR_NO_FILE',
	'PHP_MAJOR_VERSION', 'PHP_MINOR_VERSION', 'PHP_INT_MAX', 'PHP_EOL', 'DIRECTORY_SEPARATOR'
];

foreach ($external as $name) {
	$known[$name] = true;
}

$prefixes = 'ZBX_|ITEM_|INTERFACE_|SNMP_|USER_TYPE_|CONDITION_|HOST_|TRIGGER_|SYSMAP_|AUDIT_';
$files = [];

foreach (['includes', 'actions', 'views', 'views/js'] as $dir) {
	foreach ((array) glob($root.'/'.$dir.'/*.php') as $file) {
		$files[] = $file;
	}
}

$missing = [];

foreach ($files as $file) {
	$source = (string) file_get_contents($file);

	// Walk the token stream rather than the text: a name inside a string, a comment,
	// a variable ($ZBX_SERVER) or a class constant (CSettingsHelper::ITEM_TEST_TIMEOUT)
	// is not a reference to a global constant and must not be reported as one.
	$tokens = token_get_all($source);
	$names = [];
	$previous = null;

	foreach ($tokens as $token) {
		if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
			continue;
		}

		if (is_array($token) && $token[0] === T_STRING
				&& preg_match('/^(?:'.$prefixes.')[A-Z0-9_]+$/', $token[1])
				&& $previous !== '::' && $previous !== T_DOUBLE_COLON
				&& $previous !== T_OBJECT_OPERATOR && $previous !== T_CONST
				&& $previous !== T_FUNCTION) {
			$names[] = $token[1];
		}

		$previous = is_array($token) ? $token[0] : $token;
	}

	foreach (array_unique($names) as $name) {
		if (!array_key_exists($name, $known)) {
			$missing[$name][] = basename($file);
		}
	}
}

if (!$missing) {
	$count = count($files);
	echo "constants: every Zabbix constant referenced across $count files exists\n";
	exit(0);
}

echo "constants: ".count($missing)." unknown\n";

foreach ($missing as $name => $where) {
	echo "  $name  (".implode(', ', array_unique($where)).")\n";
}

exit(1);
