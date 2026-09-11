<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Includes;

/**
 * One varbind, plus whatever the MIB index could tell us about it.
 *
 * $type is the net-snmp type token as it appears in walk output ("STRING",
 * "Counter64", "Hex-STRING", ...) rather than a PHP constant, because that is the
 * form both engines can produce and the form that survives a round trip to disk.
 */
final class CVarbind {

	public string $oid;
	public string $type;
	public string $value;

	/** Symbolic name from the MIB index, e.g. "IF-MIB::ifDescr.1". Null when unresolved. */
	public ?string $name = null;

	/** Defining MIB module, e.g. "IF-MIB". */
	public ?string $mib = null;

	public ?string $syntax = null;
	public ?string $access = null;
	public ?string $description = null;

	/** Enumeration from the SYNTAX clause: value => label. */
	public array $enum = [];

	public function __construct(string $oid, string $type, string $value) {
		$this->oid = COid::normalize($oid);
		$this->type = $type;
		$this->value = $value;
	}

	public function toArray(): array {
		return [
			'oid' => $this->oid,
			'type' => $this->type,
			'value' => $this->value,
			'name' => $this->name,
			'mib' => $this->mib,
			'syntax' => $this->syntax,
			'access' => $this->access,
			'description' => $this->description,
			'enum' => $this->enum
		];
	}

	public static function fromArray(array $row): self {
		$vb = new self((string) ($row['oid'] ?? ''), (string) ($row['type'] ?? ''),
			(string) ($row['value'] ?? '')
		);
		$vb->name = $row['name'] ?? null;
		$vb->mib = $row['mib'] ?? null;
		$vb->syntax = $row['syntax'] ?? null;
		$vb->access = $row['access'] ?? null;
		$vb->description = $row['description'] ?? null;
		$vb->enum = $row['enum'] ?? [];

		return $vb;
	}
}
