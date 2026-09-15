<?php declare(strict_types = 1);

require_once __DIR__.'/stubs.php';

use Modules\SnmpWalk\Includes\CHostContext;
use Modules\SnmpWalk\Includes\COid;
use Modules\SnmpWalk\Includes\CTableAnalyzer;
use Modules\SnmpWalk\Includes\CTypeMapper;
use Modules\SnmpWalk\Includes\CVarbind;
use Modules\SnmpWalk\Includes\CWalkDiff;
use Modules\SnmpWalk\Includes\CWalkParser;

$passed = 0;
$failed = 0;

function check(string $label, $actual, $expected): void {
	global $passed, $failed;

	if ($actual === $expected) {
		$passed++;
		return;
	}

	$failed++;
	echo "FAIL  $label\n";
	echo "      expected: ".var_export($expected, true)."\n";
	echo "      actual:   ".var_export($actual, true)."\n";
}

// ------------------------------------------------------------------ COid

check('numeric OID accepted', COid::isNumeric('1.3.6.1.2.1.1.1.0'), true);
check('leading dot accepted', COid::isNumeric('.1.3.6.1'), true);
check('shell metacharacter rejected', COid::isNumeric('1.3.6; rm -rf /'), false);
check('backtick rejected', COid::isNumeric('1.3.6.`id`'), false);
check('newline rejected', COid::isNumeric("1.3.6\n1.2"), false);
check('empty rejected', COid::isNumeric(''), false);
check('symbolic rejected as numeric', COid::isNumeric('IF-MIB::ifDescr'), false);
check('symbolic accepted', COid::isSymbolic('IF-MIB::ifDescr.1'), true);
check('symbolic with semicolon rejected', COid::isSymbolic('ifDescr;id'), false);

// The bug every naive string-prefix implementation has.
check('sibling is not a child', COid::isChildOf('1.3.6.1.2.1.11', '1.3.6.1.2.1.1'), false);
check('real child is a child', COid::isChildOf('1.3.6.1.2.1.1.1.0', '1.3.6.1.2.1.1'), true);
check('self is a child', COid::isChildOf('1.3.6.1.2.1.1', '1.3.6.1.2.1.1'), true);

// The bug every naive string-sort implementation has.
$oids = ['1.3.6.1.2.1.2.2.1.1.10', '1.3.6.1.2.1.2.2.1.1.2', '1.3.6.1.2.1.2.2.1.1.1'];
usort($oids, [COid::class, 'compare']);
check('numeric ordering, not lexical', $oids,
	['1.3.6.1.2.1.2.2.1.1.1', '1.3.6.1.2.1.2.2.1.1.2', '1.3.6.1.2.1.2.2.1.1.10']);

check('parent', COid::parent('1.3.6.1.2.1.2.2.1.2.7'), '1.3.6.1.2.1.2.2.1.2');
check('suffix', COid::suffix('1.3.6.1.2.1.2.2.1.2.7'), '7');
check('multi-component suffix', COid::suffix('1.3.6.1.2.1.4.22.1.2.2.10.0.0.1', 5), '2.10.0.0.1');

// ------------------------------------------------------------------ parser

$text = <<<'WALK'
.1.3.6.1.2.1.1.1.0 = STRING: Cisco IOS Software, C3750E Software
.1.3.6.1.2.1.1.3.0 = Timeticks: (1234567800) 142 days, 21:21:18.00
.1.3.6.1.2.1.1.5.0 = STRING: "cin-core-01"
.1.3.6.1.2.1.2.2.1.6.1 = Hex-STRING: 00 1A 2B 3C 4D 5E
.1.3.6.1.2.1.2.2.1.10.1 = Counter32: 3894720184
.1.3.6.1.2.1.31.1.1.1.6.1 = Counter64: 18446744073709551615
.1.3.6.1.2.1.4.1.0 = INTEGER: 1
.1.3.6.1.2.1.99.1.0 = No Such Object available on this agent at this OID
WALK;

