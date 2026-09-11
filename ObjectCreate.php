<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Actions;

use API;
use Modules\SnmpWalk\Includes\CHostContext;
use Modules\SnmpWalk\Includes\COid;
use Modules\SnmpWalk\Includes\CTemplateExport;
use Modules\SnmpWalk\Includes\CTypeMapper;
use Modules\SnmpWalk\Includes\CVarbind;
use Modules\SnmpWalk\Includes\CWalkService;

/**
 * Turn a row of a walk into a real item, or a detected table into a discovery rule with
 * prototypes.
 *
 * Two modes. "preview" returns the proposed configuration and the reasoning behind
 * every guess, without touching anything; "create" writes it. Nothing is ever created
 * without a preview having been shown first, because a wrong value type on a counter is
 * much more annoying to fix after history exists than before.
 */
class ObjectCreate extends CWalkAction {

	protected function checkInput(): bool {
		return $this->validate([
			'hostid' => 'required|db hosts.hostid',
			'interfaceid' => 'db interface.interfaceid',
			'mode' => 'required|in preview,create,export',
			'overrides' => 'string',
			'rule' => 'string',
			'export_name' => 'string',
			'export_group' => 'string',
			'kind' => 'required|in item,discovery',
			'oid' => 'string',
			'oids' => 'array',
			'types' => 'array',
			'values' => 'array',
			'index' => 'string',
			'type' => 'string',
			'value' => 'string',
			'target' => 'in host,template',
			'templateid' => 'db hosts.hostid',
			'template_name' => 'string',
			'groupid' => 'db hstgrp.groupid',
			'label_oid' => 'string',
			'label_macro' => 'string',
			'columns' => 'array',
			'enabled' => 'in 0,1'
		]);
	}

	protected function checkPermissions(): bool {
		// Write access is checked against the actual target in doAction(), because the
		// target may be a template the user can edit on a host they can only read.
		// Failing here would report "access denied" for the wrong object.
		return parent::checkPermissions();
	}

	protected function doAction(): void {
		$this->guard(function (): void {
			$context = CHostContext::load($this->getInput('hostid'), $this->getInput('interfaceid', ''));

			if ($this->getInput('kind') === 'item' && $this->hasInput('oids')) {
				$this->items($context);

				return;
			}

			$oid = COid::normalize($this->getInput('oid', ''));

			if (!COid::isNumeric($oid)) {
				$this->fail(_s('"%1$s" is not a valid OID.', $this->getInput('oid', '')));

				return;
			}

			if ($this->getInput('kind') === 'item') {
				$this->items($context, [$oid], [$this->getInput('type', '')],
					[$this->getInput('value', '')]
				);
			}
			else {
				$this->discovery($context, $oid);
			}
		});
	}

	private function exportName(): string {
		$name = trim($this->getInput('export_name', ''));

		return $name === '' ? _('Walked device') : mb_substr($name, 0, 128);
	}

	/**
	 * Emit a Zabbix template export document instead of creating anything.
	 *
	 * This is the path that makes the output portable: a file you can read before it
	 * exists anywhere, edit, commit, review, and import into a different instance. API
	 * creation gives you a live object on one server and nothing to diff.
	 */
	private function exportDocument(array $items, array $rule, array $prototypes,
			array $valuemaps, array $rejected): void {
		$maps = [];

		foreach ($valuemaps as $key => $map) {
			if (is_array($map) && array_key_exists('mappings', $map)) {
				$maps[(string) $map['name']] = $map['mappings'];
			}
			elseif (is_array($map)) {
				$maps[(string) $key] = $map;
			}
		}

		// Link each item to its value map by name, the way an export references one.
		foreach ($items as &$item) {
			$name = $valuemaps[$item['key_']]['name'] ?? null;

			if ($name !== null) {
				$item['_valuemap'] = $name;
			}
		}
		unset($item);

		$group = trim($this->getInput('export_group', '')) ?: 'Templates/Network devices';

		$document = CTemplateExport::build(
			$this->exportName(),
			$group,
			$items,
			$rule,
			$prototypes,
			$maps,
			_s('Generated from an SNMP walk on %1$s.', zbx_date2str(DATE_TIME_FORMAT, time()))
		);

		$this->log('export', [
			'name' => $this->exportName(),
			'items' => count($items),
			'prototypes' => count($prototypes)
		]);

		$this->json([
			'mode' => 'export',
			'filename' => preg_replace('/[^A-Za-z0-9._-]+/', '_', $this->exportName()).'.yaml',
			'yaml' => CTemplateExport::toYaml($document),
			'counts' => [
				'items' => count($items),
				'prototypes' => count($prototypes),
				'valuemaps' => count($maps)
			],
			'rejected' => $rejected
		]);
	}

