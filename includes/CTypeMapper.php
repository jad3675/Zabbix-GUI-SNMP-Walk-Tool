<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Includes;

/**
 * Turn a varbind plus its MIB detail into a proposed Zabbix item.
 *
 * The proposals are meant to be right often enough to save typing, not right always.
 * Everything is shown in a pre-filled form before anything is created, and the
 * reasoning for each choice is returned alongside it so the guess can be argued with.
 */
final class CTypeMapper {

	/**
	 * @return array  value_type, units, delay, preprocessing, valuemap, reasons
	 */
	public static function suggest(CVarbind $varbind, array $detail = []): array {
		$type = $varbind->type;
		$syntax = (string) ($detail['syntax'] ?? '');
		$enum = $detail['enum'] ?? [];
		$units = (string) ($detail['units'] ?? '');

		$out = [
			'value_type' => ITEM_VALUE_TYPE_TEXT,
			'units' => '',
			'delay' => '1m',
			'preprocessing' => [],
			'valuemap' => null,
			'reasons' => []
		];

		switch (true) {
			case strcasecmp($type, 'Counter32') === 0:
			case strcasecmp($type, 'Counter64') === 0:
				$out['value_type'] = ITEM_VALUE_TYPE_UINT64;
				$out['preprocessing'][] = [
					// "Change per second" is the label in the UI; the constant is
					// DELTA_SPEED. Getting this wrong is a fatal, not a wrong value.
					'type' => ZBX_PREPROC_DELTA_SPEED,
					'params' => '',
					'error_handler' => ZBX_PREPROC_FAIL_DEFAULT,
					'error_handler_params' => ''
				];
				$out['reasons'][] = _('Counters only mean something as a rate, so Change per second is applied.');

				// An octet counter rated is bytes per second, but every stock Zabbix
				// network template reports interface throughput in bits, so a module
				// that emits Bps produces numbers that are correct and incomparable
				// with everything next to them.
				if (stripos($varbind->name ?? '', 'octets') !== false) {
					$out['preprocessing'][] = [
						'type' => ZBX_PREPROC_MULTIPLIER,
						'params' => '8',
						'error_handler' => ZBX_PREPROC_FAIL_DEFAULT,
						'error_handler_params' => ''
					];
					$out['units'] = 'bps';
					$out['reasons'][] = _('Octets are multiplied by 8 and reported as bits per second, matching the stock network templates.');
				}
				break;

			case strcasecmp($type, 'Gauge32') === 0:
			case strcasecmp($type, 'UInteger32') === 0:
				$out['value_type'] = ITEM_VALUE_TYPE_UINT64;
				$out['reasons'][] = _('A gauge is an instantaneous unsigned value, stored as-is.');
				break;

			case strcasecmp($type, 'Timeticks') === 0:
				$out['value_type'] = ITEM_VALUE_TYPE_FLOAT;
				$out['units'] = 'uptime';
				$out['preprocessing'][] = [
					'type' => ZBX_PREPROC_MULTIPLIER,
					'params' => '0.01',
					'error_handler' => ZBX_PREPROC_FAIL_DEFAULT,
					'error_handler_params' => ''
				];
				$out['reasons'][] = _('Timeticks are hundredths of a second, so a 0.01 multiplier converts to seconds.');
				break;

			case strcasecmp($type, 'INTEGER') === 0:
				$out['value_type'] = ITEM_VALUE_TYPE_UINT64;

				if (preg_match('/-\d/', $varbind->value) || stripos($syntax, 'Integer32') !== false) {
					$out['value_type'] = ITEM_VALUE_TYPE_FLOAT;
					$out['reasons'][] = _('Integer32 can be negative, so a numeric (float) type avoids losing the sign.');
				}

				if ($enum) {
					$out['valuemap'] = $enum;
					$out['value_type'] = ITEM_VALUE_TYPE_UINT64;
					$out['reasons'][] = _s('The SYNTAX clause enumerates %1$s states, which map straight onto a value map.',
						count($enum)
					);
				}
				break;

			case strcasecmp($type, 'Hex-STRING') === 0:
			case strcasecmp($type, 'BITS') === 0:
				$out['value_type'] = ITEM_VALUE_TYPE_STR;
				$out['reasons'][] = _('Binary data is kept as a character string; decode it with preprocessing if needed.');
				break;

			case strcasecmp($type, 'IpAddress') === 0:
			case strcasecmp($type, 'OID') === 0:
			case strcasecmp($type, 'NetworkAddress') === 0:
				$out['value_type'] = ITEM_VALUE_TYPE_STR;
				break;

			case strcasecmp($type, 'STRING') === 0:
				$out['value_type'] = strlen($varbind->value) > 255
					? ITEM_VALUE_TYPE_TEXT
					: ITEM_VALUE_TYPE_STR;

				if ($out['value_type'] == ITEM_VALUE_TYPE_TEXT) {
					$out['reasons'][] = _('The sampled value is longer than 255 characters, so text rather than character.');
				}

				if (preg_match('/^-?\d+$/', trim($varbind->value))) {
					$out['reasons'][] = _('The value looks numeric even though the agent reports it as a string. Consider a numeric type with Trim preprocessing.');
				}
				break;
		}

		if ($units !== '' && $out['units'] === '') {
			$out['units'] = self::mapUnits($units);

			if ($out['units'] !== '') {
				$out['reasons'][] = _s('Units taken from the MIB UNITS clause ("%1$s").', $units);
			}
		}

		// Anything that describes the device rather than measures it does not need to
		// be polled every minute.
		if (in_array($out['value_type'], [ITEM_VALUE_TYPE_STR, ITEM_VALUE_TYPE_TEXT], true)) {
			$out['delay'] = '1h';
			$out['reasons'][] = _('Descriptive strings rarely change, so an hourly interval is the sane default.');
		}

		return $out;
	}

