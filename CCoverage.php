<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Includes;

use API;

/**
 * Compare a walk against what the host's items and LLD rules actually poll.
 *
 * The output answers a question that normally takes an afternoon: what does this
 * device expose that we are not monitoring. It is also the fastest way to prove a
 * template is doing what it claims during a customer onboarding, and to catch the case
 * where a template was linked but every item is unsupported because the vendor uses a
 * private OID branch.
 *
 * Monitored OIDs are extracted from snmp_oid on both items and discovery rules, which
 * means all of these are understood:
 *
 *   1.3.6.1.2.1.1.3.0
 *   IF-MIB::ifHCInOctets.1
 *   walk[1.3.6.1.2.1.2.2]
 *   get[1.3.6.1.2.1.1.1.0]
 *   discovery[{#IFDESCR},1.3.6.1.2.1.2.2.1.2]
 *   1.3.6.1.2.1.2.2.1.10.{#SNMPINDEX}
 */
final class CCoverage {

	private CMibLookup $mib;

	public function __construct(CMibLookup $mib) {
		$this->mib = $mib;
	}

	/**
	 * @param CVarbind[] $varbinds
	 */
	public function analyze(string $hostid, array $varbinds): array {
		$monitored = $this->monitoredOids($hostid);

		$covered = [];
		$uncovered = [];

		foreach ($varbinds as $vb) {
			$by = $this->coveredBy($vb->oid, $monitored);

			$row = [
				'oid' => $vb->oid,
				'name' => $vb->name,
				'type' => $vb->type,
				'value' => $vb->value
			];

			if ($by === null) {
				$uncovered[] = $row;
			}
			else {
				$row['by'] = $by;
				$covered[] = $row;
			}
		}

		// Roll uncovered varbinds up to their parent so the output is a short list of
		// subtrees to consider rather than 4000 individual OIDs.
		$branches = [];

		foreach ($uncovered as $row) {
			$parent = COid::parent($row['oid']);
			$hit = $this->mib->lookup($row['oid']);
			$key = $hit !== null ? $hit['oid'] : $parent;

			if (!array_key_exists($key, $branches)) {
				$branches[$key] = [
					'oid' => $key,
					'name' => $hit['name'] ?? null,
					'count' => 0,
					'sample' => $row['value'],
					'type' => $row['type']
				];
			}

			$branches[$key]['count']++;
		}

		$branches = array_values($branches);
		usort($branches, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);

		return [
			'monitored_rules' => $monitored,
			'covered_count' => count($covered),
			'uncovered_count' => count($uncovered),
			'total' => count($varbinds),
			'percent' => $varbinds ? round(count($covered) / count($varbinds) * 100, 1) : 0.0,
			'uncovered_branches' => $branches,
			'uncovered' => array_slice($uncovered, 0, 5000)
		];
	}

	/**
	 * @return array[]  ['oid','source','name','key','status','templated']
	 */
	private function monitoredOids(string $hostid): array {
		$out = [];

		$items = API::Item()->get([
			'output' => ['itemid', 'name', 'key_', 'snmp_oid', 'status', 'templateid', 'state', 'error'],
			'hostids' => $hostid,
			'filter' => ['type' => ITEM_TYPE_SNMP],
			'webitems' => true
		]);

		foreach ($items as $item) {
			foreach ($this->extract((string) $item['snmp_oid']) as $oid) {
				$out[] = [
					'oid' => $oid,
					'source' => 'item',
					'name' => $item['name'],
					'key' => $item['key_'],
					'status' => (int) $item['status'] == ITEM_STATUS_ACTIVE ? 'enabled' : 'disabled',
					'state' => (int) ($item['state'] ?? 0) == ITEM_STATE_NOTSUPPORTED ? 'unsupported' : 'normal',
					'error' => (string) ($item['error'] ?? ''),
					'templated' => $item['templateid'] !== '0'
				];
			}
		}

		$rules = API::DiscoveryRule()->get([
			'output' => ['itemid', 'name', 'key_', 'snmp_oid', 'status', 'templateid', 'state', 'error'],
			'hostids' => $hostid,
			'filter' => ['type' => ITEM_TYPE_SNMP]
		]);

		foreach ($rules as $rule) {
			foreach ($this->extract((string) $rule['snmp_oid']) as $oid) {
				$out[] = [
					'oid' => $oid,
					'source' => 'discovery',
					'name' => $rule['name'],
					'key' => $rule['key_'],
					'status' => (int) $rule['status'] == ITEM_STATUS_ACTIVE ? 'enabled' : 'disabled',
					'state' => (int) ($rule['state'] ?? 0) == ITEM_STATE_NOTSUPPORTED ? 'unsupported' : 'normal',
					'error' => (string) ($rule['error'] ?? ''),
					'templated' => $rule['templateid'] !== '0'
				];
			}
		}

		return $out;
	}

	/**
	 * Pull every numeric OID out of an snmp_oid field. Public so it can be exercised
	 * directly: this is the piece most likely to quietly stop recognising a syntax.
	 *
	 * @return string[]
	 */
	public function extract(string $snmp_oid): array {
		$snmp_oid = trim($snmp_oid);

		if ($snmp_oid === '') {
			return [];
		}

		// Strip LLD macros; the branch above the macro is what is being polled.
		$snmp_oid = preg_replace('/\{#[A-Z0-9._]+\}/', '', $snmp_oid);

		// Unwrap walk[...], get[...], discovery[...] and take everything inside.
		if (preg_match('/^(?:walk|get|discovery)\[(.*)\]$/is', $snmp_oid, $m)) {
			$snmp_oid = $m[1];
		}

		$out = [];

		foreach (preg_split('/[,\s]+/', $snmp_oid) as $candidate) {
			$candidate = trim($candidate, " \t\"'.,");

			if ($candidate === '') {
				continue;
			}

			if (COid::isNumeric($candidate)) {
				$out[] = rtrim(COid::normalize($candidate), '.');
				continue;
			}

			if (COid::isSymbolic($candidate)) {
				$resolved = $this->mib->resolve($candidate);

				if ($resolved !== null) {
					$out[] = $resolved;
				}
			}
		}

		return array_values(array_unique(array_filter($out)));
	}

	/**
	 * A varbind is covered when a monitored OID is the varbind itself or an ancestor of
	 * it. An item on ifDescr.1 covers only that instance; a discovery rule on the
	 * ifDescr column covers every row.
	 */
	private function coveredBy(string $oid, array $monitored): ?array {
		foreach ($monitored as $rule) {
			if (COid::isChildOf($oid, $rule['oid'])) {
				return $rule;
			}
		}

		return null;
	}
}
