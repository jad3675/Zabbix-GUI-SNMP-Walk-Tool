<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Includes;

/**
 * What you get when there is no MIB translation available.
 *
 * Everything still works: walks run, tables are still detected from OID structure,
 * coverage still compares numeric OIDs. Only the names are missing, and the console
 * says so rather than pretending.
 */
final class CMibNull implements CMibLookup {

	public function lookup(string $oid): ?array {
		return null;
	}

	public function detail(string $oid): array {
		return [];
	}

	public function isIndexed(): bool {
		return false;
	}

	public function annotate(array $varbinds): void {
	}

	public function resolve(string $input): ?string {
		return null;
	}

	public function persist(): void {
	}
}
