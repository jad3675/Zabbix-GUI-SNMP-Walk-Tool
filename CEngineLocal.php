<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Includes;

use SNMP;
use SNMPException;

/**
 * php-snmp engine.
 *
 * Uses repeated GETNEXT rather than SNMP::walk() because walk() fetches an entire
 * subtree before returning, which is exactly the behaviour that makes a full walk of a
 * 48-port stack time out against max_execution_time. One GETNEXT per varbind is more
 * round trips, but it gives a cursor, and a cursor gives resumability and a progress
 * bar. On a LAN the extra latency is not noticeable; over a slow WAN, prefer the
 * server or script engine.
 */
final class CEngineLocal implements CEngine {

	private CHostContext $host;
	private int $timeout;
	private int $retries;
	private ?SNMP $session = null;

	/**
	 * ASN.1 type number to the net-snmp type token used everywhere else in the module.
	 */
	private const TYPES = [
		2 => 'INTEGER',
		3 => 'BITS',
		4 => 'STRING',
		5 => 'NULL',
		6 => 'OID',
		64 => 'IpAddress',
		65 => 'Counter32',
		66 => 'Gauge32',
		67 => 'Timeticks',
		68 => 'Opaque',
		70 => 'Counter64',
		71 => 'UInteger32'
	];

	private const END_OF_MIB = [128, 129, 130];

	public function __construct(CHostContext $host, int $timeout, int $retries) {
		if (!class_exists('SNMP')) {
			throw new \RuntimeException(
				_('The php-snmp extension is not installed, so the local engine is unavailable. Install php-snmp on the frontend host, or use the server or script engine.')
			);
		}

		$this->host = $host;
		$this->timeout = max(1, $timeout);
		$this->retries = max(0, $retries);
	}

	public function isResumable(): bool {
		return true;
	}

	public function name(): string {
		return 'local';
	}

	public function origin(): string {
		return _('Zabbix frontend host');
	}

	public function walk(string $root, ?string $cursor, int $limit): array {
		$root = COid::normalize($root);

		if (!COid::isNumeric($root)) {
			throw new \RuntimeException(_s('Invalid OID "%1$s".', $root));
		}

		$session = $this->session();
		$varbinds = [];
		$skipped = 0;
		$notices = [];
		$at = $cursor !== null && COid::isNumeric($cursor) ? COid::normalize($cursor) : $root;
		$done = false;

		for ($i = 0; $i < $limit; $i++) {
			try {
				$result = $session->getnext('.'.$at);
			}
			catch (SNMPException $e) {
				if ($varbinds) {
					// Partial data is worth keeping; report the failure and stop here.
					$notices[] = $this->host->scrub($e->getMessage());
					$done = true;
					break;
				}

				throw new \RuntimeException($this->host->scrub($e->getMessage()));
			}

			if (!is_array($result) || !$result) {
				$done = true;
				break;
			}

			$next_oid = COid::normalize((string) array_key_first($result));
			$object = reset($result);

			if ($next_oid === '' || COid::compare($next_oid, $at) <= 0) {
				// The agent went backwards or repeated itself. Stopping is the only
				// safe response; some agents do this at the end of a MIB view.
				$notices[] = _s('Agent returned a non-increasing OID after %1$s; stopping.', '.'.$at);
				$done = true;
				break;
			}

			$at = $next_oid;

			if (!COid::isChildOf($next_oid, $root)) {
				$done = true;
				break;
			}

			$type = is_object($object) ? (int) ($object->type ?? 0) : 0;

			if (in_array($type, self::END_OF_MIB, true)) {
				$skipped++;
				$done = true;
				break;
			}

			$varbinds[] = new CVarbind($next_oid, self::TYPES[$type] ?? 'Opaque',
				$this->stringify($type, is_object($object) ? $object->value : (string) $object)
			);
		}

		return [
			'varbinds' => $varbinds,
			'cursor' => $done ? null : $at,
			'done' => $done,
			'skipped' => $skipped,
			'notices' => $notices
		];
	}

	/**
	 * Octet strings that are not printable become Hex-STRING, matching what
	 * "snmpwalk -On" would have produced. Anything downstream that has to guess a
	 * Zabbix value type relies on this distinction.
	 */
	private function stringify(int $type, $value): string {
		$value = (string) $value;

		if ($type == 4 || $type == 3) {
			if ($value !== '' && !preg_match('//u', $value)) {
				return $this->hex($value);
			}

			if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
				return $this->hex($value);
			}
		}

		if ($type == 67 && preg_match('/^\((\d+)\)/', $value, $m)) {
			return $m[1];
		}

		return $value;
	}

	private function hex(string $value): string {
		return trim(chunk_split(strtoupper(bin2hex($value)), 2, ' '));
	}

	private function session(): SNMP {
		if ($this->session !== null) {
			return $this->session;
		}

		$version = $this->host->version();
		$target = $this->host->address.':'.$this->host->port;

		if ($this->host->address === '') {
			throw new \RuntimeException(_('The SNMP interface has no address.'));
		}

		$constants = [
			SNMP_V1 => defined('SNMP::VERSION_1') ? SNMP::VERSION_1 : 0,
			SNMP_V2C => defined('SNMP::VERSION_2C') ? SNMP::VERSION_2C : 1,
			SNMP_V3 => defined('SNMP::VERSION_3') ? SNMP::VERSION_3 : 3
		];

		$community = $version == SNMP_V3
			? (string) ($this->host->details['securityname'] ?? '')
			: (string) ($this->host->details['community'] ?? '');

		if (str_contains($community, '{$')) {
			throw new \RuntimeException(
				_('The SNMP credentials on this interface contain a macro that could not be resolved. Vault macros are not readable from the frontend; use the server or script engine for this host.')
			);
		}

		$session = new SNMP($constants[$version] ?? $constants[SNMP_V2C], $target, $community,
			$this->timeout * 1000000, $this->retries
		);

		$session->oid_output_format = SNMP_OID_OUTPUT_NUMERIC;
		$session->valueretrieval = SNMP_VALUE_OBJECT;
		$session->enum_print = false;
		$session->quick_print = false;
		$session->oid_increasing_check = false;
		$session->exceptions_enabled = SNMP::ERRNO_ANY;

		if ($version == SNMP_V3) {
			$session->setSecurity(
				$this->securityLevel(),
				$this->authProtocol(),
				(string) ($this->host->details['authpassphrase'] ?? ''),
				$this->privProtocol(),
				(string) ($this->host->details['privpassphrase'] ?? ''),
				(string) ($this->host->details['contextname'] ?? ''),
				''
			);
		}

		return $this->session = $session;
	}

	private function securityLevel(): string {
		return [
			ITEM_SNMPV3_SECURITYLEVEL_NOAUTHNOPRIV => 'noAuthNoPriv',
			ITEM_SNMPV3_SECURITYLEVEL_AUTHNOPRIV => 'authNoPriv',
			ITEM_SNMPV3_SECURITYLEVEL_AUTHPRIV => 'authPriv'
		][(int) ($this->host->details['securitylevel'] ?? 0)] ?? 'noAuthNoPriv';
	}

	private function authProtocol(): string {
		return ['MD5', 'SHA', 'SHA224', 'SHA256', 'SHA384', 'SHA512'][
			(int) ($this->host->details['authprotocol'] ?? 0)
		] ?? 'MD5';
	}

	private function privProtocol(): string {
		return ['DES', 'AES128', 'AES192', 'AES256', 'AES192C', 'AES256C'][
			(int) ($this->host->details['privprotocol'] ?? 0)
		] ?? 'DES';
	}
}