	/**
	 * Per-object edits made in the preview, keyed by OID.
	 *
	 * Name, interval and units are trivially changeable after creation. The key and the
	 * value type are not: changing a key orphans history, and changing a value type
	 * after history exists splits the data across tables. Those are exactly the two the
	 * module was guessing with no way to intervene, which is backwards.
	 */
	private function overrides(): array {
		$raw = trim($this->getInput('overrides', ''));

		if ($raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);

		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * Apply an override to a proposed object, ignoring anything not offered for editing.
	 */
	private static function applyOverride(array $object, array $override): array {
		foreach (['name', 'key_', 'delay', 'units'] as $field) {
			if (array_key_exists($field, $override) && is_string($override[$field])
					&& trim($override[$field]) !== '') {
				$object[$field] = trim($override[$field]);
			}
		}

		if (array_key_exists('value_type', $override)
				&& array_key_exists((int) $override['value_type'], [
					ITEM_VALUE_TYPE_FLOAT => 1, ITEM_VALUE_TYPE_STR => 1,
					ITEM_VALUE_TYPE_LOG => 1, ITEM_VALUE_TYPE_UINT64 => 1,
					ITEM_VALUE_TYPE_TEXT => 1
				])) {
			$object['value_type'] = (int) $override['value_type'];
		}

		return $object;
	}

	/**
	 * Resolve where the objects are going.
	 *
	 * A host is the quick answer and the wrong one for anything you intend to keep:
	 * host items do not inherit, do not reach the other forty devices of that model,
	 * and drift. A template is the answer that survives, so it can be picked here, and
	 * created here if it does not exist yet.
	 *
	 * @return array  ['id' => string, 'is_template' => bool, 'name' => string, 'interfaceid' => ?string]
	 */
	private function resolveTarget(CHostContext $context): array {
		if ($this->getInput('target', 'host') !== 'template') {
			if (!API::Host()->get(['output' => [], 'hostids' => $context->hostid,
					'editable' => true, 'countOutput' => true])) {
				throw new \RuntimeException(_('You do not have permission to change this host.'));
			}

			return [
				'id' => $context->hostid,
				'is_template' => false,
				'name' => $context->name,
				// Host items must name an interface. Template items must not.
				'interfaceid' => $context->interfaceid
			];
		}

		$templateid = $this->getInput('templateid', '');
		$name = trim($this->getInput('template_name', ''));

		if ($templateid !== '') {
			$templates = API::Template()->get([
				'output' => ['templateid', 'name'],
				'templateids' => $templateid,
				'editable' => true
			]);

			if (!$templates) {
				throw new \RuntimeException(_('You do not have permission to change that template, or it does not exist.'));
			}

			$template = reset($templates);

			return [
				'id' => $template['templateid'],
				'is_template' => true,
				'name' => $template['name'],
				'interfaceid' => null
			];
		}

		if ($name === '') {
			throw new \RuntimeException(_('Choose a template, or give a name for a new one.'));
		}

		$groupid = $this->getInput('groupid', '');

		if ($groupid === '') {
			throw new \RuntimeException(_('A new template needs a template group.'));
		}

		if ($this->getInput('mode') === 'preview') {
			return [
				'id' => '',
				'is_template' => true,
				'name' => $name,
				'interfaceid' => null,
				'will_create' => true
			];
		}

		$created = API::Template()->create([
			'host' => $name,
			'groups' => [['groupid' => $groupid]]
		]);

		$new_id = $created['templateids'][0] ?? null;

		if ($new_id === null) {
			throw new \RuntimeException(_('The template was not created.'));
		}

		$this->log('template.create', ['name' => $name, 'templateid' => $new_id]);

		return [
			'id' => (string) $new_id,
			'is_template' => true,
			'name' => $name,
			'interfaceid' => null,
			'created' => true
		];
	}

	/**
	 * Build one or more items from walk rows.
	 *
	 * Bulk and single go through the same path so a selection of forty rows is
	 * previewed and created exactly the way one row is. Keys are checked for collisions
	 * against each other and against what the target already has, because the failure
	 * mode otherwise is half the selection created and the rest rejected.
	 */
	private function items(CHostContext $context, ?array $oids = null, ?array $types = null,
			?array $values = null): void {
		$oids = $oids ?? array_values((array) $this->getInput('oids', []));
		$types = $types ?? array_values((array) $this->getInput('types', []));
		$values = $values ?? array_values((array) $this->getInput('values', []));

		if (!$oids) {
			$this->fail(_('Nothing selected.'));

			return;
		}

		$export = $this->getInput('mode') === 'export';
		$target = $export
			? ['id' => '', 'is_template' => true, 'interfaceid' => null,
				'name' => $this->exportName()]
			: $this->resolveTarget($context);
		$overrides = $this->overrides();
		$mib = CWalkService::mib();

		$items = [];
		$reasons = [];
		$valuemaps = [];
		$rejected = [];
		$seen = [];

		foreach ($oids as $i => $raw_oid) {
			$oid = COid::normalize((string) $raw_oid);

			if (!COid::isNumeric($oid)) {
				$rejected[] = ['oid' => (string) $raw_oid, 'reason' => _('Not a valid OID.')];
				continue;
			}

			$varbind = new CVarbind($oid, (string) ($types[$i] ?? ''), (string) ($values[$i] ?? ''));
			$mib->annotate([$varbind]);

			$hit = $mib->lookup($oid);
			$detail = $hit !== null ? $mib->detail($hit['oid']) : [];
			$index = $this->instanceIndex($oid, $hit);
			$suggestion = CTypeMapper::suggest($varbind, $detail);

			$key = CTypeMapper::key($varbind, $index === '' ? null : $index);

			if (array_key_exists($key, $seen)) {
				$rejected[] = [
					'oid' => $oid,
					'reason' => _s('Key "%1$s" is already used by .%2$s in this selection.', $key, $seen[$key])
				];
				continue;
			}

			$seen[$key] = $oid;

			$item = [
				'hostid' => $target['id'],
				'type' => ITEM_TYPE_SNMP,
				'name' => CTypeMapper::name($varbind, $index === '' ? null : _s('index %1$s', $index)),
				'key_' => $key,
				'snmp_oid' => 'get['.$oid.']',
				'value_type' => $suggestion['value_type'],
				'delay' => $suggestion['delay'],
				'units' => $suggestion['units'],
				'status' => (int) $this->getInput('enabled', 1) === 1
					? ITEM_STATUS_ACTIVE
					: ITEM_STATUS_DISABLED,
				'description' => (string) ($detail['description'] ?? '')
			];

			if ($target['interfaceid'] !== null) {
				$item['interfaceid'] = $target['interfaceid'];
			}

			if ($suggestion['preprocessing']) {
				$item['preprocessing'] = $suggestion['preprocessing'];
			}

			$item['tags'] = CTypeMapper::tags($varbind, $detail);

			$item = self::applyOverride($item, $overrides[$oid] ?? []);

			// A renamed key has to be re-checked, or two rows edited to the same key
			// sail through and the API rejects the batch.
			if ($item['key_'] !== $key) {
				if (array_key_exists($item['key_'], $seen)) {
					$rejected[] = [
						'oid' => $oid,
						'reason' => _s('Key "%1$s" is already used by .%2$s in this selection.',
							$item['key_'], $seen[$item['key_']]
						)
					];
					continue;
				}

				unset($seen[$key]);
				$seen[$item['key_']] = $oid;
				$key = $item['key_'];
			}

			$items[] = $item;
			$valuemaps[$key] = $suggestion['valuemap'] === null
				? null
				: ['name' => (string) ($detail['name'] ?? $oid), 'mappings' => $suggestion['valuemap']];

			if (count($oids) === 1) {
				$reasons = $suggestion['reasons'];
			}
		}

		$mib->persist();

		if (!$items) {
			$this->fail($rejected
				? $rejected[0]['reason']
				: _('None of the selected rows could be turned into an item.')
			);

			return;
		}

		if ($export) {
			$this->exportDocument($items, [], [], $valuemaps, $rejected);

			return;
		}

		// A key that already exists on the target is rejected by the API with a message
		// that does not say which of forty items caused it. Check first.
		if ($target['id'] !== '') {
			$existing = API::Item()->get([
				'output' => ['key_'],
				'hostids' => $target['id'],
				'filter' => ['key_' => array_column($items, 'key_')],
				'webitems' => true
			]);

			$taken = array_flip(array_column($existing, 'key_'));

			foreach ($items as $i => $item) {
				if (array_key_exists($item['key_'], $taken)) {
					$rejected[] = [
						'oid' => $item['snmp_oid'],
						'reason' => _s('Key "%1$s" already exists on %2$s.', $item['key_'], $target['name'])
					];
					unset($items[$i]);
				}
			}

			$items = array_values($items);
		}

		if ($this->getInput('mode') === 'preview') {
			$this->json([
				'mode' => 'preview',
				'kind' => 'item',
				'target' => [
					'name' => $target['name'],
					'is_template' => $target['is_template'],
					'will_create' => (bool) ($target['will_create'] ?? false)
				],
				'items' => $items,
				'item' => $items[0] ?? null,
				'valuemap' => $valuemaps[$items[0]['key_'] ?? ''] ?? null,
				'reasons' => $reasons,
				'rejected' => $rejected
			]);

			return;
		}

		if (!$items) {
			$this->fail(_('Every selected row collided with something that already exists.'));

			return;
		}

		foreach ($items as &$item) {
			$valuemap = $valuemaps[$item['key_']] ?? null;

			if ($valuemap !== null) {
				$valuemapid = $this->ensureValueMap($target['id'], $valuemap['name'], $valuemap['mappings']);

				if ($valuemapid !== null) {
					$item['valuemapid'] = $valuemapid;
				}
			}
		}
		unset($item);

		$result = API::Item()->create($items);

		$this->log('item.create', [
			'target' => $target['id'],
			'is_template' => $target['is_template'] ? 'yes' : 'no',
			'count' => count($items)
		]);

		$this->json([
			'mode' => 'create',
			'kind' => 'item',
			'created' => count($result['itemids'] ?? []),
			'rejected' => $rejected,
			'target' => [
				'id' => $target['id'],
				'name' => $target['name'],
				'is_template' => $target['is_template'],
				'created' => (bool) ($target['created'] ?? false)
			],
			'key' => $items[0]['key_'] ?? ''
		]);
	}

	/**
	 * The trailing instance for an item key.
	 *
	 * Without it every row of a column reduces to the same key, so ifDescr.1 and
	 * ifDescr.7 collide. Scalars ending in .0 keep no suffix, which is what they want.
	 */
	private function instanceIndex(string $oid, ?array $hit): string {
		$index = $this->getInput('index', '');

		if ($index !== '') {
			return $index;
		}

		if ($hit !== null) {
			return $hit['index'] === '0' ? '' : $hit['index'];
		}

		return COid::suffix($oid) === '0' ? '' : COid::suffix($oid);
	}

	/**
	 * Build a discovery rule from a detected table.
	 *
	 * The rule discovers on the chosen label column, which gives both {#SNMPINDEX} and
	 * a readable macro. Prototypes are created disabled by default: linking twenty
	 * prototypes against a 400-port chassis and enabling them in the same second is a
	 * good way to find out what your poller capacity really is.
	 */
	private function discovery(CHostContext $context, string $entry_oid): void {
		$mib = CWalkService::mib();
		$label_oid = COid::normalize($this->getInput('label_oid', ''));

		if (!COid::isNumeric($label_oid)) {
			$this->fail(_('Choose a column to discover on.'));

			return;
		}

		$macro = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $this->getInput('label_macro', 'SNMPVALUE')));
		$macro = $macro === '' ? 'SNMPVALUE' : $macro;

