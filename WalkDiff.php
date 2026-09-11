<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Actions;

use CWebUser;
use Modules\SnmpWalk\Includes\CWalkBuffer;
use Modules\SnmpWalk\Includes\CWalkDiff as CDiff;
use Modules\SnmpWalk\Includes\CWalkService;

/**
 * Compare two walks: two snapshots, or a snapshot against the walk currently on
 * screen. The pair does not have to be from the same host, so "why does this switch
 * discover interfaces and its twin does not" is one operation.
 */
class WalkDiff extends CWalkAction {

	protected function checkInput(): bool {
		return $this->validate([
			'before_hostid' => 'required|db hosts.hostid',
			'before_id' => 'required|string',
			'after_hostid' => 'db hosts.hostid',
			'after_id' => 'string',
			'after_token' => 'string',
			'ignore_volatile' => 'in 0,1'
		]);
	}

	protected function doAction(): void {
		$this->guard(function (): void {
			$store = CWalkService::snapshots();
			$before = $store->load($this->getInput('before_hostid'), $this->getInput('before_id'));

			if ($this->getInput('after_id', '') !== '') {
				$after = $store->load($this->getInput('after_hostid', ''), $this->getInput('after_id'));
			}
			else {
				$buffer = new CWalkBuffer(CWalkService::dataDir(), (string) CWebUser::$data['userid'],
					$this->getInput('after_token', '')
				);
				$after = ['meta' => $buffer->meta(), 'varbinds' => $buffer->varbinds()];
			}

			$diff = CDiff::compare($before['varbinds'], $after['varbinds'],
				(int) $this->getInput('ignore_volatile', 1) === 1
			);

			$diff['before_meta'] = $before['meta'];
			$diff['after_meta'] = $after['meta'];

			$this->json($diff);
		});
	}
}
