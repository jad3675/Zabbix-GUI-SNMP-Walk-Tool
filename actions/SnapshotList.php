<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Actions;

use API;
use Modules\SnmpWalk\Includes\CWalkService;

/**
 * Snapshots for one host, or across a host group when the diff picker needs to compare
 * one device against another.
 */
class SnapshotList extends CWalkAction {

	protected function checkInput(): bool {
		return $this->validate([
			'hostid' => 'db hosts.hostid',
			'groupid' => 'db hstgrp.groupid'
		]);
	}

	protected function doAction(): void {
		$this->guard(function (): void {
			$store = CWalkService::snapshots();

			if ($this->hasInput('hostid')) {
				$this->json(['snapshots' => $store->listForHost($this->getInput('hostid'))]);

				return;
			}

			$options = [
				'output' => ['hostid', 'name'],
				'with_items' => true,
				'limit' => 1000
			];

			if ($this->hasInput('groupid')) {
				$options['groupids'] = $this->getInput('groupid');
			}

			$hosts = API::Host()->get($options);
			$names = array_column($hosts, 'name', 'hostid');
			$snapshots = $store->listForHosts(array_keys($names));

			foreach ($snapshots as &$snapshot) {
				$snapshot['host_name'] = $names[$snapshot['hostid']] ?? $snapshot['host_name'];
			}
			unset($snapshot);

			$this->json(['snapshots' => $snapshots]);
		});
	}
}
