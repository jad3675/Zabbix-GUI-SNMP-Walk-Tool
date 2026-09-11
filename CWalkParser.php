<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Includes;

/**
 * Parser for text walk output.
 *
 * Used by the server and script engines, which both hand back net-snmp style text
 * rather than structured varbinds, and by snapshot import. Written to be tolerant:
 * it accepts numeric or symbolic OIDs, with or without a leading dot, and handles the
 * continuation lines net-snmp emits for long Hex-STRING values.
 */
final class CWalkParser {

	/**
	 * Lines that mean "the agent has nothing here" rather than a value.
	 */
	private const NON_VALUES = [
		'No Such Object available on this agent at this OID',
		'No Such Instance currently exists at this OID',
		'No more variables left in this MIB View'
	];

	/**
	 * @return array  ['varbinds' => CVarbind[], 'skipped' => int, 'notices' => string[]]
	 */
	public static function parse(string $text): array {
		$varbinds = [];
		$notices = [];
		$skipped = 0;
		$current = null;

		foreach (preg_split('/\R/', $text) as $line) {
			if (trim($line) === '') {
				continue;
			}

			// Continuation of a multi-line Hex-STRING: indented run of hex pairs.
			if ($current !== null && preg_match('/^\s+((?:[0-9A-Fa-f]{2}[ ]?)+)$/', $line, $m)) {
				$current->value = rtrim($current->value).' '.trim($m[1]);
				continue;
			}

			if (!preg_match('/^\.?([\w:.\-]+?)\s*=\s*(.*)$/', trim($line), $m)) {
				$notices[] = $line;
				continue;
			}

			$oid = $m[1];
			$rest = $m[2];

			$non_value = false;

			foreach (self::NON_VALUES as $marker) {
				if (str_contains($rest, $marker)) {
					$non_value = true;
					break;
				}
			}

			if ($non_value) {
				$skipped++;
				continue;
			}

			if (preg_match('/^([\w\-]+):\s?(.*)$/s', $rest, $vm)) {
				$type = $vm[1];
				$value = $vm[2];
			}
			else {
				$type = '';
				$value = $rest;
			}

			$value = self::unquote(trim($value));

			// Timeticks: (12345678) 1 day, 10:17:36.78  ->  keep the raw tick count,
			// the human form is derivable and the raw number is what an item needs.
			if (strcasecmp($type, 'Timeticks') === 0 && preg_match('/^\((\d+)\)/', $value, $tm)) {
				$value = $tm[1];
			}

			if (!COid::isNumeric($oid)) {
				// Symbolic OID from an engine that had MIBs loaded. Keep it as the name
				// and leave the numeric OID empty for the caller to resolve.
				$vb = new CVarbind('', $type, $value);
				$vb->name = $oid;
			}
			else {
				$vb = new CVarbind($oid, $type, $value);
			}

			$varbinds[] = $vb;
			$current = $vb;
		}

		return [
			'varbinds' => $varbinds,
			'skipped' => $skipped,
			'notices' => $notices
		];
	}

	private static function unquote(string $value): string {
		if (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') {
			return substr($value, 1, -1);
		}

		return $value;
	}

	/**
	 * Render varbinds back to "snmpwalk -On" text. This is the download format that
	 * every other SNMP tool, and the template builder, can consume.
	 *
	 * @param CVarbind[] $varbinds
	 */
	public static function toNumericText(array $varbinds): string {
		$lines = [];

		foreach ($varbinds as $vb) {
			$lines[] = '.'.$vb->oid.' = '.($vb->type === '' ? '' : $vb->type.': ').$vb->value;
		}

		return implode("\n", $lines)."\n";
	}

	/**
	 * Render with symbolic names where the MIB index resolved them.
	 *
	 * @param CVarbind[] $varbinds
	 */
	public static function toTranslatedText(array $varbinds): string {
		$lines = [];

		foreach ($varbinds as $vb) {
			$label = $vb->name !== null
				? ($vb->mib !== null ? $vb->mib.'::'.$vb->name : $vb->name)
				: '.'.$vb->oid;

			$lines[] = $label.' = '.($vb->type === '' ? '' : $vb->type.': ').$vb->value;
		}

		return implode("\n", $lines)."\n";
	}
}
