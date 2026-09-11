<?php declare(strict_types = 1);

/**
 * Loads the module's shared classes.
 *
 * Zabbix's module autoloader has changed the way it maps sub-namespaces onto
 * directories between releases, and getting that wrong produces a blank page rather
 * than a useful error. Requiring the files explicitly is dull and works everywhere.
 * Each entry is guarded, so if the autoloader does resolve the class first, nothing is
 * loaded twice.
 */

$classes = [
	'Modules\SnmpWalk\Includes\COid' => 'COid.php',
	'Modules\SnmpWalk\Includes\CVarbind' => 'CVarbind.php',
	'Modules\SnmpWalk\Includes\CMibLookup' => 'CMibLookup.php',
	'Modules\SnmpWalk\Includes\CMibIndex' => 'CMibIndex.php',
	'Modules\SnmpWalk\Includes\CMibNull' => 'CMibNull.php',
	'Modules\SnmpWalk\Includes\CWalkParser' => 'CWalkParser.php',
	'Modules\SnmpWalk\Includes\CHostContext' => 'CHostContext.php',
	'Modules\SnmpWalk\Includes\CEngine' => 'CEngine.php',
	'Modules\SnmpWalk\Includes\CEngineLocal' => 'CEngineLocal.php',
	'Modules\SnmpWalk\Includes\CEngineServer' => 'CEngineServer.php',
	'Modules\SnmpWalk\Includes\CEngineScript' => 'CEngineScript.php',
	'Modules\SnmpWalk\Includes\CTypeMapper' => 'CTypeMapper.php',
	'Modules\SnmpWalk\Includes\CTableAnalyzer' => 'CTableAnalyzer.php',
	'Modules\SnmpWalk\Includes\CSnapshotStore' => 'CSnapshotStore.php',
	'Modules\SnmpWalk\Includes\CWalkBuffer' => 'CWalkBuffer.php',
	'Modules\SnmpWalk\Includes\CWalkDiff' => 'CWalkDiff.php',
	'Modules\SnmpWalk\Includes\CCoverage' => 'CCoverage.php',
	'Modules\SnmpWalk\Includes\CYaml' => 'CYaml.php',
	'Modules\SnmpWalk\Includes\CTemplateExport' => 'CTemplateExport.php',
	'Modules\SnmpWalk\Includes\CWalkService' => 'CWalkService.php'
];

foreach ($classes as $class => $file) {
	if (!class_exists($class, false) && !interface_exists($class, false)) {
		require_once __DIR__.'/'.$file;
	}
}
