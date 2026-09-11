<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Includes;

use CSessionHelper;
use CSettingsHelper;
use CZabbixServer;

/**
 * Server engine: run an unsaved walk[] item through the Zabbix server's item test.
 *
 * This is the same mechanism as the Test button on the item configuration form. The
 * frontend opens a trapper connection to the server, the server (or the proxy that
 * owns the host) performs the SNMP request, and the raw value comes back. That buys
 * proxy routing, server-side macro resolution and SNMPv3 handling for free, with no
 * new credential path and no new firewall hole.
 *
 * ---------------------------------------------------------------------------------
 * VERSION-SENSITIVE SEAM. CZabbixServer::testItem() is internal frontend API, not
 * published API, and its request shape has changed between releases. Everything that
 * depends on it is isolated in this class and guarded, and diagnose() reports exactly
 * what it found so a failure here is a clear message rather than a white page. If a
 * future release breaks it, this is the one file to fix; the script engine is the
 * documented alternative in the meantime.
 * ---------------------------------------------------------------------------------
 */
final class CEngineServer implements CEngine {

	private CHostContext $host;

	public function __construct(CHostContext $host) {
		$this->host = $host;
	}

	public function isResumable(): bool {
		// walk[] returns the whole subtree in one value. There is no cursor to resume
		// from, so the console asks for the subtree and shows an indeterminate spinner.
		return false;
	}

	public function name(): string {
		return 'server';
	}

	public function origin(): string {
		return $this->host->proxy_name !== null
			? _s('Zabbix proxy %1$s', $this->host->proxy_name)
			: _('Zabbix server');
	}

