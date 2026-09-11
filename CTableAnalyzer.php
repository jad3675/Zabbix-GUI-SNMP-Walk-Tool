<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Includes;

/**
 * Separate a walk into scalars and conceptual tables.
 *
 * Two strategies, in order of confidence:
 *
 *  1. With MIBs loaded, an object whose parent carries an INDEX or AUGMENTS clause is
 *     a table column by definition, and the INDEX clause tells us how many trailing
 *     components make up the index. This is exact, including multi-component indexes
 *     like ipNetToMediaEntry's { ifIndex, ipAddress }.
 *
 *  2. Without MIBs, group by the OID with its last component removed. A group with
 *     more than one distinct trailing component is a column; sibling columns sharing
 *     an index set form a table. This gets ifTable and entPhysicalTable right and gets
 *     multi-component indexes wrong, so it is reported as low confidence.
 *
 * Anything ending in .0, or alone under its parent, is a scalar.
 */
final class CTableAnalyzer {

	private CMibLookup $mib;

	public function __construct(CMibLookup $mib) {
		$this->mib = $mib;
	}

	/**
	 * @param CVarbind[] $varbinds
	 *
	 * @return array  ['tables' => array[], 'scalars' => array[]]
	 */
	public function analyze(array $varbinds): array {
		$columns = [];
		$scalars = [];

		foreach ($varbinds as $vb) {
			$placement = $this->place($vb);

			if ($placement === null) {
				$scalars[] = [
					'oid' => $vb->oid,
					'name' => $vb->name,
					'type' => $vb->type,
					'value' => $vb->value
				];
				continue;
			}

			[$column_oid, $index, $confidence] = $placement;

			if (!array_key_exists($column_oid, $columns)) {
				$columns[$column_oid] = [
					'oid' => $column_oid,
					'confidence' => $confidence,
					'cells' => []
				];
			}

			$columns[$column_oid]['cells'][$index] = $vb;

			if ($confidence === 'low') {
				$columns[$column_oid]['confidence'] = 'low';
			}
		}

		// A "column" with exactly one cell whose index is 0 is really a scalar.
		foreach ($columns as $oid => $column) {
			if (count($column['cells']) === 1 && (string) array_key_first($column['cells']) === '0') {
				$vb = reset($column['cells']);
				$scalars[] = [
					'oid' => $vb->oid,
					'name' => $vb->name,
					'type' => $vb->type,
					'value' => $vb->value
				];
				unset($columns[$oid]);
			}
		}

		usort($scalars, static fn(array $a, array $b): int => COid::compare($a['oid'], $b['oid']));

		return [
			'tables' => $this->group($columns),
			'scalars' => $scalars
		];
	}

	/**
	 * @return array|null  [column_oid, index, confidence] or null for a scalar.
	 */
	private function place(CVarbind $varbind): ?array {
		$hit = $this->mib->lookup($varbind->oid);

		if ($hit !== null && $hit['index'] !== '') {
			$parent = $this->mib->detail(COid::parent($hit['oid']));

			if (!empty($parent['is_entry'])) {
				return [$hit['oid'], $hit['index'], 'high'];
			}
		}

		if ($hit !== null && $hit['index'] === '0') {
			return null;
		}

		$index = COid::suffix($varbind->oid);

		if ($index === '0') {
			return null;
		}

		$column_oid = COid::parent($varbind->oid);

		return $column_oid === '' ? null : [$column_oid, $index, 'low'];
	}

