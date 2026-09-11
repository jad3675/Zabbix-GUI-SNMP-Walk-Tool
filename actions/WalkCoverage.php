<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Actions;

use CWebUser;
use Modules\SnmpWalk\Includes\CCoverage;
use Modules\SnmpWalk\Includes\CWalkBuffer;
use Modules\SnmpWalk\Includes\CWalkService;

/**
 * Diff a walk against what the host's items and LLD rules actually poll.
 *
 * Also reports the OIDs the host claims to monitor but that the device did not answer
 * for, which is the fastest way to find a template linked to the wrong device family.
 */
class WalkCoverage extends CWalkAction {

	protected function checkInput(): bool {
		return $this->validate([
			'hostid' => 'required|db hosts.hostid',
			'token' => 'string',
			'snapshot' => 'string'
		]);
	}

	protected function doAction(): void {
		$this->guard(function (): void {
			$hostid = $this->getInput('hostid');
			$snapshot = $this->getInput('snapshot', '');

			$varbinds = $snapshot !== ''
				? CWalkService::snapshots()->load($hostid, $snapshot)['varbinds']
				: (new CWalkBuffer(CWalkService::dataDir(), (string) CWebUser::$data['userid'],
					$this->getInput('token', '')))->varbinds();

			$mib = CWalkService::mib();
			$coverage = (new CCoverage($mib))->analyze($hostid, $varbinds);
			$mib->persist();

			// An OID a rule polls that the walk did not return is either a dead item or
			// a subtree outside the walk root. Both are worth surfacing.
			$walked = [];

			foreach ($varbinds as $vb) {
				$walked[$vb->oid] = true;
			}

			$unanswered = [];

			foreach ($coverage['monitored_rules'] as $rule) {
				$found = false;

				foreach ($walked as $oid => $_) {
					if (\Modules\SnmpWalk\Includes\COid::isChildOf((string) $oid, $rule['oid'])) {
						$found = true;
						break;
					}
				}

				if (!$found) {
					$unanswered[] = $rule;
				}
			}

			$coverage['unanswered'] = $unanswered;
			unset($coverage['monitored_rules']);

			$this->json($coverage);
		});
	}
}