	/**
	 * Report what this engine can and cannot see, without performing a walk. Surfaced
	 * in the console so the seam above is visible rather than mysterious.
	 */
	public static function diagnose(): array {
		$out = [
			'class' => class_exists('CZabbixServer'),
			'method' => false,
			'address' => null,
			'usable' => false,
			'reason' => null
		];

		if (!$out['class']) {
			$out['reason'] = _('CZabbixServer is not available in this frontend.');

			return $out;
		}

		$out['method'] = method_exists('CZabbixServer', 'testItem');

		if (!$out['method']) {
			$out['reason'] = _('This frontend version does not expose CZabbixServer::testItem(). Use the script engine.');

			return $out;
		}

		[$address, $port] = self::serverAddress();
		$out['address'] = $address === null ? null : $address.':'.$port;

		if ($address === null) {
			$out['reason'] = _('The Zabbix server address is not configured in zabbix.conf.php.');

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

		$diagnosis = self::diagnose();

		if (!$diagnosis['usable']) {
			throw new \RuntimeException((string) $diagnosis['reason']);
		}

		[$address, $port] = self::serverAddress();

		$server = new CZabbixServer($address, $port,
			timeUnitToSeconds(CSettingsHelper::get(CSettingsHelper::CONNECT_TIMEOUT)),
			timeUnitToSeconds(CSettingsHelper::get(defined('CSettingsHelper::ITEM_TEST_TIMEOUT')
				? CSettingsHelper::ITEM_TEST_TIMEOUT
				: CSettingsHelper::SOCKET_TIMEOUT
			)),
			ZBX_SOCKET_BYTES_LIMIT
		);

		// This structure mirrors what CControllerPopupItemTest builds for an SNMP item:
		// options, item and host, with the interface nested under host. A flat payload
		// gets "Missing item field." back from the server.
		$request = [
			'options' => [
				'single' => true,
				'state' => 0
			],
			'item' => [
				'type' => ITEM_TYPE_SNMP,
				'value_type' => ITEM_VALUE_TYPE_TEXT,
				'flags' => ZBX_FLAG_DISCOVERY_NORMAL,
				'snmp_oid' => 'walk['.$root.']',
				'timeout' => self::itemTimeout()
			],
			'host' => [
				'host' => $this->host->host,
				'proxyid' => $this->host->proxyid ?? 0,
				'interface' => [
					'interfaceid' => $this->host->interfaceid,
					'address' => $this->host->address,
					'port' => $this->host->port,
					'details' => $this->relevantDetails()
				]
			]
		];

		$result = $server->testItem($request, CSessionHelper::getId());

		if ($result === false) {
			throw new \RuntimeException($this->host->scrub((string) $server->getError()));
		}

		$item = is_array($result) && array_key_exists('item', $result) ? (array) $result['item'] : [];

		// The device-side failure comes back inside the item, not as a transport error.
		if (array_key_exists('error', $item) && $item['error'] !== '') {
			throw new \RuntimeException($this->host->scrub((string) $item['error']));
		}

		$value = array_key_exists('result', $item)
			? (string) $item['result']
			: self::extractValue($result);

		if ($value === '') {
			throw new \RuntimeException(_s('The server returned no value. Response shape: %1$s',
				self::describe($result)
			));
		}

		$parsed = CWalkParser::parse($value);
		$varbinds = [];

		foreach ($parsed['varbinds'] as $vb) {
			if ($vb->oid !== '' && COid::isChildOf($vb->oid, $root)) {
				$varbinds[] = $vb;
			}
		}

		$notices = $parsed['notices'];

		// A response that yields nothing is the failure mode this engine is prone to,
		// because the item test response shape is not published and has moved between
		// releases. Reporting what actually came back turns that from a dead end into
		// a five-minute fix.
		if (!$varbinds) {
			$notices[] = _s('Nothing in the response parsed as a varbind. Response shape: %1$s',
				self::describe($result)
			);
			$notices[] = _s('First 300 characters of the value: %1$s',
				$this->host->scrub(mb_substr($value, 0, 300))
			);

			if ($parsed['varbinds']) {
				$notices[] = _s('%1$s lines parsed but fell outside the requested subtree .%2$s',
					count($parsed['varbinds']), $root
				);
			}
		}

		if (count($varbinds) >= $limit) {
			$notices[] = _s('The server returned %1$s varbinds, at or above the per-request limit. Walk a narrower subtree if values look truncated.',
				count($varbinds)
			);
		}

		return [
			'varbinds' => $varbinds,
			'cursor' => null,
			'done' => true,
			'skipped' => $parsed['skipped'],
			'notices' => $notices
		];
	}

	/**
	 * Per-item timeout for the test request.
	 *
	 * Since 7.0 an SNMP item carries its own timeout, and the server refuses the test
	 * with "Unsupported timeout value" if it is missing or not a time unit. Take the
	 * global SNMP agent timeout so a walk waits exactly as long as ordinary collection
	 * against the same device would, rather than inventing a number.
	 */
	private static function itemTimeout(): string {
		if (defined('CSettingsHelper::TIMEOUT_SNMP_AGENT')) {
			$timeout = (string) CSettingsHelper::get(CSettingsHelper::TIMEOUT_SNMP_AGENT);

			if ($timeout !== '') {
				return $timeout;
			}
		}

		return '3s';
	}

	/**
	 * Interface details with the fields that do not apply to this SNMP version removed.
	 *
	 * The server rejects a v2c interface that also carries SNMPv3 security fields, and
	 * sending a community string alongside v3 credentials is a good way to leak one.
	 * The frontend prunes the same set before its own item test.
	 */
	private function relevantDetails(): array {
		$details = $this->host->details;

		if ((int) ($details['version'] ?? SNMP_V2C) == SNMP_V3) {
			$unrelated = ['community'];

			$level = (int) ($details['securitylevel'] ?? 0);

			if ($level == ITEM_SNMPV3_SECURITYLEVEL_NOAUTHNOPRIV) {
				$unrelated = array_merge($unrelated,
					['authprotocol', 'authpassphrase', 'privprotocol', 'privpassphrase']
				);
			}
			elseif ($level == ITEM_SNMPV3_SECURITYLEVEL_AUTHNOPRIV) {
				$unrelated = array_merge($unrelated, ['privprotocol', 'privpassphrase']);
			}
		}
		else {
			$unrelated = ['contextname', 'securityname', 'securitylevel', 'authprotocol',
				'authpassphrase', 'privprotocol', 'privpassphrase'
			];
		}

		return array_diff_key($details, array_flip($unrelated));
	}

	/**
	 * Pull the item value out of whatever shape the item test came back in.
	 *
	 * Zabbix has returned this as a bare string, as ['result' => string], and as a
	 * nested structure under 'result' or 'value' depending on the release. Rather than
	 * pinning one of those, look for the first string that plausibly holds walk output.
	 */
	private static function extractValue($result, int $depth = 0): string {
		if (is_string($result)) {
			return $result;
		}

		if (!is_array($result) || $depth > 4) {
			return '';
		}

		// Preferred keys first, so a structure carrying both a value and an error
		// message does not return the error message as the walk.
		foreach (['value', 'result', 'data', 'item', 0] as $key) {
			if (array_key_exists($key, $result)) {
				$found = self::extractValue($result[$key], $depth + 1);

				if ($found !== '') {
					return $found;
				}
			}
		}

		foreach ($result as $key => $entry) {
			if ($key === 'error' || $key === 'errors') {
				continue;
			}

			$found = self::extractValue($entry, $depth + 1);

			if ($found !== '') {
				return $found;
			}
		}

		return '';
	}

	/**
	 * A one-line description of a response structure, for error messages. Keys only,
	 * never values, so nothing sensitive is echoed back to the browser.
	 */
	private static function describe($result, int $depth = 0): string {
		if (is_string($result)) {
			return 'string('.strlen($result).')';
		}

		if (is_bool($result)) {
			return $result ? 'true' : 'false';
		}

		if (is_scalar($result)) {
			return gettype($result);
		}

		if (!is_array($result)) {
			return gettype($result);
		}

		if ($depth > 2) {
			return 'array('.count($result).')';
		}

		$parts = [];

		foreach ($result as $key => $entry) {
			$parts[] = $key.': '.self::describe($entry, $depth + 1);
		}

		return '{'.implode(', ', $parts).'}';
	}

	/**
	 * @return array{0: string|null, 1: int}
	 */
	private static function serverAddress(): array {
		global $ZBX_SERVER, $ZBX_SERVER_PORT;

		$address = $ZBX_SERVER ?? null;
		$port = (int) ($ZBX_SERVER_PORT ?? 10051);

		if ($address === null || $address === '') {
			return [null, $port];
		}

		return [(string) $address, $port ?: 10051];
	}
}
