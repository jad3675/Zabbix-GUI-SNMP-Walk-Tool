<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Includes;

/**
 * The subset of MIB translation that the analysis code depends on.
 *
 * Table detection and coverage analysis both work without any MIBs at all, just less
 * confidently, so they depend on this rather than on the snmptranslate-backed
 * implementation. CMibNull is what they get when net-snmp is not installed on the
 * frontend, which keeps the console useful on a bare container instead of failing.
 */
interface CMibLookup {

	/**
	 * Longest-prefix lookup.
	 *
	 * @return array|null  ['oid' => string, 'name' => string, 'index' => string]
	 */
	public function lookup(string $oid): ?array;

	/**
	 * Full object detail: syntax, access, description, enumerations, INDEX clause.
	 */
	public function detail(string $oid): array;

	public function isIndexed(): bool;

	/**
	 * @param CVarbind[] $varbinds
	 */
	public function annotate(array $varbinds): void;

	public function resolve(string $input): ?string;

	/**
	 * Flush anything built lazily during the request.
	 */
	public function persist(): void;
}
