<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Includes;

use API;

/**
 * Everything the engines need to know about a host: its SNMP interface, the resolved
 * SNMP credentials, and whether it is monitored through a proxy.
 *
 * Credentials come from the interface "details" fields, which in practice always hold
 * user macros ({$SNMP_COMMUNITY} and friends). Those are resolved here, once, so the
 * engines never deal with macros. Nothing that comes out of here is ever echoed back
 * to the browser: see redacted().
 */
final class CHostContext {

	public string $hostid;
	public string $host;
	public string $name;
	public string $interfaceid;
	public string $address;
	public string $port;
	public array $details;
	public ?string $proxyid = null;
	public ?string $proxy_name = null;
	public bool $writable = false;

	private array $macros = [];

	/**
	 * @throws \RuntimeException when the host is not visible to the user or has no SNMP interface.
	 */
	public static function load(string $hostid, ?string $interfaceid = null): self {
		$hosts = API::Host()->get([
			'output' => ['hostid', 'host', 'name', 'proxyid', 'proxy_hostid', 'monitored_by'],
			'selectInterfaces' => ['interfaceid', 'type', 'ip', 'dns', 'useip', 'port', 'main', 'details'],
			'selectMacros' => ['macro', 'value', 'type'],
			'selectInheritedMacros' => ['macro', 'value', 'type'],
			'hostids' => $hostid
		]);

		if (!$hosts) {
			throw new \RuntimeException(_('No permissions to referred host or it does not exist.'));
		}

		$host = reset($hosts);

		$writable = (bool) API::Host()->get([
			'output' => [],
			'hostids' => $hostid,
			'editable' => true,
			'countOutput' => true
		]);

		$snmp_interfaces = array_values(array_filter($host['interfaces'],
			static fn(array $i): bool => (int) $i['type'] === INTERFACE_TYPE_SNMP
		));

		if (!$snmp_interfaces) {
			throw new \RuntimeException(_s('Host "%1$s" has no SNMP interface.', $host['name']));
		}

		$interface = null;

		if ($interfaceid !== null && $interfaceid !== '') {
			foreach ($snmp_interfaces as $candidate) {
				if (bccomp($candidate['interfaceid'], $interfaceid) == 0) {
					$interface = $candidate;
					break;
				}
			}
		}

		if ($interface === null) {
			foreach ($snmp_interfaces as $candidate) {
				if ((int) $candidate['main'] === INTERFACE_PRIMARY) {
					$interface = $candidate;
					break;
				}
			}
		}

		if ($interface === null) {
			$interface = reset($snmp_interfaces);
		}

		$context = new self();
		$context->hostid = $host['hostid'];
		$context->host = $host['host'];
		$context->name = $host['name'];
		$context->writable = $writable;
		$context->interfaceid = $interface['interfaceid'];
		$context->address = (int) $interface['useip'] === INTERFACE_USE_IP
			? $interface['ip']
			: $interface['dns'];
		$context->port = $interface['port'];
		$context->details = $interface['details'] ?: [];

		$proxyid = $host['proxyid'] ?? ($host['proxy_hostid'] ?? null);

		if ($proxyid !== null && bccomp((string) $proxyid, '0') != 0) {
			$context->proxyid = (string) $proxyid;
			$context->proxy_name = self::proxyName($context->proxyid);
		}

		$context->macros = self::macroMap($host);
		$context->resolveDetails();

		return $context;
	}

	/**
	 * All SNMP interfaces on the host, for the interface selector in the console.
	 */
	public static function interfaces(string $hostid): array {
		$interfaces = API::HostInterface()->get([
			'output' => ['interfaceid', 'ip', 'dns', 'useip', 'port', 'main', 'details'],
			'hostids' => $hostid,
			'filter' => ['type' => INTERFACE_TYPE_SNMP]
		]);

		$out = [];

		foreach ($interfaces as $interface) {
			$address = (int) $interface['useip'] === INTERFACE_USE_IP ? $interface['ip'] : $interface['dns'];
			$version = (int) ($interface['details']['version'] ?? SNMP_V2C);

			$out[] = [
				'interfaceid' => $interface['interfaceid'],
				'label' => $address.':'.$interface['port'].' (SNMPv'.($version == SNMP_V3 ? '3' : ($version == SNMP_V1 ? '1' : '2c')).')',
				'main' => (int) $interface['main'] === INTERFACE_PRIMARY
			];
		}

		return $out;
	}