$parsed = CWalkParser::parse($text);
check('varbind count', count($parsed['varbinds']), 7);
check('non-value skipped', $parsed['skipped'], 1);
check('timeticks reduced to raw ticks', $parsed['varbinds'][1]->value, '1234567800');
check('quoted string unquoted', $parsed['varbinds'][2]->value, 'cin-core-01');
check('hex string preserved', $parsed['varbinds'][3]->value, '00 1A 2B 3C 4D 5E');
check('counter64 not truncated', $parsed['varbinds'][5]->value, '18446744073709551615');
check('type captured', $parsed['varbinds'][4]->type, 'Counter32');

// Multi-line Hex-STRING continuation, as net-snmp emits for long values.
$multiline = ".1.3.6.1.2.1.1.1.0 = Hex-STRING: 00 11 22 33 44 55 66 77 88 99 AA BB CC DD EE FF\n"
	."00 11 22 33\n";
$parsed = CWalkParser::parse($multiline);
check('continuation not treated as a varbind', count($parsed['varbinds']), 1);

$multiline_indented = ".1.3.6.1.2.1.1.1.0 = Hex-STRING: 00 11 22 33 44 55 66 77 88 99 AA BB CC DD EE FF\n"
	."   00 11 22 33\n";
$parsed = CWalkParser::parse($multiline_indented);
check('indented continuation appended', count($parsed['varbinds']), 1);
check('continuation joined',
	str_ends_with($parsed['varbinds'][0]->value, 'FF 00 11 22 33'), true);

$symbolic = CWalkParser::parse("IF-MIB::ifDescr.1 = STRING: GigabitEthernet0/1\n");
check('symbolic OID kept as name', $symbolic['varbinds'][0]->name, 'IF-MIB::ifDescr.1');
check('symbolic OID leaves numeric empty', $symbolic['varbinds'][0]->oid, '');

// ------------------------------------------------------------------ round trip

$vb = new CVarbind('1.3.6.1.2.1.1.5.0', 'STRING', 'cin-core-01');
$vb->name = 'sysName.0';
$vb->mib = 'SNMPv2-MIB';
check('numeric render', trim(CWalkParser::toNumericText([$vb])),
	'.1.3.6.1.2.1.1.5.0 = STRING: cin-core-01');
check('translated render', trim(CWalkParser::toTranslatedText([$vb])),
	'SNMPv2-MIB::sysName.0 = STRING: cin-core-01');

// A rendered walk must parse back to the same values, or the download is not a
// substitute for the real thing.
$reparsed = CWalkParser::parse(CWalkParser::toNumericText([$vb]));
check('round trip preserves oid', $reparsed['varbinds'][0]->oid, '1.3.6.1.2.1.1.5.0');
check('round trip preserves value', $reparsed['varbinds'][0]->value, 'cin-core-01');

// ------------------------------------------------------------------ diff

$before = [
	new CVarbind('1.3.6.1.2.1.1.1.0', 'STRING', 'IOS 15.2(4)E7'),
	new CVarbind('1.3.6.1.2.1.1.3.0', 'Timeticks', '100'),
	new CVarbind('1.3.6.1.2.1.2.2.1.2.1', 'STRING', 'Gi0/1'),
	new CVarbind('1.3.6.1.2.1.2.2.1.2.48', 'STRING', 'Gi0/48'),
	new CVarbind('1.3.6.1.4.1.9.9.13.1.3.1.3.1', 'INTEGER', '1')
];

$after = [
	new CVarbind('1.3.6.1.2.1.1.1.0', 'STRING', 'IOS 15.2(4)E10'),
	new CVarbind('1.3.6.1.2.1.1.3.0', 'Timeticks', '900'),
	new CVarbind('1.3.6.1.2.1.2.2.1.2.1', 'STRING', 'Gi0/1'),
	new CVarbind('1.3.6.1.2.1.2.2.1.2.49', 'STRING', 'Gi0/49')
];

$diff = CWalkDiff::compare($before, $after);
check('firmware string change detected', $diff['summary']['changed'], 1);
check('uptime change ignored as volatile', $diff['unchanged'], 2);
check('removed OID found', $diff['summary']['removed'], 2);
check('added OID found', $diff['summary']['added'], 1);
check('the vendor OID that vanished is named', $diff['removed'][1]['oid'],
	'1.3.6.1.4.1.9.9.13.1.3.1.3.1');

