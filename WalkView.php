<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Actions;

use API;
use CControllerResponseData;
use Modules\SnmpWalk\Includes\CWalkService;

/**
 * The console page. Everything after first paint is driven by the JSON actions.
 */
class WalkView extends CWalkAction {

	protected function checkInput(): bool {
		return $this->validateInput([
			'hostid' => 'db hosts.hostid',
			'oid' => 'string',
			'snapshot' => 'string'
		]);
	}

	protected function doAction(): void {
		$hostid = $this->hasInput('hostid') ? $this->getInput('hostid') : '';
		$host = null;

		if ($hostid !== '') {
			$hosts = API::Host()->get(['output' => ['hostid', 'name'], 'hostids' => $hostid]);
			$host = $hosts ? reset($hosts) : null;
		}

		$mib = CWalkService::mib();

		$this->setResponse(new CControllerResponseData([
			'hostid' => $host !== null ? $host['hostid'] : '',
			'host_name' => $host !== null ? $host['name'] : '',
			'oid' => $this->getInput('oid', '1.3.6.1.2.1'),
			'snapshot' => $this->getInput('snapshot', ''),
			'chunk_size' => CWalkService::chunkSize(),
			'max_varbinds' => CWalkService::maxVarbinds(),
			'engines' => CWalkService::engineStatus(),
			'mib' => [
				'indexed' => $mib->isIndexed(),
				'objects' => $mib->isIndexed() ? $mib->count() : 0,
				'built' => $mib->indexedAt()
			]
		]));
	}
}
