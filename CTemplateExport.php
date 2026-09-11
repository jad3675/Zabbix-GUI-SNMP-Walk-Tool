<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Includes;

/**
 * Build a Zabbix template export document from proposed items and discovery rules.
 *
 * This is the alternative to creating objects through the API, and for most work it is
 * the better one: the result is a file you can read before it exists anywhere, edit,
 * commit, review in a pull request, and import into a different instance. API creation
 * gives you a live object on one server and nothing to diff.
 *
 * The structures fed in here are the same API payloads ObjectCreate would have sent, so
 * the two paths cannot drift apart in what they propose.
 */
final class CTemplateExport {

	private const VALUE_TYPES = [
		ITEM_VALUE_TYPE_FLOAT => 'FLOAT',
		ITEM_VALUE_TYPE_STR => 'CHAR',
		ITEM_VALUE_TYPE_LOG => 'LOG',
		ITEM_VALUE_TYPE_UINT64 => 'UNSIGNED',
		ITEM_VALUE_TYPE_TEXT => 'TEXT'
	];

	private const PREPROC_TYPES = [
		ZBX_PREPROC_MULTIPLIER => 'MULTIPLIER',
		ZBX_PREPROC_DELTA_SPEED => 'CHANGE_PER_SECOND'
	];

	/**
	 * @param array  $items       Item payloads as built for API::Item()->create()
	 * @param array  $rule        Discovery rule payload, or []
	 * @param array  $prototypes  Item prototype payloads
	 * @param array  $valuemaps   name => [value => label]
	 */
	public static function build(string $template_name, string $group_name, array $items,
			array $rule = [], array $prototypes = [], array $valuemaps = [],
			string $description = ''): array {
		$template = [
			'uuid' => CYaml::uuid(),
			'template' => $template_name,
			'name' => $template_name,
			'description' => $description,
			'groups' => [['name' => $group_name]]
		];

		if ($items) {
			$template['items'] = array_map(
				static fn(array $item): array => self::item($item),
				array_values($items)
			);
		}

		if ($rule) {
			$exported = self::item($rule);
			unset($exported['value_type'], $exported['units']);

			if ($prototypes) {
				$exported['item_prototypes'] = array_map(
					static fn(array $prototype): array => self::item($prototype),
					array_values($prototypes)
				);
			}

			$template['discovery_rules'] = [$exported];
		}

		if ($valuemaps) {
			$exported = [];

			foreach ($valuemaps as $name => $mappings) {
				$pairs = [];

				foreach ($mappings as $value => $label) {
					$pairs[] = ['value' => (string) $value, 'newvalue' => (string) $label];
				}

				$exported[] = [
					'uuid' => CYaml::uuid(),
					'name' => (string) $name,
					'mappings' => $pairs
				];
			}

			$template['valuemaps'] = $exported;
		}

		return [
			'zabbix_export' => [
				'version' => defined('ZABBIX_EXPORT_VERSION') ? ZABBIX_EXPORT_VERSION : '7.0',
				'template_groups' => [[
					'uuid' => CYaml::uuid(),
					'name' => $group_name
				]],
				'templates' => [$template]
			]
		];
	}

	public static function toYaml(array $document): string {
		return CYaml::dump($document);
	}

	/**
	 * One item, prototype or discovery rule.
	 *
	 * Field order follows what Zabbix writes, so an exported file sits next to an
	 * official template without the diff being all reordering.
	 */
	private static function item(array $item): array {
		$exported = [
			'uuid' => CYaml::uuid(),
			'name' => (string) ($item['name'] ?? ''),
			'type' => 'SNMP_AGENT',
			'snmp_oid' => (string) ($item['snmp_oid'] ?? ''),
			'key' => (string) ($item['key_'] ?? ''),
			'delay' => (string) ($item['delay'] ?? '1m')
		];

		if (array_key_exists('value_type', $item)) {
			$exported['value_type'] = self::VALUE_TYPES[(int) $item['value_type']] ?? 'TEXT';
		}

		if (($item['units'] ?? '') !== '') {
			$exported['units'] = (string) $item['units'];
		}

		if (($item['description'] ?? '') !== '') {
			$exported['description'] = (string) $item['description'];
		}

		// A disabled object is exported with an explicit status; enabled is the default
		// and Zabbix omits it.
		if ((int) ($item['status'] ?? ITEM_STATUS_ACTIVE) === ITEM_STATUS_DISABLED) {
			$exported['status'] = 'DISABLED';
		}

		if (($item['_valuemap'] ?? '') !== '') {
			$exported['valuemap'] = ['name' => (string) $item['_valuemap']];
		}

		if ($item['preprocessing'] ?? []) {
			$steps = [];

			foreach ($item['preprocessing'] as $step) {
				$name = self::PREPROC_TYPES[(int) $step['type']] ?? null;

				if ($name === null) {
					continue;
				}

				$exported_step = ['type' => $name];

				// CHANGE_PER_SECOND takes no parameters, and writing an empty one makes
				// the import complain.
				if (($step['params'] ?? '') !== '') {
					$exported_step['parameters'] = [(string) $step['params']];
				}

				$steps[] = $exported_step;
			}

			if ($steps) {
				$exported['preprocessing'] = $steps;
			}
		}

		if ($item['tags'] ?? []) {
			$exported['tags'] = array_values($item['tags']);
		}

		return $exported;
	}
}
