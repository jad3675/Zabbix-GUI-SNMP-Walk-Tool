<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Actions;

use CWebUser;
use Modules\SnmpWalk\Includes\CHostContext;
use Modules\SnmpWalk\Includes\COid;
use Modules\SnmpWalk\Includes\CWalkBuffer;
use Modules\SnmpWalk\Includes\CWalkService;

/**
 * Run one chunk of a walk.
 *
 * The client calls this repeatedly with the cursor from the previous response until
 * done comes back true. Resumable engines therefore give a progress bar and a partial
 * result that is still useful if the device stops answering halfway through; one-shot
 * engines return everything in the first call.
 *
 * The OID is validated here before it reaches any engine. It is the only user-supplied
 * value that ends up anywhere near a network call or a subprocess, so it is checked
 * against a strict numeric pattern rather than sanitised.
 */
class WalkRun extends CWalkAction {

	protected function checkInput(): bool {
		return $this->validate([
			'hostid' => 'required|db hosts.hostid',
			'interfaceid' => 'db interface.interfaceid',
			'oid' => 'required|string',
			'engine' => 'in auto,local,server,script',
			'cursor' => 'string',
			'token' => 'string',
			// Credentials supplied for this walk only. Never stored, never logged,
			// never echoed back: see CHostContext::applyOverride().
			'cred_version' => 'in 1,2,3',
			'cred_community' => 'string',
			'cred_securityname' => 'string',
			'cred_securitylevel' => 'in 0,1,2',
			'cred_authprotocol' => 'int32',
			'cred_authpassphrase' => 'string',
			'cred_privprotocol' => 'int32',
			'cred_privpassphrase' => 'string',
			'cred_contextname' => 'string'
		]);
	}

	protected function doAction(): void {
		$this->guard(function (): void {
			$mib = CWalkService::mib();
			$requested = trim($this->getInput('oid'));

			// Accept a symbolic root and resolve it, so the box takes IF-MIB::ifTable
			// as happily as it takes 1.3.6.1.2.1.2.2.
			if (!COid::isNumeric($requested)) {
				if (!COid::isSymbolic($requested)) {
					$this->fail(_s('"%1$s" is not a valid OID.', $requested));

					return;
				}

				$resolved = $mib->resolve($requested);

				if ($resolved === null) {
					$this->fail(_s('Cannot resolve "%1$s". Load the MIB that defines it, or enter a numeric OID.',
						$requested
					));

					return;
				}

				$requested = $resolved;
			}

			$root = COid::normalize($requested);

			if (COid::depth($root) < 2) {
				$this->fail(_('Walking from the root of the MIB tree would take a very long time. Start at 1.3.6.1.2.1 or lower.'));

				return;
			}

			$context = CHostContext::load($this->getInput('hostid'), $this->getInput('interfaceid', ''));

			if ($this->getInput('cred_version', '') !== '') {
				$context->applyOverride([
					'version' => $this->getInput('cred_version'),
					'community' => $this->getInput('cred_community', ''),
					'securityname' => $this->getInput('cred_securityname', ''),
					'securitylevel' => $this->getInput('cred_securitylevel', '0'),
					'authprotocol' => $this->getInput('cred_authprotocol', '0'),
					'authpassphrase' => $this->getInput('cred_authpassphrase', ''),
					'privprotocol' => $this->getInput('cred_privprotocol', '0'),
					'privpassphrase' => $this->getInput('cred_privpassphrase', ''),
					'contextname' => $this->getInput('cred_contextname', '')
				]);
			}

			$engine = CWalkService::engine($context, $this->getInput('engine', 'auto'));

			$cursor = $this->getInput('cursor', '');
			$cursor = $cursor !== '' && COid::isNumeric($cursor) ? $cursor : null;

			$buffer = new CWalkBuffer(CWalkService::dataDir(), (string) CWebUser::$data['userid'],
				$cursor === null || $this->getInput('token', '') === ''
					? CWalkBuffer::newToken()
					: $this->getInput('token')
			);

			if ($cursor === null) {
				$buffer->start([
					'hostid' => $context->hostid,
					'host_name' => $context->name,
					'root' => $root,
					'engine' => $engine->name(),
					'origin' => $engine->origin(),
					'started' => time(),
					'user' => CWebUser::$data['username'],
					// The fact, not the value. Six months later, "these numbers came
					// from credentials somebody typed" is worth knowing about a
					// snapshot; the credentials themselves are not written anywhere.
					'credentials' => $context->overridden ? 'supplied' : 'interface'
				]);

				$this->log('walk', [
					'host' => $context->name,
					'oid' => $root,
					'engine' => $engine->name(),
					'credentials' => $context->overridden ? 'supplied' : 'interface'
				]);
			}

			$limit = CWalkService::chunkSize();
			$result = $engine->walk($root, $cursor, $limit);

			$mib->annotate($result['varbinds']);
			$mib->persist();

			$total = $buffer->append($result['varbinds']);
			$done = $result['done'];

			if (!$done && $total >= CWalkService::maxVarbinds()) {
				$done = true;
				$result['notices'][] = _s('Stopped at the configured limit of %1$s varbinds.',
					CWalkService::maxVarbinds()
				);
			}

			$rows = [];

			foreach ($result['varbinds'] as $vb) {
				$rows[] = [
					'oid' => $vb->oid,
					'name' => $vb->name,
					'mib' => $vb->mib,
					'type' => $vb->type,
					'value' => $vb->value
				];
			}

			$this->json([
				'token' => $buffer->token(),
				'rows' => $rows,
				'cursor' => $done ? null : $result['cursor'],
				'done' => $done,
				'total' => $total,
				'skipped' => $result['skipped'],
				'notices' => $result['notices'],
				'engine' => $engine->name(),
				'origin' => $engine->origin(),
				'resumable' => $engine->isResumable(),
				'root' => $root
			]);
		});
	}
}
