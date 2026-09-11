<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Actions;

use API;
use CWebUser;
use Modules\SnmpWalk\Includes\CWalkBuffer;
use Modules\SnmpWalk\Includes\CWalkService;

/**
 * Promote the current walk buffer to a stored snapshot against the host.
 *
 * A saved walk is the baseline every later diff compares against, so this is what
 * turns "we onboarded that switch in March" into something checkable.
 */
class SnapshotSave extends CWalkAction {

	protected function checkInput(): bool {
		return $this->validate([
			'hostid' => 'required|db hosts.hostid',
			'token' => 'required|string',
			'label' => 'string'
		]);
	}

	protected function doAction(): void {
		$this->guard(function (): void {
			$hostid = $this->getInput('hostid');

			if (!API::Host()->get(['output' => [], 'hostids' => $hostid, 'countOutput' => true])) {
				$this->fail(_('No permissions to referred host or it does not exist.'));

				return;
			}

			$buffer = new CWalkBuffer(CWalkService::dataDir(), (string) CWebUser::$data['userid'],
				$this->getInput('token')
			);

			$varbinds = $buffer->varbinds();

			if (!$varbinds) {
				$this->fail(_('There is nothing to save.'));

				return;
			}

			$meta = $buffer->meta();
			$meta['label'] = mb_substr(trim($this->getInput('label', '')), 0, 128);
			$meta['saved'] = time();
			$meta['user'] = CWebUser::$data['username'];

			$store = CWalkService::snapshots();
			$id = $store->save($hostid, $meta, $varbinds);
			$store->prune($hostid, (int) CWalkService::config('snapshot_retention_days', 365));

			$this->log('snapshot.save', ['host' => $hostid, 'id' => $id, 'count' => count($varbinds)]);

			$this->json([
				'id' => $id,
				'snapshots' => $store->listForHost($hostid)
			]);
		});
	}
}
