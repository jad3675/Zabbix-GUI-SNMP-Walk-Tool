<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Includes;

use API;

/**
 * Script engine: run a global script on the Zabbix server or proxy through
 * script.execute.
 *
 * Slower to set up than the server engine (an admin creates one script, once) but
 * built entirely on published API, so it does not break when frontend internals move.
 * This is the engine to reach for when the server engine's diagnosis comes back
 * unusable, and the one to standardise on for proxied customer estates.
 *
 * The script must have scope "manual host action", execute on "Zabbix server (proxy)",
 * and take the OID through {MANUALINPUT}. contrib/global-script.md has the exact
 * configuration and a command line that does not leak the community string into ps.
 */
final class CEngineScript implements CEngine {

	private CHostContext $host;
	private string $scriptid;
	private ?string $script_name = null;

	public function __construct(CHostContext $host, string $scriptid) {
		if ($scriptid === '' || !ctype_digit($scriptid)) {
			throw new \RuntimeException(
				_('No walk script is configured. Set script_id in the module configuration, or choose a different engine.')
			);
		}

		$this->host = $host;
		$this->scriptid = $scriptid;
	}

	public function isResumable(): bool {
		return false;
	}

	public function name(): string {
		return 'script';
	}

	public function origin(): string {
		return $this->host->proxy_name !== null
			? _s('Zabbix proxy %1$s', $this->host->proxy_name)
			: _('Zabbix server');
	}

	/**
	 * Confirm the configured script exists and is shaped the way the engine needs.
	 */
	public static function diagnose(?string $scriptid): array {
		$out = ['configured' => false, 'usable' => false, 'name' => null, 'reason' => null];

		if ($scriptid === null || $scriptid === '') {
			$out['reason'] = _('No script configured.');

			return $out;
		}

		$out['configured'] = true;

		$scripts = API::Script()->get([
			'output' => ['scriptid', 'name', 'type', 'scope', 'execute_on', 'manualinput'],
			'scriptids' => $scriptid
		]);

		if (!$scripts) {
			$out['reason'] = _s('Script %1$s does not exist or is not visible to this user.', $scriptid);

			return $out;
		}

		$script = reset($scripts);
		$out['name'] = $script['name'];

		if ((int) $script['scope'] !== ZBX_SCRIPT_SCOPE_HOST) {
			$out['reason'] = _('The configured script must have the scope "Manual host action".');

			return $out;
		}

		$out['usable'] = true;

		return $out;
	}

	public function walk(string $root, ?string $cursor, int $limit): array {
		$root = COid::normalize($root);

		if (!COid::isNumeric($root)) {
			throw new \RuntimeException(_s('Invalid OID "%1$s".', $root));
		}

		$diagnosis = self::diagnose($this->scriptid);

		if (!$diagnosis['usable']) {
			throw new \RuntimeException((string) $diagnosis['reason']);
		}

		$this->script_name = $diagnosis['name'];

		// The OID has already passed COid::isNumeric(), so it holds only digits and
		// dots. It still goes through as manual input rather than being interpolated
		// into a command, so the script's own quoting is what protects the shell.
		$result = API::Script()->execute([
			'scriptid' => $this->scriptid,
			'hostid' => $this->host->hostid,
			'manualinput' => '.'.$root
		]);

		if (!is_array($result) || ($result['response'] ?? '') !== 'success') {
			throw new \RuntimeException($this->host->scrub(
				(string) ($result['value'] ?? _('The script did not return a result.'))
			));
		}

		$parsed = CWalkParser::parse((string) ($result['value'] ?? ''));
		$varbinds = [];

		foreach ($parsed['varbinds'] as $vb) {
			if ($vb->oid !== '' && COid::isChildOf($vb->oid, $root)) {
				$varbinds[] = $vb;
			}
		}

		$notices = array_map([$this->host, 'scrub'], $parsed['notices']);

		if (!$varbinds && $parsed['varbinds']) {
			$notices[] = _('The script returned symbolic OIDs. Add -On to the snmpwalk command so results can be indexed numerically.');
		}

		return [
			'varbinds' => $varbinds,
			'cursor' => null,
			'done' => true,
			'skipped' => $parsed['skipped'],
			'notices' => $notices
		];
	}

	public function scriptName(): ?string {
		return $this->script_name;
	}
}
