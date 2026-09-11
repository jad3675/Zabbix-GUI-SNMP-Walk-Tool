<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Includes;

/**
 * OID validation, normalisation and comparison.
 *
 * Everything inside the module stores OIDs in dotted numeric form WITHOUT a leading
 * dot ("1.3.6.1.2.1.1.1.0"). Translation to a symbolic form happens at display time
 * only, so a walk stays useful when a MIB is missing.
 */
final class COid {

	public const MAX_LENGTH = 512;

	/**
	 * Strict numeric OID check. This is the only gate between user input and any
	 * SNMP call or shelled-out snmptranslate, so it is deliberately narrow.
	 */
	public static function isNumeric(string $oid): bool {
		if ($oid === '' || strlen($oid) > self::MAX_LENGTH) {
			return false;
		}

		return (bool) preg_match('/^\.?\d+(\.\d+)*$/', $oid);
	}

	/**
	 * Symbolic OID as accepted in the console input box, e.g. "IF-MIB::ifTable" or
	 * "ifDescr.1". Resolved through the MIB index before use; never passed to a shell.
	 */
	public static function isSymbolic(string $oid): bool {
		if ($oid === '' || strlen($oid) > self::MAX_LENGTH) {
			return false;
		}

		return (bool) preg_match('/^[A-Za-z][A-Za-z0-9-]*(::[A-Za-z][A-Za-z0-9-]*)?(\.\d+)*$/', $oid);
	}

	public static function normalize(string $oid): string {
		return ltrim(trim($oid), '.');
	}

	/**
	 * @return int[]
	 */
	public static function toArray(string $oid): array {
		$oid = self::normalize($oid);

		return $oid === '' ? [] : array_map('intval', explode('.', $oid));
	}

	/**
	 * True when $oid is $prefix or sits below it. Component-aware, so "1.3.6.1.2.1.11"
	 * is not treated as a child of "1.3.6.1.2.1.1".
	 */
	public static function isChildOf(string $oid, string $prefix): bool {
		$prefix = self::normalize($prefix);

		if ($prefix === '') {
			return true;
		}

		$oid = self::normalize($oid);

		return $oid === $prefix || str_starts_with($oid, $prefix.'.');
	}

	/**
	 * Numeric, component-wise comparison. Sorting walk output as strings puts
	 * .10 before .2, which makes tables unreadable.
	 */
	public static function compare(string $a, string $b): int {
		$a = self::toArray($a);
		$b = self::toArray($b);
		$n = min(count($a), count($b));

		for ($i = 0; $i < $n; $i++) {
			if ($a[$i] !== $b[$i]) {
				return $a[$i] <=> $b[$i];
			}
		}

		return count($a) <=> count($b);
	}

	public static function parent(string $oid, int $count = 1): string {
		$parts = self::toArray($oid);

		if (count($parts) <= $count) {
			return '';
		}

		return implode('.', array_slice($parts, 0, count($parts) - $count));
	}

	/**
	 * The trailing $count components, i.e. the table index.
	 */
	public static function suffix(string $oid, int $count = 1): string {
		$parts = self::toArray($oid);

		if (count($parts) < $count) {
			return '';
		}

		return implode('.', array_slice($parts, -$count));
	}

	public static function depth(string $oid): int {
		return count(self::toArray($oid));
	}
}