		$overrides = $this->overrides();
		$rule_override = json_decode(trim($this->getInput('rule', '')) ?: '[]', true);
		$rule_override = is_array($rule_override) ? $rule_override : [];

		$entry_detail = $mib->detail($entry_oid);
		$table_detail = $mib->detail(COid::parent($entry_oid));
		$table_name = $table_detail['name'] ?? ($entry_detail['name'] ?? '.'.$entry_oid);

		$export = $this->getInput('mode') === 'export';
		$target = $export
			? ['id' => '', 'is_template' => true, 'interfaceid' => null,
				'name' => $this->exportName()]
			: $this->resolveTarget($context);

		$rule = [
			'hostid' => $target['id'],
			'type' => ITEM_TYPE_SNMP,
			'name' => _s('%1$s discovery', $table_name),
			'key_' => preg_replace('/[^A-Za-z0-9._-]/', '', $table_name).'.discovery',
			'snmp_oid' => 'discovery[{#'.$macro.'},'.$label_oid.']',
			'delay' => '1h',
			// The rule follows the same switch as its prototypes. Creating an enabled
			// rule with disabled prototypes still runs discovery on every interval and
			// fills the database with rows nobody asked for yet.
			'status' => (int) $this->getInput('enabled', 0) === 1
				? ITEM_STATUS_ACTIVE
				: ITEM_STATUS_DISABLED
		];