$diff = CWalkDiff::compare($before, $after, false);
check('uptime change reported when asked', $diff['summary']['changed'], 2);

$retyped = CWalkDiff::compare(
	[new CVarbind('1.3.6.1.2.1.2.2.1.10.1', 'Counter32', '5')],
	[new CVarbind('1.3.6.1.2.1.2.2.1.10.1', 'Counter64', '5')]
);
check('type change reported separately', $retyped['summary']['retyped'], 1);

// ------------------------------------------------------------------ type mapping

$counter = CTypeMapper::suggest(new CVarbind('1.3.6.1.2.1.31.1.1.1.6.1', 'Counter64', '99'));
check('counter is unsigned', $counter['value_type'], ITEM_VALUE_TYPE_UINT64);
check('counter gets change per second',
	$counter['preprocessing'][0]['type'], ZBX_PREPROC_DELTA_SPEED);
check('change per second is preprocessing type 10',
	$counter['preprocessing'][0]['type'], 10);

$ticks = CTypeMapper::suggest(new CVarbind('1.3.6.1.2.1.1.3.0', 'Timeticks', '1234567800'));
check('timeticks becomes float', $ticks['value_type'], ITEM_VALUE_TYPE_FLOAT);
check('timeticks gets 0.01 multiplier', $ticks['preprocessing'][0]['params'], '0.01');
check('timeticks units', $ticks['units'], 'uptime');
check('multiplier is preprocessing type 1', $ticks['preprocessing'][0]['type'], 1);

$enum = CTypeMapper::suggest(
	new CVarbind('1.3.6.1.2.1.2.2.1.8.1', 'INTEGER', '1'),
	['enum' => ['1' => 'up', '2' => 'down', '3' => 'testing']]
);
check('enumeration becomes a value map', $enum['valuemap'], ['1' => 'up', '2' => 'down', '3' => 'testing']);

$long = CTypeMapper::suggest(new CVarbind('1.3.6.1.2.1.1.1.0', 'STRING', str_repeat('x', 300)));
check('long string becomes text', $long['value_type'], ITEM_VALUE_TYPE_TEXT);
check('descriptive strings polled hourly', $long['delay'], '1h');

$short = CTypeMapper::suggest(new CVarbind('1.3.6.1.2.1.1.5.0', 'STRING', 'cin-core-01'));
check('short string is character', $short['value_type'], ITEM_VALUE_TYPE_STR);

$vb = new CVarbind('1.3.6.1.2.1.2.2.1.2.7', 'STRING', 'Gi0/7');
$vb->name = 'ifDescr.7';
check('item key strips the instance', CTypeMapper::key($vb, '7'), 'ifDescr[7]');
check('item key sanitises', CTypeMapper::key(new CVarbind('1.3.6.1.2.1.1.1.0', 'STRING', 'x')),
	'snmp.1_3_6_1_2_1_1_1_0');

// ------------------------------------------------------------------ table detection

// No MIBs loaded, so the structural fallback is what gets tested here.
$blind = new \Modules\SnmpWalk\Includes\CMibNull();

$walk = [];

// An ifTable fragment: three columns, four rows.
foreach ([1, 2, 3, 10] as $index) {
	$walk[] = new CVarbind('1.3.6.1.2.1.2.2.1.1.'.$index, 'INTEGER', (string) $index);
	$walk[] = new CVarbind('1.3.6.1.2.1.2.2.1.2.'.$index, 'STRING', 'Gi0/'.$index);
	$walk[] = new CVarbind('1.3.6.1.2.1.2.2.1.10.'.$index, 'Counter32', (string) ($index * 1000));
}

// Two scalars that must not be swept into the table.
$walk[] = new CVarbind('1.3.6.1.2.1.1.1.0', 'STRING', 'IOS');
$walk[] = new CVarbind('1.3.6.1.2.1.1.5.0', 'STRING', 'cin-core-01');

$analysis = (new CTableAnalyzer($blind))->analyze($walk);

