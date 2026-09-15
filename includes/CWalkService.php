<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Includes;

use APP;

/**
 * Wiring. Reads the module configuration and hands back the pieces the controllers
 * need, so no controller has to know how any of them are built.
 *
 * Configuration comes from the module manager when it is available and from
 * manifest.json otherwise, because the manager's accessor has moved between releases
 * and a config read is not worth a hard version dependency.
 */
final class CWalkService {

	public const MODULE_ID = 'snmp-walk';

	private static ?array $config = null;
	private static ?CMibLookup $mib = null;

	public static function config(?string $key = null, $default = null) {
		if (self::$config === null) {
			self::$config = self::readConfig();
		}

		if ($key === null) {
			return self::$config;
		}

		return array_key_exists($key, self::$config) && self::$config[$key] !== null
			? self::$config[$key]
			: $default;
	}

	public static function dataDir(): string {
		return (string) self::config('data_dir', '/var/lib/zabbix/snmpwalk');
	}

	public static function mib(): CMibLookup {
		if (self::$mib === null) {
			if (!function_exists('proc_open')) {
				return self::$mib = new CMibNull();
			}

			self::$mib = new CMibIndex(
				(string) self::config('snmptranslate', 'snmptranslate'),
				(array) self::config('mib_dirs', []),
				self::dataDir()
			);
		}

		return self::$mib;
	}

	public static function snapshots(): CSnapshotStore {
		return new CSnapshotStore(self::dataDir());
	}

	public static function chunkSize(): int {
		return max(20, min(2000, (int) self::config('chunk_size', 300)));
	}

	/**
	 * How many templates the target picker offers before it tells you to search.
	 *
	 * The cap exists because an uncapped query makes payload and render cost scale with
	 * the size of the install, to populate a list nobody scrolls. Raise it if your
	 * engineers would rather browse than type.
	 */
	public static function templateLimit(): int {
		return max(25, min(2000, (int) self::config('template_limit', 300)));
	}

	public static function maxVarbinds(): int {
		return max(1000, (int) self::config('max_varbinds', 200000));
	}

	/**
	 * Build the engine for a host.
	 *
	 * "auto" prefers the path that works in a distributed install: the script engine
	 * when one is configured, then the server engine, then local. A host behind a proxy
	 * is never given the local engine, because the frontend cannot reach it and the
	 * resulting timeout is a confusing way to learn that.
	 */
	public static function engine(CHostContext $host, string $requested = 'auto'): CEngine {
		$configured = (string) self::config('engine', 'auto');
		$engine = $requested === 'auto' || $requested === '' ? $configured : $requested;

		if ($engine === 'auto') {
			$engine = self::pick($host);
		}

		switch ($engine) {
			case 'local':
			case 'server':
				$unresolved = self::unresolvedReason($host);

				if ($unresolved !== null) {
					throw new \RuntimeException($unresolved);
				}

				break;

			case 'script':
				// The script engine's credentials come from the script's own command
				// line on the poller. There is no way to pass supplied ones to it, and
				// walking with the stored community while the console shows the typed
				// one would be the worst of both.
				if ($host->overridden) {
					throw new \RuntimeException(
						_('The script engine uses the credentials in the global script on the poller, so it cannot use the ones supplied here. Choose the frontend or server engine, or clear the supplied credentials.')
					);
				}
		}

		switch ($engine) {
			case 'local':
				if ($host->proxyid !== null) {
					throw new \RuntimeException(_s('%1$s is monitored by proxy %2$s, so the frontend probably cannot reach it. Use the server or script engine.',
						$host->name, (string) $host->proxy_name
					));
				}

				return new CEngineLocal($host,
					(int) self::config('local_timeout', 3),
					(int) self::config('local_retries', 2)
				);

			case 'server':
				return new CEngineServer($host);

			case 'script':
				return new CEngineScript($host, (string) self::config('script_id', ''));
		}

		throw new \RuntimeException(_s('Unknown walk engine "%1$s".', $engine));
	}

	/**
	 * Walk from wherever the host is actually monitored from.
	 *
	 * The point of a walk is to find out what the poller can see. A walk that succeeds
	 * from the frontend while the assigned server or proxy cannot reach the device has
	 * answered the wrong question, and answered it reassuringly, which is worse than
	 * failing. Route through the collector that owns the host and the result means
	 * something.
	 *
	 * This costs the cursor: both server-side engines return a whole subtree in one
	 * response, so there is no progress and no useful stop button. That is a real loss
	 * and it is still the right default. The local engine remains one click away for an
	 * all-in-one install, or when neither server-side path is available.
	 */
	private static function pick(CHostContext $host): string {
		// Supplied credentials rule out the script engine, which reads its own from the
		// poller. Between the two that can use them, prefer the local engine when it
		// has a path to the device, because it is the resumable one.
		if ($host->overridden) {
			if ($host->proxyid === null && class_exists('SNMP')) {
				return 'local';
			}

			return 'server';
		}

		$script = CEngineScript::diagnose((string) self::config('script_id', ''));

		if ($script['usable']) {
			return 'script';
		}

		// Neither remaining engine can read these credentials. Pick the one whose
		// refusal names the macro instead of the one that would sit on a socket until
		// it times out.
		if ($host->hasUnresolvedCredentials()) {
			return 'server';
		}

		if (CEngineServer::diagnose()['usable']) {
			return 'server';
		}

		if ($host->proxyid === null && class_exists('SNMP')) {
			return 'local';
		}

		return 'server';
	}

