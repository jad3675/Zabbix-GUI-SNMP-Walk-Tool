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
 *
 * Resolution stops at the API's boundary. Secret text and Vault macro values are never
 * returned by usermacro.get, by design, so they cannot be resolved here at any
 * permission level. Those references are left intact and reported by
 * unresolvedCredentials(); the script engine is the only one that can walk such a host,
 * because Zabbix server expands macros in a global script's command itself.
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

	/** True when the credentials came from the console rather than the interface. */
	public bool $overridden = false;

	/**
	 * Per-walk item timeout, or null to use the global SNMP timeout. A walk asks a
	 * device to do far more work than a single collection does, so the timeout that
	 * suits ordinary polling is often too tight here.
	 */
	public ?string $timeout = null;

	/** True when any transport setting came from the console. */
	public bool $transport_overridden = false;

	/** Protocol names indexed the way Zabbix stores them in interface details. */
	public const AUTH_PROTOCOLS = ['MD5', 'SHA1', 'SHA224', 'SHA256', 'SHA384', 'SHA512'];
	public const PRIV_PROTOCOLS = ['DES', 'AES128', 'AES192', 'AES256', 'AES192C', 'AES256C'];

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
	 * Replace the interface credentials with ones supplied for this walk only.
	 *
	 * The case this exists for is a host whose community lives in a Secret text or
	 * Vault macro and an install with no usable script engine: the value cannot be
	 * read here and there is nowhere else to get it, so the person at the keyboard
	 * types it. It is also the quickest way to answer "is the stored community simply
	 * wrong", which is a question the console could not previously ask.
	 *
	 * Nothing supplied here is written anywhere. It lives in this object for the
	 * duration of one request, goes to the device, and is gone. The browser holds it
	 * in memory to repeat it on each chunk of a resumable walk, which is why the
	 * console clears it when the host changes.
	 *
	 * The whole details array is rebuilt rather than merged, so nothing from the
	 * interface survives underneath the override and there is no combination of
	 * stored and typed credentials to reason about later.
	 *
	 * @throws \RuntimeException on anything that is not a complete, usable credential.
	 */
	public function applyOverride(array $override): void {
		$version = (int) ($override['version'] ?? 0);

		if (!in_array($version, [SNMP_V1, SNMP_V2C, SNMP_V3], true)) {
			throw new \RuntimeException(_('Select an SNMP version for the supplied credentials.'));
		}

		// Carried over because they describe how to talk to the device rather than
		// who is talking: overriding a community should not silently turn bulk off.
		$details = [
			'version' => $version,
			'bulk' => $this->details['bulk'] ?? SNMP_BULK_ENABLED,
			'max_repetitions' => $this->details['max_repetitions'] ?? 10
		];

		if ($version == SNMP_V3) {
			$level = (int) ($override['securitylevel'] ?? ITEM_SNMPV3_SECURITYLEVEL_NOAUTHNOPRIV);

			if (!in_array($level, [ITEM_SNMPV3_SECURITYLEVEL_NOAUTHNOPRIV,
					ITEM_SNMPV3_SECURITYLEVEL_AUTHNOPRIV, ITEM_SNMPV3_SECURITYLEVEL_AUTHPRIV], true)) {
				throw new \RuntimeException(_('Invalid SNMPv3 security level.'));
			}

			$securityname = trim((string) ($override['securityname'] ?? ''));

			if ($securityname === '') {
				throw new \RuntimeException(_('Supply the SNMPv3 security name.'));
			}

			$details['securityname'] = $securityname;
			$details['securitylevel'] = $level;
			$details['contextname'] = (string) ($override['contextname'] ?? '');
			$details['authprotocol'] = self::protocolIndex($override['authprotocol'] ?? 0,
				count(self::AUTH_PROTOCOLS)
			);
			$details['authpassphrase'] = '';
			$details['privprotocol'] = self::protocolIndex($override['privprotocol'] ?? 0,
				count(self::PRIV_PROTOCOLS)
			);
			$details['privpassphrase'] = '';

			if ($level != ITEM_SNMPV3_SECURITYLEVEL_NOAUTHNOPRIV) {
				$details['authpassphrase'] = (string) ($override['authpassphrase'] ?? '');

				if ($details['authpassphrase'] === '') {
					throw new \RuntimeException(_('Supply the SNMPv3 authentication passphrase, or select security level noAuthNoPriv.'));
				}
			}

			if ($level == ITEM_SNMPV3_SECURITYLEVEL_AUTHPRIV) {
				$details['privpassphrase'] = (string) ($override['privpassphrase'] ?? '');

				if ($details['privpassphrase'] === '') {
					throw new \RuntimeException(_('Supply the SNMPv3 privacy passphrase, or select security level authNoPriv.'));
				}
			}
		}
		else {
			$community = (string) ($override['community'] ?? '');

			// Not trimmed. A community string with a trailing space is a bad idea and
			// also somebody's production reality, and quietly changing what was typed
			// would make this tool lie about what it sent.
			if ($community === '') {
				throw new \RuntimeException(_('Supply the community string.'));
			}

			$details['community'] = $community;
		}

		$this->details = $details;
		$this->overridden = true;
	}

	/**
	 * Override how the request is made, as opposed to who is making it.
	 *
	 * Combined requests and max repetitions live in the interface, so tuning them
	 * previously meant editing host configuration, walking, and editing it back. That
	 * is a bad loop to be in while characterising an unfamiliar device, and worse when
	 * the host belongs to a customer whose configuration you would rather not touch to
	 * answer a question. Nothing here is written back to the interface.
	 *
	 * Absent keys leave the interface value alone, so the console can send only what
	 * the operator actually changed.
	 *
	 * @throws \RuntimeException on a value the server would reject anyway.
	 */
	public function applyTransport(array $override): void {
		if (array_key_exists('bulk', $override) && $override['bulk'] !== '') {
			$this->details['bulk'] = (int) $override['bulk'] === 0
				? SNMP_BULK_DISABLED
				: SNMP_BULK_ENABLED;
			$this->transport_overridden = true;
		}

		if (array_key_exists('max_repetitions', $override) && $override['max_repetitions'] !== '') {
			$repetitions = (int) $override['max_repetitions'];

			// The upper bound is where responses start fragmenting on a normal MTU
			// rather than anything Zabbix enforces. A device that needs more than this
			// wants a narrower subtree, not a bigger PDU.
			if ($repetitions < 1 || $repetitions > 250) {
				throw new \RuntimeException(_('Max repetitions must be between 1 and 250.'));
			}

			$this->details['max_repetitions'] = $repetitions;
			$this->transport_overridden = true;
		}

		if (array_key_exists('timeout', $override) && $override['timeout'] !== '') {
			$this->timeout = self::normalizeTimeout((string) $override['timeout']);
			$this->transport_overridden = true;
		}
	}

	/**
	 * Accept 30, 30s or 1m and return what the server will take.
	 *
	 * The server answers an out-of-range item timeout with "Unsupported timeout value"
	 * and nothing else, so the range is checked here where the message can say which
	 * field was wrong.
	 */
	private static function normalizeTimeout(string $value): string {
		$value = trim($value);

		if (!preg_match('/^([1-9][0-9]*)(s|m)?$/', $value, $match)) {
			throw new \RuntimeException(_('Timeout must be a number of seconds, optionally suffixed with s or m.'));
		}

		$seconds = (int) $match[1] * (($match[2] ?? 's') === 'm' ? 60 : 1);

		if ($seconds < 1 || $seconds > 600) {
			throw new \RuntimeException(_('Timeout must be between 1s and 600s.'));
		}

		return $seconds.'s';
	}

	private static function protocolIndex($value, int $count): int {
		$index = (int) $value;

		return $index >= 0 && $index < $count ? $index : 0;
	}

	/**
	 * Macro references still sitting in the credential fields after resolution.
	 *
	 * Returned as macro names, ready to drop into a message. A non-empty result means
	 * the value is Secret text, a Vault secret, or a macro that does not exist: in
	 * every one of those cases the frontend has no way to learn the real value, and
	 * only the Zabbix server can run this walk.
	 *
	 * Only the fields the configured SNMP version actually uses are examined, the same
	 * set CEngineServer sends, so a v3 host at noAuthNoPriv is not failed over
	 * passphrase macros nothing is going to read.
	 *
	 * @return string[]
	 */
	public function unresolvedCredentials(): array {
		if ($this->version() == SNMP_V3) {
			$fields = ['securityname', 'contextname'];
			$level = (int) ($this->details['securitylevel'] ?? ITEM_SNMPV3_SECURITYLEVEL_NOAUTHNOPRIV);

			if ($level != ITEM_SNMPV3_SECURITYLEVEL_NOAUTHNOPRIV) {
				$fields[] = 'authpassphrase';
			}

			if ($level == ITEM_SNMPV3_SECURITYLEVEL_AUTHPRIV) {
				$fields[] = 'privpassphrase';
			}
		}
		else {
			$fields = ['community'];
		}

		$unresolved = [];

		foreach ($fields as $field) {
			$value = (string) ($this->details[$field] ?? '');

			if (preg_match_all('/\{\$[A-Z0-9._]+(?::.+?)?\}/', $value, $matches)) {
				foreach ($matches[0] as $macro) {
					$unresolved[$macro] = true;
				}
			}
		}

		return array_keys($unresolved);
	}

	public function hasUnresolvedCredentials(): bool {
		return $this->unresolvedCredentials() !== [];
	}

	/**
	 * A description of the credentials safe to show in the UI. Nothing secret leaves
	 * the server: the community string and both passphrases are replaced, not masked
	 * character by character, so their length does not leak either.
	 */
	public function redacted(): array {
		$version = $this->version();
		$unresolved = $this->unresolvedCredentials();

		$out = [
			'host' => $this->name,
			'address' => $this->address,
			'port' => $this->port,
			'version' => $version == SNMP_V3 ? '3' : ($version == SNMP_V1 ? '1' : '2c'),
			'proxy' => $this->proxy_name,
			'writable' => $this->writable,
			// Naming the macros is the whole point: '(empty)' against a host that
			// plainly has a community configured is how this looked before, and it
			// sent people looking at the device.
			'unresolved_macros' => $unresolved,
			'overridden' => $this->overridden,
			'bulk' => $this->usesBulk() ? 1 : 0,
			'max_repetitions' => $this->maxRepetitions(),
			'timeout' => $this->timeout
		];

		if ($version == SNMP_V3) {
			$out['security_name'] = $this->details['securityname'] ?? '';
			$out['security_level'] = ['noAuthNoPriv', 'authNoPriv', 'authPriv'][
				(int) ($this->details['securitylevel'] ?? 0)
			] ?? 'noAuthNoPriv';
			$out['context_name'] = $this->details['contextname'] ?? '';
		}
		elseif ($this->overridden) {
			$out['community'] = '(supplied)';
		}
		elseif ($unresolved) {
			$out['community'] = '(unresolved macro)';
		}
		else {
			$out['community'] = ($this->details['community'] ?? '') === '' ? '(empty)' : '(set)';
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

			// An unexpanded macro is not a secret, and blanking it out of the error
			// message would hide the one thing that explains the failure.
			if (str_contains($secret, '{$')) {
				continue;
			}

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
	 * Expand {$MACRO} and {$MACRO:context} references. Secret text and Vault macros
	 * cannot be read through the API, so they are left as-is and the walk is refused
	 * with a clear message rather than authenticating as the literal string or, worse,
	 * as nothing at all.
	 */
	private function expand(string $value): string {
		if (!str_contains($value, '{$')) {
			return $value;
		}

		return (string) preg_replace_callback('/\{\$[A-Z0-9._]+(?::.+?)?\}/', function (array $m): string {
			return $this->macros[$m[0]] ?? $m[0];
		}, $value);
	}

	/**
	 * The macro values the frontend is actually allowed to see.
	 *
	 * Only plain text macros qualify. Vault macros are resolved by the server, and
	 * usermacro.get omits the value field entirely for Secret text, so an earlier
	 * version of this method mapped {$SNMP_COMMUNITY} to '' and walked with an empty
	 * community: a timeout, with nothing in it to suggest why. Leaving both kinds out
	 * of the map means the reference survives into details unexpanded, where
	 * unresolvedCredentials() can name it.
	 *
	 * A macro that is unreadable at a higher precedence must also remove a readable
	 * value inherited from below it, or a host-level Secret text macro would fall
	 * through to whatever plain text value the template had.
	 */
	private static function macroMap(array $host): array {
		$map = [];

		foreach (API::UserMacro()->get(['globalmacro' => true, 'output' => ['macro', 'value', 'type']]) as $macro) {
			if ((int) ($macro['type'] ?? ZBX_MACRO_TYPE_TEXT) === ZBX_MACRO_TYPE_TEXT) {
				$map[$macro['macro']] = (string) ($macro['value'] ?? '');
			}
		}

		// Inherited (template) macros are overridden by host macros, so apply them first.
		foreach (['inheritedMacros', 'macros'] as $source) {
			foreach ($host[$source] ?? [] as $macro) {
				if ((int) ($macro['type'] ?? ZBX_MACRO_TYPE_TEXT) === ZBX_MACRO_TYPE_TEXT) {
					$map[$macro['macro']] = (string) ($macro['value'] ?? '');
				}
				else {
					unset($map[$macro['macro']]);
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