		$rule = self::applyOverride($rule, $rule_override);

		if ($target['interfaceid'] !== null) {
			$rule['interfaceid'] = $target['interfaceid'];
		}

		$prototypes = [];

		foreach ((array) $this->getInput('columns', []) as $column_oid) {
			$column_oid = COid::normalize((string) $column_oid);

			if (!COid::isNumeric($column_oid)) {
				continue;
			}

			$column_detail = $mib->detail($column_oid);
			$sample = new CVarbind($column_oid.'.1', $this->columnType($column_detail), '');
			$mib->annotate([$sample]);
			$suggestion = CTypeMapper::suggest($sample, $column_detail);

			$name = $column_detail['name'] ?? '.'.$column_oid;

			$prototype = [
				'type' => ITEM_TYPE_SNMP,
				'name' => '{#'.$macro.'}: '.$name,
				'key_' => preg_replace('/[^A-Za-z0-9._-]/', '', $name).'[{#SNMPINDEX}]',
				'snmp_oid' => 'get['.$column_oid.'.{#SNMPINDEX}]',
				'value_type' => $suggestion['value_type'],
				'delay' => $suggestion['delay'],
				'units' => $suggestion['units'],
				'status' => (int) $this->getInput('enabled', 0) === 1
					? ITEM_STATUS_ACTIVE
					: ITEM_STATUS_DISABLED,
				'description' => (string) ($column_detail['description'] ?? ''),
				'_reasons' => $suggestion['reasons'],
				'_enum' => $suggestion['valuemap']
			];

			if ($target['interfaceid'] !== null) {
				$prototype['interfaceid'] = $target['interfaceid'];
			}

			if ($suggestion['preprocessing']) {
				$prototype['preprocessing'] = $suggestion['preprocessing'];
			}

			$prototype['tags'] = CTypeMapper::tags($sample, $column_detail, '{#'.$macro.'}');
			$prototype = self::applyOverride($prototype, $overrides[$column_oid] ?? []);

			if ($suggestion['valuemap'] !== null) {
				$prototype['_valuemap'] = (string) ($column_detail['name'] ?? $column_oid);
			}

			$prototypes[] = $prototype;
		}