	/**
	 * Why the frontend-resolved engines cannot run against this host, or null.
	 *
	 * Both the local and server engines are handed credentials that CHostContext has
	 * already expanded. When the values behind them are Secret text or Vault secrets,
	 * there is nothing to expand: usermacro.get does not return those values to any
	 * user. Only the script engine works, because the server resolves the macro in the
	 * script's command line itself.
	 */
	private static function unresolvedReason(?CHostContext $host): ?string {
		if ($host === null || !$host->hasUnresolvedCredentials()) {
			return null;
		}

		return _s('The SNMP credentials for this host use %1$s, which the frontend cannot read. Secret text and Vault macro values are resolved by Zabbix server only, so this host can only be walked with the script engine.',
			implode(', ', $host->unresolvedCredentials())
		);
	}

	/**
	 * What each engine can do right now, for the console's engine selector. Showing
	 * this up front is the difference between "the walk failed" and "the walk failed
	 * because php-snmp is not installed".
	 */
	public static function engineStatus(?CHostContext $host = null): array {
		$server = CEngineServer::diagnose();
		$script = CEngineScript::diagnose((string) self::config('script_id', ''));

		// Unreadable credentials come first: whether php-snmp is installed does not
		// matter when there is no community string to hand it.
		$unresolved = self::unresolvedReason($host);
		$local_reason = $unresolved;

		if ($local_reason === null && !class_exists('SNMP')) {
			$local_reason = _('The php-snmp extension is not installed on the frontend host. Install php-snmp and restart PHP-FPM to enable this engine.');
		}
		elseif ($local_reason === null && $host !== null && $host->proxyid !== null) {
			$local_reason = _s('Host is monitored by proxy %1$s; the frontend has no path to it.',
				(string) $host->proxy_name
			);
		}

		return [
			'local' => [
				'label' => _('Frontend (php-snmp)'),
				'usable' => $local_reason === null,
				'reason' => $local_reason,
				'resumable' => true,
				// Shown even when the engine works, because "it worked from here" is
				// not the same answer as "the poller can reach it".
				'caveat' => _('Packets leave the frontend, not the poller that owns this host, so a result here does not prove collection will work.')
			],
			'server' => [
				'label' => _('Zabbix server or proxy (item test)'),
				// The item test payload carries the credentials the frontend resolved,
				// so this engine is no better off than the local one here.
				'usable' => $server['usable'] && $unresolved === null,
				'reason' => $unresolved ?? $server['reason'],
				'resumable' => false
			],
			'script' => [
				'label' => _('Zabbix server or proxy (global script)'),
				'usable' => $script['usable'] && !($host !== null && $host->overridden),
				'reason' => $host !== null && $host->overridden
					? _('This engine takes its credentials from the global script on the poller, so it cannot use the ones supplied here.')
					: $script['reason'],
				'resumable' => false,
				'script_name' => $script['name']
			]
		];
	}

	private static function readConfig(): array {
		$defaults = [
			'data_dir' => '/var/lib/zabbix/snmpwalk',
			// /var/lib/zabbix/mibs is the path the official Zabbix container images
			// expect custom MIBs to be mounted at, so it leads.
			'mib_dirs' => ['/var/lib/zabbix/mibs', '/usr/share/snmp/mibs'],
			'snmptranslate' => 'snmptranslate',
			'engine' => 'auto',
			'chunk_size' => 300,
			'max_varbinds' => 200000,
			'local_timeout' => 3,
			'local_retries' => 2,
			'script_id' => null,
			'min_user_type' => USER_TYPE_ZABBIX_ADMIN,
			'template_limit' => 300,
			'snapshot_retention_days' => 365
		];

		$config = [];

		try {
			$manager = APP::ModuleManager();

			if (method_exists($manager, 'getModule')) {
				$module = $manager->getModule(self::MODULE_ID);

				if ($module !== null && method_exists($module, 'getConfig')) {
					$config = (array) $module->getConfig();
				}
			}
		}
		catch (\Throwable $e) {
			$config = [];
		}

		if (!$config) {
			$manifest = json_decode((string) @file_get_contents(dirname(__DIR__).'/manifest.json'), true);
			$config = is_array($manifest) ? (array) ($manifest['config'] ?? []) : [];
		}

		return $config + $defaults;
	}
}