check('one table detected', count($analysis['tables']), 1);
check('scalars kept out of it', count($analysis['scalars']), 2);
check('row count', $analysis['tables'][0]['row_count'], 4);
check('column count', count($analysis['tables'][0]['columns']), 3);
check('index 10 sorts after index 2', $analysis['tables'][0]['indexes'], ['1', '2', '3', '10']);
check('confidence flagged as low without MIBs', $analysis['tables'][0]['confidence'], 'low');
check('label column picked by distinctness',
	$analysis['tables'][0]['lld']['label_oid'], '1.3.6.1.2.1.2.2.1.2');
// Octet counters must come out in bits per second, or their numbers are correct and
// incomparable with every stock network template sitting next to them.
$octets = new CVarbind('1.3.6.1.2.1.2.2.1.10.1', 'Counter32', '5');
$octets->name = 'ifInOctets.1';
$rated = CTypeMapper::suggest($octets);
check('octet counter units are bits', $rated['units'], 'bps');
check('octet counter is rated first', $rated['preprocessing'][0]['type'], 10);
check('octet counter then multiplied by 8', $rated['preprocessing'][1]['params'], '8');

$plain = new CVarbind('1.3.6.1.2.1.2.2.1.11.1', 'Counter32', '5');
$plain->name = 'ifInUcastPkts.1';
$counted = CTypeMapper::suggest($plain);
check('non-octet counter is not multiplied', count($counted['preprocessing']), 1);
check('non-octet counter has no units', $counted['units'], '');

$detail = ['mib' => 'IF-MIB', 'name' => 'ifInOctets'];
$tags = CTypeMapper::tags($octets, $detail, '{#IFDESCR}');
check('tags name the MIB', in_array(['tag' => 'mib', 'value' => 'IF-MIB'], $tags, true), true);
check('tags name the object', in_array(['tag' => 'object', 'value' => 'ifInOctets'], $tags, true), true);
check('prototype tags carry the row macro',
	in_array(['tag' => 'index', 'value' => '{#IFDESCR}'], $tags, true), true);

check('counter offered as a metric prototype',
	in_array('1.3.6.1.2.1.2.2.1.10',
		array_column($analysis['tables'][0]['lld']['metric_columns'], 'oid'), true), true);

// A single-instance non-zero OID must not be promoted to a table.
$analysis = (new CTableAnalyzer($blind))->analyze([
	new CVarbind('1.3.6.1.4.1.9.2.1.58.1', 'INTEGER', '4')
]);
check('lone instance is not a table', count($analysis['tables']), 0);

// --------------------------------------------------- monitored OID extraction

$coverage = new \Modules\SnmpWalk\Includes\CCoverage($blind);

check('plain OID', $coverage->extract('1.3.6.1.2.1.1.3.0'), ['1.3.6.1.2.1.1.3.0']);
check('leading dot stripped', $coverage->extract('.1.3.6.1.2.1.1.3.0'), ['1.3.6.1.2.1.1.3.0']);
check('get[] unwrapped', $coverage->extract('get[1.3.6.1.2.1.1.1.0]'), ['1.3.6.1.2.1.1.1.0']);
check('walk[] unwrapped', $coverage->extract('walk[1.3.6.1.2.1.2.2]'), ['1.3.6.1.2.1.2.2']);
check('walk[] with several OIDs',
	$coverage->extract('walk[1.3.6.1.2.1.2.2,1.3.6.1.2.1.31.1.1]'),
	['1.3.6.1.2.1.2.2', '1.3.6.1.2.1.31.1.1']);
check('discovery[] drops the macro, keeps the column',
	$coverage->extract('discovery[{#IFDESCR},1.3.6.1.2.1.2.2.1.2]'),
	['1.3.6.1.2.1.2.2.1.2']);
check('prototype OID drops the LLD macro',
	$coverage->extract('1.3.6.1.2.1.2.2.1.10.{#SNMPINDEX}'),
	['1.3.6.1.2.1.2.2.1.10']);
check('symbolic OID with no MIB loaded yields nothing',
	$coverage->extract('IF-MIB::ifHCInOctets.1'), []);
check('empty field yields nothing', $coverage->extract(''), []);

// ------------------------------------------------------ template export

use Modules\SnmpWalk\Includes\CTemplateExport;
use Modules\SnmpWalk\Includes\CYaml;

