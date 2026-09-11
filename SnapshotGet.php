<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Actions;

use Modules\SnmpWalk\Includes\CWalkService;

/**
 * Load a stored walk back into the console.
 */
class SnapshotGet extends CWalkAction {

	protected function checkInput(): bool {
		return $this->validate([
			'hostid' => 'required|db hosts.hostid',
			'id' => 'required|string'
		]);
	}

	protected function doAction(): void {
		$this->guard(function (): void {
			$snapshot = CWalkService::snapshots()->load($this->getInput('hostid'), $this->getInput('id'));

			$rows = [];

			foreach ($snapshot['varbinds'] as $vb) {
				$rows[] = [
					'oid' => $vb->oid,
					'name' => $vb->name,
					'mib' => $vb->mib,
					'type' => $vb->type,
					'value' => $vb->value
				];
			}

			$this->json([
				'meta' => $snapshot['meta'],
				'rows' => $rows,
				'total' => count($rows)
			]);
		});
	}
}
