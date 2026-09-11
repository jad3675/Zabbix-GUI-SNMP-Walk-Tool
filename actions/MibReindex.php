<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Actions;

use Modules\SnmpWalk\Includes\CWalkService;

/**
 * Rebuild the OID index from every configured MIB directory.
 *
 * One snmptranslate run over the whole tree, cached to disk. Run it after adding MIBs
 * on the frontend host by hand, or after a distribution package update.
 */
class MibReindex extends CWalkAction {

	protected function checkPermissions(): bool {
		return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function checkInput(): bool {
		return true;
	}

	protected function doAction(): void {
		$this->guard(function (): void {
			$mib = CWalkService::mib();
			$result = $mib->reindex();

			$this->log('mib.reindex', ['objects' => $result['objects']]);

			$this->json([
				'objects' => $result['objects'],
				'warnings' => $result['errors'] === '' ? null : $result['errors'],
				'built' => $mib->indexedAt()
			]);
		});
	}
}