check('uuid is 32 hex characters', (bool) preg_match('/^[0-9a-f]{32}$/', CYaml::uuid()), true);

check('plain scalar stays unquoted', trim(CYaml::dump(['key' => 'SNMP_AGENT'])), 'key: SNMP_AGENT');
check('value with a space is quoted', trim(CYaml::dump(['key' => 'Bits received'])),
	"key: 'Bits received'");
check('numeric-looking string is quoted', trim(CYaml::dump(['key' => '8'])), "key: '8'");
check('reserved word is quoted', trim(CYaml::dump(['key' => 'no'])), "key: 'no'");
check('apostrophe is doubled', trim(CYaml::dump(['key' => "it's"])), "key: 'it''s'");
check('empty string is quoted', trim(CYaml::dump(['key' => ''])), "key: ''");

$document = CTemplateExport::build('Walked Device', 'Templates/Network devices',
	[[
		'name' => 'Bits received',
		'key_' => 'ifInOctets[7]',
		'snmp_oid' => 'get[1.3.6.1.2.1.2.2.1.10.7]',
		'delay' => '1m',
		'value_type' => ITEM_VALUE_TYPE_UINT64,
		'units' => 'bps',
		'description' => "MIB: IF-MIB\nThe total number of octets received.",
		'status' => ITEM_STATUS_ACTIVE,
		'preprocessing' => [
			['type' => ZBX_PREPROC_DELTA_SPEED, 'params' => ''],
			['type' => ZBX_PREPROC_MULTIPLIER, 'params' => '8']
		],
		'tags' => [['tag' => 'mib', 'value' => 'IF-MIB']]
	]]
);

$yaml = CTemplateExport::toYaml($document);

check('export declares the version', str_contains($yaml, "version: '7.4'"), true);
check('item type is the export token', str_contains($yaml, 'type: SNMP_AGENT'), true);
check('value type is the export token', str_contains($yaml, 'value_type: UNSIGNED'), true);
check('rate step carries no parameters',
	str_contains($yaml, "- type: CHANGE_PER_SECOND\n            - type: MULTIPLIER"), true);
check('multiplier carries its parameter', str_contains($yaml, "- '8'"), true);
check('description is a literal block', str_contains($yaml, 'description: |'), true);
check('enabled status is omitted', str_contains($yaml, 'status:'), false);

$disabled = CTemplateExport::toYaml(CTemplateExport::build('T', 'G', [[
	'name' => 'x', 'key_' => 'x', 'snmp_oid' => 'get[1.3]', 'delay' => '1m',
	'value_type' => ITEM_VALUE_TYPE_UINT64, 'status' => ITEM_STATUS_DISABLED
]]));
check('disabled status is explicit', str_contains($disabled, 'status: DISABLED'), true);

$lld = CTemplateExport::toYaml(CTemplateExport::build('T', 'G', [],
	['name' => 'ifTable discovery', 'key_' => 'ifTable.discovery',
		'snmp_oid' => 'discovery[{#IFDESCR},1.3.6.1.2.1.2.2.1.2]', 'delay' => '1h'],
	[['name' => '{#IFDESCR}: ifInOctets', 'key_' => 'ifInOctets[{#SNMPINDEX}]',
		'snmp_oid' => 'get[1.3.6.1.2.1.2.2.1.10.{#SNMPINDEX}]', 'delay' => '1m',
		'value_type' => ITEM_VALUE_TYPE_UINT64]],
	['IF-MIB::ifOperStatus' => ['1' => 'up', '2' => 'down']]
));
check('discovery rule is exported', str_contains($lld, 'discovery_rules:'), true);
check('prototypes nest under the rule', str_contains($lld, 'item_prototypes:'), true);
check('value maps are exported', str_contains($lld, "name: 'IF-MIB::ifOperStatus'"), true);
check('a colon forces quoting', str_contains($lld, 'name: IF-MIB::ifOperStatus'), false);
check('rule has no value type', str_contains($lld, 'value_type: UNSIGNED')
	&& !str_contains(substr($lld, strpos($lld, 'discovery_rules:'),
		strpos($lld, 'item_prototypes:') - strpos($lld, 'discovery_rules:')), 'value_type'), true);

