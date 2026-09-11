<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Actions;

use CWebUser;
use Modules\SnmpWalk\Includes\CTableAnalyzer;
use Modules\SnmpWalk\Includes\CWalkBuffer;
use Modules\SnmpWalk\Includes\CWalkService;

/**
 * Split a completed walk into scalars and conceptual tables, and propose what could be
 * monitored. Runs against the server-side buffer or a saved snapshot, so the browser
 * never posts the walk back.
 */
class WalkAnalyze extends CWalkAction {

	protected function checkInput(): bool {
		return $this->validate([
			'token' => 'string',
			'hostid' => 'db hosts.hostid',
			'snapshot' => 'string'
		]);
	}

	protected function doAction(): void {
		$this->guard(function (): void {
			$mib = CWalkService::mib();
			$varbinds = $this->resolveVarbinds();

			$analysis = (new CTableAnalyzer($mib))->analyze($varbinds);
			$mib->persist();

			$this->json([
				'tables' => $analysis['tables'],
				'scalars' => $analysis['scalars'],
				'mib_loaded' => $mib->isIndexed(),
				'counts' => [
					'tables' => count($analysis['tables']),
					'scalars' => count($analysis['scalars']),
					'varbinds' => count($varbinds)
				]
			]);
		});
	}

	/**
	 * @return \Modules\SnmpWalk\Includes\CVarbind[]
	 */
	private function resolveVarbinds(): array {
		$snapshot = $this->getInput('snapshot', '');

		if ($snapshot !== '') {
			return CWalkService::snapshots()
				->load($this->getInput('hostid', ''), $snapshot)['varbinds'];
		}

		$buffer = new CWalkBuffer(CWalkService::dataDir(), (string) CWebUser::$data['userid'],
			$this->getInput('token', '')
		);

		return $buffer->varbinds();
	}
}