	/**
	 * MIB UNITS clauses are free text. Map the common ones onto Zabbix unit symbols and
	 * leave anything unrecognised alone rather than guessing.
	 */
	private static function mapUnits(string $units): string {
		$units = strtolower(trim($units));

		$map = [
			'seconds' => 's',
			'second' => 's',
			'milliseconds' => 'ms',
			'centi-seconds' => 's',
			'bytes' => 'B',
			'octets' => 'B',
			'kilobytes' => 'B',
			'bits per second' => 'bps',
			'bits/second' => 'bps',
			'packets' => '',
			'percent' => '%',
			'percentage' => '%',
			'celsius' => 'C',
			'degrees celsius' => 'C',
			'volts' => 'V',
			'amps' => 'A',
			'amperes' => 'A',
			'watts' => 'W',
			'hertz' => 'Hz',
			'rpm' => 'rpm'
		];

		return $map[$units] ?? '';
	}

	/**
	 * Item key for a fixed instance, e.g. "ifDescr[1]".
	 */
	public static function key(CVarbind $varbind, ?string $index = null): string {
		$base = $varbind->name !== null
			? preg_replace('/\.\d+(\.\d+)*$/', '', $varbind->name)
			: 'snmp.'.str_replace('.', '_', $varbind->oid);

		$base = preg_replace('/[^A-Za-z0-9._-]/', '', (string) $base);

		if ($base === '') {
			$base = 'snmp';
		}

		return $index === null || $index === '' ? $base : $base.'['.$index.']';
	}

	/**
	 * Tags for a created item.
	 *
	 * Deliberately factual rather than semantic. The stock templates tag things
	 * "component: network" because a human decided that; nothing here can infer it from
	 * a MIB. What can be stated is which MIB the object came from, which object it is,
	 * and for a prototype which row it belongs to, and those are enough to filter and
	 * group on.
	 */
	public static function tags(CVarbind $varbind, array $detail = [], ?string $index_macro = null): array {
		$tags = [['tag' => 'source', 'value' => 'snmp-walk']];

		if (($detail['mib'] ?? null) !== null) {
			$tags[] = ['tag' => 'mib', 'value' => (string) $detail['mib']];
		}
		elseif ($varbind->mib !== null) {
			$tags[] = ['tag' => 'mib', 'value' => $varbind->mib];
		}

		$object = $detail['name'] ?? ($varbind->name !== null
			? preg_replace('/\.\d+(\.\d+)*$/', '', $varbind->name)
			: null);

		if ($object !== null && $object !== '') {
			$tags[] = ['tag' => 'object', 'value' => (string) $object];
		}

		if ($index_macro !== null && $index_macro !== '') {
			$tags[] = ['tag' => 'index', 'value' => $index_macro];
		}

		return $tags;
	}

	/**
	 * Human name for the item. The MIB name is more useful than the description, which
	 * is usually a paragraph.
	 */
	public static function name(CVarbind $varbind, ?string $index_label = null): string {
		$base = $varbind->name !== null
			? preg_replace('/\.\d+(\.\d+)*$/', '', $varbind->name)
			: '.'.$varbind->oid;

		return $index_label === null || $index_label === ''
			? (string) $base
			: $index_label.': '.$base;
	}
}