// ------------------------------------------------------- CHostContext macros

/**
 * macroMap() is private because nothing outside the class has any business calling
 * it, but it is also where a Secret text macro turns into an empty community string,
 * which is worth a test of its own rather than one inferred from a walk that failed.
 */
function macro_map(array $global, array $inherited, array $host): array {
	API::$global_macros = $global;

	$method = new ReflectionMethod(CHostContext::class, 'macroMap');
	$method->setAccessible(true);

	return $method->invoke(null, ['inheritedMacros' => $inherited, 'macros' => $host]);
}

$text = ['macro' => '{$SNMP_COMMUNITY}', 'value' => 'public', 'type' => ZBX_MACRO_TYPE_TEXT];
// usermacro.get omits value entirely for Secret text. That omission is the bug.
$secret = ['macro' => '{$SNMP_COMMUNITY}', 'type' => ZBX_MACRO_TYPE_SECRET];
$vault = ['macro' => '{$SNMP_COMMUNITY}', 'value' => 'secret/zabbix:community',
	'type' => ZBX_MACRO_TYPE_VAULT];

check('text macro is readable', macro_map([], [], [$text]), ['{$SNMP_COMMUNITY}' => 'public']);
check('secret text macro is not readable', macro_map([], [], [$secret]), []);
check('vault macro is not readable', macro_map([], [], [$vault]), []);
check('global text macro is readable', macro_map([$text], [], []), ['{$SNMP_COMMUNITY}' => 'public']);
check('host secret overrides inherited text', macro_map([], [$text], [$secret]), []);
check('host secret overrides global text', macro_map([$text], [], [$secret]), []);
check('host text overrides inherited text',
	macro_map([], [$text], [['macro' => '{$SNMP_COMMUNITY}', 'value' => 'private',
		'type' => ZBX_MACRO_TYPE_TEXT]]),
	['{$SNMP_COMMUNITY}' => 'private']
);

API::$global_macros = [];

// -------------------------------------------- CHostContext unresolved credentials

function context(array $details): CHostContext {
	$context = new CHostContext();
	$context->details = $details;

	// Typed properties with no default, so redacted() needs them set even though
	// nothing under test reads them.
	$context->hostid = '10084';
	$context->host = 'sw-core-01';
	$context->name = 'sw-core-01';
	$context->interfaceid = '1';
	$context->address = '10.0.0.1';
	$context->port = '161';

	return $context;
}

check('resolved community is not flagged',
	context(['version' => SNMP_V2C, 'community' => 'public'])->unresolvedCredentials(), []);
check('unresolved community is flagged',
	context(['version' => SNMP_V2C, 'community' => '{$SNMP_COMMUNITY}'])->unresolvedCredentials(),
	['{$SNMP_COMMUNITY}']);
check('empty community is not a macro',
	context(['version' => SNMP_V2C, 'community' => ''])->hasUnresolvedCredentials(), false);
check('macro with context is flagged',
	context(['version' => SNMP_V2C, 'community' => '{$SNMP_COMMUNITY:"eth0"}'])->hasUnresolvedCredentials(),
	true);
check('v3 passphrases are flagged at authPriv',
	context(['version' => SNMP_V3, 'securitylevel' => ITEM_SNMPV3_SECURITYLEVEL_AUTHPRIV,
		'securityname' => 'zabbix', 'authpassphrase' => '{$SNMP_AUTHPASS}',
		'privpassphrase' => '{$SNMP_PRIVPASS}'])->unresolvedCredentials(),
	['{$SNMP_AUTHPASS}', '{$SNMP_PRIVPASS}']);
check('v3 passphrases are ignored at noAuthNoPriv',
	context(['version' => SNMP_V3, 'securitylevel' => ITEM_SNMPV3_SECURITYLEVEL_NOAUTHNOPRIV,
		'securityname' => 'zabbix', 'authpassphrase' => '{$SNMP_AUTHPASS}',
		'privpassphrase' => '{$SNMP_PRIVPASS}'])->hasUnresolvedCredentials(),
	false);
