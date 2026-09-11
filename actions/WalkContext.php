<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Actions;

use Modules\SnmpWalk\Includes\CHostContext;
use Modules\SnmpWalk\Includes\CWalkService;

/**
 * What the console needs after a host is picked: its SNMP interfaces, a redacted view
 * of the credentials that will be used, which engines can run against this host, and
 * the snapshots already on file for it.
 */
class WalkContext extends CWalkAction {

	protected function checkInput(): bool {
		return $this->validate([
			'hostid' => 'required|db hosts.hostid',
			'interfaceid' => 'db interface.interfaceid'
		]);
	}

	protected function doAction(): void {
		$this->guard(function (): void {
			$hostid = $this->getInput('hostid');
			$context = CHostContext::load($hostid, $this->getInput('interfaceid', ''));

			$this->json([
				'host' => $context->redacted(),
				'interfaces' => CHostContext::interfaces($hostid),
				'interfaceid' => $context->interfaceid,
				'engines' => CWalkService::engineStatus($context),
				'snapshots' => CWalkService::snapshots()->listForHost($hostid)
			]);
		});
	}
}