		$mib->persist();

		if ($export) {
			$maps = [];

			foreach ($prototypes as $prototype) {
				if (($prototype['_enum'] ?? null) !== null) {
					$maps[(string) $prototype['_valuemap']] = $prototype['_enum'];
				}
			}

			foreach ($prototypes as &$prototype) {
				unset($prototype['_reasons'], $prototype['_enum']);
			}
			unset($prototype);

			$this->exportDocument([], $rule, $prototypes, $maps, []);

			return;
		}

		if ($this->getInput('mode') === 'preview') {
			$this->json([
				'mode' => 'preview',
				'kind' => 'discovery',
				'target' => [
					'name' => $target['name'],
					'is_template' => $target['is_template'],
					'will_create' => (bool) ($target['will_create'] ?? false)
				],
				'rule' => $rule,
				'prototypes' => $prototypes
			]);

			return;
		}

		if (!$prototypes) {
			$this->fail(_('Select at least one column to create a prototype for.'));

			return;
		}

		$created = API::DiscoveryRule()->create($rule);
		$ruleid = $created['itemids'][0] ?? null;

		if ($ruleid === null) {
			$this->fail(_('The discovery rule was not created.'));

			return;
		}

		$payload = [];

		foreach ($prototypes as $prototype) {
			$enum = $prototype['_enum'];
			unset($prototype['_reasons'], $prototype['_enum']);

			$prototype['hostid'] = $target['id'];
			$prototype['ruleid'] = $ruleid;

			if ($enum !== null) {
				$valuemapid = $this->ensureValueMap($target['id'], $prototype['name'], $enum);

				if ($valuemapid !== null) {
					$prototype['valuemapid'] = $valuemapid;
				}
			}

			$payload[] = $prototype;
		}

