<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Includes;

/**
 * Compare two walks.
 *
 * The use this earns its keep on is a firmware upgrade: walk before, walk after, and
 * find out whether the vendor quietly moved or dropped an OID a template depends on.
 * It also works across devices, which answers "why does this switch discover 48
 * interfaces and its twin discover none".
 *
 * Values are compared as strings on purpose. A counter that moved is not interesting,
 * so counters and other free-running types can be excluded, but a sysDescr that
 * changed by one character is exactly the thing you are looking for.
 */
final class CWalkDiff {

	/**
	 * Types whose values are expected to change between two reads.
	 */
	private const VOLATILE = ['Counter32', 'Counter64', 'Timeticks'];

	/**
	 * @param CVarbind[] $before
	 * @param CVarbind[] $after
	 * @param bool       $ignore_volatile  Suppress value changes on counters and uptime.
	 */
	public static function compare(array $before, array $after, bool $ignore_volatile = true): array {
		$a = self::index($before);
		$b = self::index($after);

		$added = [];
		$removed = [];
		$changed = [];
		$retyped = [];
		$unchanged = 0;

		foreach ($b as $oid => $vb) {
			if (!array_key_exists($oid, $a)) {
				$added[] = [
					'oid' => $oid,
					'name' => $vb->name,
					'type' => $vb->type,
					'value' => $vb->value
				];
				continue;
			}

			$old = $a[$oid];

			if ($old->type !== $vb->type && $old->type !== '' && $vb->type !== '') {
				$retyped[] = [
					'oid' => $oid,
					'name' => $vb->name,
					'before_type' => $old->type,
					'after_type' => $vb->type,
					'before' => $old->value,
					'after' => $vb->value
				];
				continue;
			}

			if ($old->value === $vb->value) {
				$unchanged++;
				continue;
			}

			if ($ignore_volatile && in_array($vb->type, self::VOLATILE, true)) {
				$unchanged++;
				continue;
			}

			$changed[] = [
				'oid' => $oid,
				'name' => $vb->name,
				'type' => $vb->type,
				'before' => $old->value,
				'after' => $vb->value
			];
		}

		foreach ($a as $oid => $vb) {
			if (!array_key_exists($oid, $b)) {
				$removed[] = [
					'oid' => $oid,
					'name' => $vb->name,
					'type' => $vb->type,
					'value' => $vb->value
				];
			}
		}

		foreach ([&$added, &$removed, &$changed, &$retyped] as &$set) {
			usort($set, static fn(array $x, array $y): int => COid::compare($x['oid'], $y['oid']));
		}
		unset($set);

		return [
			'added' => $added,
			'removed' => $removed,
			'changed' => $changed,
			'retyped' => $retyped,
			'unchanged' => $unchanged,
			'summary' => [
				'added' => count($added),
				'removed' => count($removed),
				'changed' => count($changed),
				'retyped' => count($retyped),
				'unchanged' => $unchanged,
				'before_total' => count($a),
				'after_total' => count($b)
			]
		];
	}

	/**
	 * @param CVarbind[] $varbinds
	 *
	 * @return CVarbind[]  keyed by OID
	 */
	private static function index(array $varbinds): array {
		$out = [];

		foreach ($varbinds as $vb) {
			if ($vb->oid !== '') {
				$out[$vb->oid] = $vb;
			}
		}

		return $out;
	}
}
