<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Includes;

/**
 * A small YAML emitter for Zabbix export documents.
 *
 * Zabbix's own export writer is not reachable from a module in a way that is stable
 * across releases, and the structure emitted here is narrow and fully controlled: nested
 * maps, lists, and scalar strings. So this covers that and nothing else. It is not a
 * general YAML implementation and should not be used as one.
 *
 * The output is matched to what Zabbix itself writes, because the point of exporting is
 * that the file diffs cleanly against templates from other sources.
 */
final class CYaml {

	/**
	 * Words YAML would read as something other than a string.
	 */
	private const RESERVED = ['true', 'false', 'null', 'yes', 'no', 'on', 'off', '~'];

	public static function dump(array $data): string {
		return self::map($data, 0);
	}

	private static function map(array $data, int $depth): string {
		$pad = str_repeat('  ', $depth);
		$out = '';

		foreach ($data as $key => $value) {
			if ($value === null || $value === []) {
				continue;
			}

			if (is_array($value) && array_is_list($value)) {
				$out .= $pad.$key.":\n".self::sequence($value, $depth + 1);
			}
			elseif (is_array($value)) {
				$out .= $pad.$key.":\n".self::map($value, $depth + 1);
			}
			else {
				$out .= $pad.$key.': '.self::scalar((string) $value, $depth + 1)."\n";
			}
		}

		return $out;
	}

	private static function sequence(array $items, int $depth): string {
		$pad = str_repeat('  ', $depth);
		$out = '';

		foreach ($items as $item) {
			if (is_array($item)) {
				// A list entry that is a map hangs its first key off the dash, and the
				// rest line up under it.
				$block = array_is_list($item)
					? self::sequence($item, $depth + 1)
					: self::map($item, $depth + 1);
				$block = ltrim($block, ' ');
				$out .= $pad.'- '.$block;
			}
			else {
				$out .= $pad.'- '.self::scalar((string) $item, $depth + 1)."\n";
			}
		}

		return $out;
	}

	/**
	 * Quote only what needs quoting, so the result reads and diffs like a hand-written
	 * template rather than a machine dump.
	 */
	private static function scalar(string $value, int $depth): string {
		if ($value === '') {
			return "''";
		}

		if (str_contains($value, "\n")) {
			return self::block($value, $depth);
		}

		$plain = preg_match('/^[A-Za-z0-9_.\/-]+$/', $value) === 1
			&& !in_array(strtolower($value), self::RESERVED, true)
			&& !is_numeric($value);

		return $plain ? $value : "'".str_replace("'", "''", $value)."'";
	}

	/**
	 * Multi-line strings become literal blocks, which is how Zabbix writes MIB
	 * descriptions and the only form that survives a round trip unchanged.
	 */
	private static function block(string $value, int $depth): string {
		$pad = str_repeat('  ', $depth);
		$lines = [];

		foreach (explode("\n", rtrim($value, "\n")) as $line) {
			$lines[] = $line === '' ? '' : $pad.$line;
		}

		return "|\n".implode("\n", $lines);
	}

	/**
	 * A Zabbix export UUID: 32 lowercase hex characters, unique per object.
	 */
	public static function uuid(): string {
		return bin2hex(random_bytes(16));
	}
}