check('v3 priv passphrase is ignored at authNoPriv',
	context(['version' => SNMP_V3, 'securitylevel' => ITEM_SNMPV3_SECURITYLEVEL_AUTHNOPRIV,
		'securityname' => 'zabbix', 'authpassphrase' => 'authpass',
		'privpassphrase' => '{$SNMP_PRIVPASS}'])->hasUnresolvedCredentials(),
	false);
check('community is not checked on a v3 interface',
	context(['version' => SNMP_V3, 'securitylevel' => ITEM_SNMPV3_SECURITYLEVEL_NOAUTHNOPRIV,
		'securityname' => 'zabbix', 'community' => '{$SNMP_COMMUNITY}'])->hasUnresolvedCredentials(),
	false);

// -------------------------------------------------- CHostContext credential override

function overridden(array $override, array $details = ['version' => SNMP_V2C, 'community' => '{$SNMP_COMMUNITY}', 'bulk' => SNMP_BULK_ENABLED]): CHostContext {
	$host = context($details);
	$host->applyOverride($override);

	return $host;
}

function refused(callable $body): string {
	try {
		$body();
	}
	catch (\RuntimeException $e) {
		return 'refused';
	}

	return 'accepted';
}

$v2 = overridden(['version' => SNMP_V2C, 'community' => 'n0tpublic']);

check('override replaces the community', $v2->details['community'], 'n0tpublic');
check('override clears the unresolved macro', $v2->hasUnresolvedCredentials(), false);
check('override is flagged', $v2->overridden, true);
check('override keeps bulk settings', $v2->usesBulk(), true);
check('override is reported, not the value', $v2->redacted()['community'], '(supplied)');

// The point of rebuilding details rather than merging: a v2c override must not leave
// a v3 passphrase from the interface sitting underneath it.
$switched = overridden(['version' => SNMP_V2C, 'community' => 'public'],
	['version' => SNMP_V3, 'securitylevel' => ITEM_SNMPV3_SECURITYLEVEL_AUTHPRIV,
		'securityname' => 'zabbix', 'authpassphrase' => 'leftover', 'privpassphrase' => 'leftover']);
check('override drops stale v3 fields', array_key_exists('authpassphrase', $switched->details), false);

check('empty community is refused',
	refused(fn() => overridden(['version' => SNMP_V2C, 'community' => ''])), 'refused');
check('unknown version is refused',
	refused(fn() => overridden(['version' => 9, 'community' => 'public'])), 'refused');
check('v3 without a security name is refused',
	refused(fn() => overridden(['version' => SNMP_V3,
		'securitylevel' => ITEM_SNMPV3_SECURITYLEVEL_NOAUTHNOPRIV])), 'refused');
check('authPriv without a priv passphrase is refused',
	refused(fn() => overridden(['version' => SNMP_V3, 'securityname' => 'zabbix',
		'securitylevel' => ITEM_SNMPV3_SECURITYLEVEL_AUTHPRIV, 'authpassphrase' => 'a'])), 'refused');

$v3 = overridden(['version' => SNMP_V3, 'securityname' => 'zabbix',
	'securitylevel' => ITEM_SNMPV3_SECURITYLEVEL_NOAUTHNOPRIV]);
check('noAuthNoPriv needs no passphrases', $v3->hasUnresolvedCredentials(), false);
check('noAuthNoPriv blanks the passphrases', $v3->details['authpassphrase'], '');
check('out of range protocol falls back to the first',
	overridden(['version' => SNMP_V3, 'securityname' => 'zabbix',
		'securitylevel' => ITEM_SNMPV3_SECURITYLEVEL_NOAUTHNOPRIV,
		'authprotocol' => 99])->details['authprotocol'], 0);

check('a supplied community is scrubbed from errors',
	$v2->scrub('timeout with n0tpublic'), 'timeout with ******');

check('a real community is scrubbed from errors',
	context(['version' => SNMP_V2C, 'community' => 'n0tpublic'])->scrub('timeout with n0tpublic'),
	'timeout with ******');
check('an unresolved macro survives scrubbing',
	context(['version' => SNMP_V2C, 'community' => '{$SNMP_COMMUNITY}'])
		->scrub('cannot read {$SNMP_COMMUNITY}'),
	'cannot read {$SNMP_COMMUNITY}');

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