	/**
	 * Columns sharing a parent OID belong to the same conceptual row.
	 */
	private function group(array $columns): array {
		$tables = [];

		foreach ($columns as $column) {
			$entry_oid = COid::parent($column['oid']);
			$tables[$entry_oid][] = $column;
		}

		$out = [];

		foreach ($tables as $entry_oid => $entry_columns) {
			$indexes = [];

			foreach ($entry_columns as $column) {
				foreach (array_keys($column['cells']) as $index) {
					$indexes[(string) $index] = true;
				}
			}

			// PHP turns numeric-string array keys back into integers, so the index
			// set has to be cast back before it is compared or serialised. A
			// multi-component index like "2.10.0.0.1" is always a string; a
			// single-component one must be too, or the two shapes disagree.
			$indexes = array_map('strval', array_keys($indexes));
			usort($indexes, [COid::class, 'compare']);

			$entry_detail = $this->mib->detail((string) $entry_oid);
			$table_detail = $this->mib->detail(COid::parent((string) $entry_oid));

			$rendered_columns = [];

			foreach ($entry_columns as $column) {
				$detail = $this->mib->detail($column['oid']);
				$sample = reset($column['cells']);

				$rendered_columns[] = [
					'oid' => $column['oid'],
					'name' => $detail['name'] ?? null,
					'syntax' => $detail['syntax'] ?? null,
					'units' => $detail['units'] ?? null,
					'access' => $detail['access'] ?? null,
					'description' => $detail['description'] ?? null,
					'enum' => $detail['enum'] ?? [],
					'type' => $sample instanceof CVarbind ? $sample->type : '',
					'confidence' => $column['confidence'],
					'filled' => count($column['cells']),
					'values' => self::stringKeys(array_map(
						static fn(CVarbind $vb): string => $vb->value,
						$column['cells']
					))
				];
			}

			usort($rendered_columns, static fn(array $a, array $b): int => COid::compare($a['oid'], $b['oid']));

			$confidence = 'high';

			foreach ($rendered_columns as $column) {
				if ($column['confidence'] === 'low') {
					$confidence = 'low';
					break;
				}
			}

			// A single column with a single row is not a table, it is a stray scalar
			// that happened not to end in .0.
			if (count($rendered_columns) === 1 && count($indexes) === 1) {
				continue;
			}

			$out[] = [
				'entry_oid' => (string) $entry_oid,
				'table_oid' => COid::parent((string) $entry_oid),
				'name' => $table_detail['name'] ?? ($entry_detail['name'] ?? null),
				'entry_name' => $entry_detail['name'] ?? null,
				'index_objects' => $entry_detail['index'] ?? [],
				'description' => $table_detail['description'] ?? null,
				'confidence' => $confidence,
				'indexes' => $indexes,
				'row_count' => count($indexes),
				'columns' => $rendered_columns,
				'lld' => $this->suggestLld($rendered_columns, $entry_detail)
			];
		}

		usort($out, static fn(array $a, array $b): int => COid::compare($a['entry_oid'], $b['entry_oid']));

		return $out;
	}

	/**
	 * Force array keys back to strings so the index type is consistent everywhere,
	 * including once the structure has been through json_encode.
	 */
	private static function stringKeys(array $values): array {
		$out = [];

		foreach ($values as $key => $value) {
			$out[(string) $key] = $value;
		}

		return $out;
	}

	/**
	 * Propose an LLD rule for a table: which column makes the best {#NAME} macro, and
	 * which columns are worth an item prototype.
	 */
	private function suggestLld(array $columns, array $entry_detail): array {
		$label_column = null;
		$best_score = -1;

		foreach ($columns as $column) {
			$name = strtolower((string) ($column['name'] ?? ''));
			$score = 0;

			if (preg_match('/(descr|name|alias|label|ident)$/', $name)) {
				$score += 10;
			}

			if (in_array($column['type'], ['STRING'], true)) {
				$score += 4;
			}

			// A column whose values are all distinct identifies a row; one where they
			// repeat does not.
			$values = $column['values'];

			if ($values && count(array_unique($values)) === count($values)) {
				$score += 3;
			}

			if (($column['access'] ?? '') === 'read-only' && $score > 0) {
				$score += 1;
			}

			if ($score > $best_score) {
				$best_score = $score;
				$label_column = $column;
			}
		}

		$metrics = [];

		// The index column duplicates {#SNMPINDEX}. Polling ifIndex every minute to be
		// told it is still 12 is not monitoring, and the stock templates do not do it.
		$index_names = array_map('strtolower', $entry_detail['index'] ?? []);

		foreach ($columns as $column) {
			if (!in_array($column['type'], ['Counter32', 'Counter64', 'Gauge32', 'INTEGER',
					'UInteger32', 'Timeticks'], true)) {
				continue;
			}

			if (in_array(strtolower((string) ($column['name'] ?? '')), $index_names, true)) {
				continue;
			}

			$metrics[] = ['oid' => $column['oid'], 'name' => $column['name'], 'type' => $column['type']];
		}

		return [
			'label_oid' => $label_column['oid'] ?? null,
			'label_name' => $label_column['name'] ?? null,
			'label_macro' => '{#'.strtoupper(preg_replace('/[^A-Za-z0-9]/', '',
				(string) ($label_column['name'] ?? 'SNMPVALUE'))).'}',
			'index_macro' => '{#SNMPINDEX}',
			'index_objects' => $entry_detail['index'] ?? [],
			'metric_columns' => $metrics,
			'worth_lld' => count($columns) > 1 && $best_score > 0
		];
	}
}
