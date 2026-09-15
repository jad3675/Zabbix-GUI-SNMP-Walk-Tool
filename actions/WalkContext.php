<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Actions;

use Modules\SnmpWalk\Includes\CHostContext;
use Modules\SnmpWalk\Includes\CWalkService;

/**
 * What the console needs after a host is picked: its SNMP interfaces, a redacted view
 * of the credentials that will be used, which engines can run against this host, and
 * the snapshots already on file for it.
 *
 * Re-requested when the console's credential override is switched on or off, because
 * that changes which engines can run: supplied credentials rule the script engine out,
 * and unreadable stored ones rule the other two out.
 */
class WalkContext extends CWalkAction {

	protected function checkInput(): bool {
		return $this->validate([
			'hostid' => 'required|db hosts.hostid',
			'interfaceid' => 'db interface.interfaceid',
			// Whether the console intends to supply credentials, not what they are.
			// Enough to answer which engines could use them, without the values
			// leaving the browser until there is a walk to run.
			'credentials' => 'in 0,1',
			// Echoed back through redacted() so the console can show what the walk
			// will actually use rather than what the interface says.
			'bulk' => 'in 0,1',
			'max_repetitions' => 'string',
			'item_timeout' => 'string'
		]);
	}

	protected function doAction(): void {
		$this->guard(function (): void {
			$hostid = $this->getInput('hostid');
			$context = CHostContext::load($hostid, $this->getInput('interfaceid', ''));
			$context->overridden = (int) $this->getInput('credentials', 0) === 1;
			$context->applyTransport([
				'bulk' => $this->getInput('bulk', ''),
				'max_repetitions' => $this->getInput('max_repetitions', ''),
				'timeout' => $this->getInput('item_timeout', '')
			]);

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
