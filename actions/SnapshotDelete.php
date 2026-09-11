<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Actions;

use API;
use Modules\SnmpWalk\Includes\CWalkService;

/**
 * Delete a stored walk. Requires write access to the host, not just read.
 */
class SnapshotDelete extends CWalkAction {

	protected function checkInput(): bool {
		return $this->validate([
			'hostid' => 'required|db hosts.hostid',
			'id' => 'required|string'
		]);
	}

	protected function doAction(): void {
		$this->guard(function (): void {
			$hostid = $this->getInput('hostid');

			if (!API::Host()->get(['output' => [], 'hostids' => $hostid, 'editable' => true, 'countOutput' => true])) {
				$this->fail(_('You do not have permission to change this host.'));

				return;
			}

			$store = CWalkService::snapshots();
			$store->delete($hostid, $this->getInput('id'));

			$this->log('snapshot.delete', ['host' => $hostid, 'id' => $this->getInput('id')]);

			$this->json(['snapshots' => $store->listForHost($hostid)]);
		});
	}
}