		API::ItemPrototype()->create($payload);

		$this->log('discoveryrule.create', [
			'target' => $target['id'],
			'entry' => $entry_oid,
			'prototypes' => count($payload)
		]);

		$this->json([
			'mode' => 'create',
			'kind' => 'discovery',
			'ruleid' => $ruleid,
			'prototypes' => count($payload),
			'target' => [
				'id' => $target['id'],
				'name' => $target['name'],
				'is_template' => $target['is_template'],
				'created' => (bool) ($target['created'] ?? false)
			]
		]);
	}

	private function columnType(array $detail): string {
		$syntax = strtolower((string) ($detail['syntax'] ?? ''));

		foreach (['counter64' => 'Counter64', 'counter32' => 'Counter32', 'counter' => 'Counter32',
				'gauge' => 'Gauge32', 'timeticks' => 'Timeticks', 'unsigned32' => 'Gauge32',
				'integer' => 'INTEGER', 'ipaddress' => 'IpAddress', 'object identifier' => 'OID'] as $needle => $type) {
			if (str_contains($syntax, $needle)) {
				return $type;
			}
		}

		return 'STRING';
	}

	/**
	 * Reuse a value map with the same name on the host rather than creating a duplicate
	 * on every item built from the same enumeration.
	 */
	private function ensureValueMap(string $hostid, string $name, array $enum): ?string {
		if ($hostid === '') {
			return null;
		}

		$name = mb_substr(trim($name), 0, 64);

		if ($name === '') {
			return null;
		}

		$existing = API::ValueMap()->get([
			'output' => ['valuemapid'],
			'hostids' => $hostid,
			'filter' => ['name' => $name]
		]);

		if ($existing) {
			return $existing[0]['valuemapid'];
		}

		$mappings = [];

		foreach ($enum as $value => $label) {
			$mappings[] = ['value' => (string) $value, 'newvalue' => (string) $label];
		}

		$created = API::ValueMap()->create([
			'hostid' => $hostid,
			'name' => $name,
			'mappings' => $mappings
		]);

		return $created['valuemapids'][0] ?? null;
	}
}