	public function version(): int {
		return (int) ($this->details['version'] ?? SNMP_V2C);
	}

	public function usesBulk(): bool {
		return (int) ($this->details['bulk'] ?? SNMP_BULK_ENABLED) == SNMP_BULK_ENABLED;
	}

	public function maxRepetitions(): int {
		return (int) ($this->details['max_repetitions'] ?? 10);
	}

	/**
	 * A description of the credentials safe to show in the UI. Nothing secret leaves
	 * the server: the community string and both passphrases are replaced, not masked
	 * character by character, so their length does not leak either.
	 */
	public function redacted(): array {
		$version = $this->version();

		$out = [
			'host' => $this->name,
			'address' => $this->address,
			'port' => $this->port,
			'version' => $version == SNMP_V3 ? '3' : ($version == SNMP_V1 ? '1' : '2c'),
			'proxy' => $this->proxy_name,
			'writable' => $this->writable
		];

		if ($version == SNMP_V3) {
			$out['security_name'] = $this->details['securityname'] ?? '';
			$out['security_level'] = ['noAuthNoPriv', 'authNoPriv', 'authPriv'][
				(int) ($this->details['securitylevel'] ?? 0)
			] ?? 'noAuthNoPriv';
			$out['context_name'] = $this->details['contextname'] ?? '';
		}
		else {
			$out['community'] = $this->details['community'] === '' ? '(empty)' : '(set)';
		}

		return $out;
	}

	/**
	 * Replace occurrences of the SNMP secrets in arbitrary text. Applied to any engine
	 * error message before it reaches the browser, because net-snmp and the Zabbix
	 * server both like to echo the community string back in failure messages.
	 */
	public function scrub(string $text): string {
		foreach (['community', 'authpassphrase', 'privpassphrase'] as $field) {
			$secret = (string) ($this->details[$field] ?? '');

			if (strlen($secret) > 2) {
				$text = str_replace($secret, '******', $text);
			}
		}

		return $text;
	}

	private function resolveDetails(): void {
		foreach ($this->details as $key => $value) {
			if (is_string($value)) {
				$this->details[$key] = $this->expand($value);
			}
		}
	}

	/**
	 * Expand {$MACRO} and {$MACRO:context} references. Secret-vault macros cannot be
	 * read through the API, so they are left as-is and the engine will fail with a
	 * clear message rather than silently authenticating as the literal string.
	 */
	private function expand(string $value): string {
		if (!str_contains($value, '{$')) {
			return $value;
		}

		return (string) preg_replace_callback('/\{\$[A-Z0-9._]+(?::.+?)?\}/', function (array $m): string {
			return $this->macros[$m[0]] ?? $m[0];
		}, $value);
	}

	private static function macroMap(array $host): array {
		$map = [];

		foreach (API::UserMacro()->get(['globalmacro' => true, 'output' => ['macro', 'value', 'type']]) as $macro) {
			if ((int) $macro['type'] !== ZBX_MACRO_TYPE_VAULT) {
				$map[$macro['macro']] = $macro['value'];
			}
		}

		// Inherited (template) macros are overridden by host macros, so apply them first.
		foreach (['inheritedMacros', 'macros'] as $source) {
			foreach ($host[$source] ?? [] as $macro) {
				if ((int) ($macro['type'] ?? 0) !== ZBX_MACRO_TYPE_VAULT) {
					$map[$macro['macro']] = (string) ($macro['value'] ?? '');
				}
			}
		}

		return $map;
	}

	private static function proxyName(string $proxyid): ?string {
		$proxies = API::Proxy()->get(['output' => ['name', 'host'], 'proxyids' => $proxyid]);

		if (!$proxies) {
			return null;
		}

		$proxy = reset($proxies);

		return $proxy['name'] ?? ($proxy['host'] ?? null);
	}
}
