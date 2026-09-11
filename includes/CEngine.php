<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Includes;

/**
 * A walk engine fetches varbinds. The three implementations differ in where the SNMP
 * packet originates, which is the only thing that really matters in a distributed
 * install:
 *
 *   local   php-snmp, from the frontend host. Resumable, so a walk of an entire
 *           chassis streams in with a progress bar. Requires the frontend to have a
 *           network path to the device, which rules it out for proxied hosts.
 *
 *   server  An unsaved walk[] item run through the Zabbix server's item test, the
 *           same path the Test button on the item form uses. Routes through the
 *           proxy, inherits macro resolution and SNMPv3 handling. One shot: the
 *           server returns the whole subtree or nothing, so no progress bar.
 *
 *   script  A global script with scope "manual host action" executed on the server or
 *           proxy through script.execute. Also proxy-aware, and built entirely on
 *           documented API, at the cost of an admin having to create the script once.
 *
 * All three return the same shape so the console does not care which ran.
 */
interface CEngine {

	/**
	 * @param string      $root    Numeric OID to walk below.
	 * @param string|null $cursor  Resume point, or null to start at $root. Ignored by
	 *                             engines that cannot resume.
	 * @param int         $limit   Maximum varbinds to return in this call.
	 *
	 * @return array  [
	 *                  'varbinds' => CVarbind[],
	 *                  'cursor'   => string|null   next resume point, null when done
	 *                  'done'     => bool,
	 *                  'skipped'  => int,
	 *                  'notices'  => string[]
	 *                ]
	 *
	 * @throws \RuntimeException on a transport or credential failure.
	 */
	public function walk(string $root, ?string $cursor, int $limit): array;

	/**
	 * Whether walk() honours $cursor. Drives whether the console shows progress.
	 */
	public function isResumable(): bool;

	/**
	 * Short identifier used in snapshots and in the UI.
	 */
	public function name(): string;

	/**
	 * Human-readable description of where the packets came from, e.g.
	 * "Zabbix proxy cin-proxy-01".
	 */
	public function origin(): string;
}
