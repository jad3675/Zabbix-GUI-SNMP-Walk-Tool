<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Actions;

use CControllerResponseData;
use Modules\SnmpWalk\Includes\CWalkService;

/**
 * The MIB library page.
 *
 * Zabbix has no MIB management of its own: MIBs are files on whichever host runs
 * net-snmp, dropped there by hand. This gives the frontend a place to hold the vendor
 * MIBs that never ship in a distribution package, so translation works for everyone
 * using the console rather than for whoever last scp'd a file onto the server.
 */
class MibView extends CWalkAction {

	protected function checkInput(): bool {
		return true;
	}

	protected function doAction(): void {
		$mib = CWalkService::mib();

		$this->setResponse(new CControllerResponseData([
			'indexed' => $mib->isIndexed(),
			'built' => $mib->indexedAt(),
			'objects' => $mib->isIndexed() ? $mib->count() : 0,
			'upload_dir' => $mib->uploadDir(),
			'system_dirs' => (array) CWalkService::config('mib_dirs', []),
			'binary' => (string) CWalkService::config('snmptranslate', 'snmptranslate'),
			'uploaded' => $mib->uploadedMibs(),
			'writable' => is_dir($mib->uploadDir())
				? is_writable($mib->uploadDir())
				: is_writable(dirname($mib->uploadDir())) || !file_exists(dirname($mib->uploadDir()))
		]));
	}
}
