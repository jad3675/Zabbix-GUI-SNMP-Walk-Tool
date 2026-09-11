<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Includes;

/**
 * MIB translation, built on net-snmp's snmptranslate.
 *
 * Two tiers, because they have very different costs:
 *
 *  - The index (numeric OID <-> symbolic name) is built once with a single
 *    "snmptranslate -Tz" run over every configured MIB directory and cached on disk.
 *    Lookups after that are array lookups, so translating a 20k-varbind walk is free.
 *
 *  - Object detail (SYNTAX, MAX-ACCESS, DESCRIPTION, enumerations, table INDEX) needs
 *    one "snmptranslate -Td" run per object, so it is fetched lazily and memoised to
 *    disk. Detail is only ever needed per column, per expanded row, or when creating
 *    an item, never per row of a walk.
 *
 * Nothing here ever accepts a shell metacharacter: OIDs are validated by COid and
 * directories are resolved against the configured allow list before use.
 */
class CMibIndex implements CMibLookup {

	private const INDEX_FILE = 'mib-index.json';
	private const DETAIL_FILE = 'mib-detail.json';

	private string $binary;
	private array $dirs;
	private string $data_dir;

	/** oid => name, e.g. "1.3.6.1.2.1.2.2.1.2" => "ifDescr" */
	private array $by_oid = [];

	/** lowercased name => oid */
	private array $by_name = [];

	/** name => defining MIB module */
	private array $module_of = [];

	private array $detail = [];
	private bool $detail_dirty = false;
	private bool $loaded = false;

	public function __construct(string $binary, array $dirs, string $data_dir) {
		$this->binary = $binary;
		$this->dirs = $dirs;
		$this->data_dir = rtrim($data_dir, '/');
	}

	public function isAvailable(): bool {
		return $this->run(['-V'], $out, $err, true) !== false || $err !== '';
	}

	public function isIndexed(): bool {
		return file_exists($this->data_dir.'/'.self::INDEX_FILE);
	}

	public function indexedAt(): ?int {
		$path = $this->data_dir.'/'.self::INDEX_FILE;

		return file_exists($path) ? (filemtime($path) ?: null) : null;
	}

	public function count(): int {
		$this->load();

		return count($this->by_oid);
	}

	/**
	 * Rebuild the index from every configured MIB directory.
	 *
	 * @return array  ['objects' => int, 'errors' => string]
	 */
	public function reindex(): array {
		$args = ['-Tz', '-m', 'ALL'];
		$dirs = $this->mibPath();

		if ($dirs !== '') {
			array_push($args, '-M', $dirs);
		}

		$output = $this->run($args, $stdout, $stderr);

		if ($output === false) {
			throw new \RuntimeException(_s('Cannot execute %1$s. Install net-snmp on the frontend host or set the snmptranslate path in the module configuration.',
				$this->binary
			));
		}

		$by_oid = [];
		$by_name = [];

		// Each line is: "sysDescr"<tab>"1.3.6.1.2.1.1.1"
		foreach (explode("\n", $stdout) as $line) {
			if (!preg_match('/^"([^"]+)"\s+"\.?([0-9.]+)"\s*$/', trim($line), $m)) {
				continue;
			}

			$name = $m[1];
			$oid = COid::normalize($m[2]);

			// Keep the first (shortest) definition when a name is ambiguous, and never
			// let a later MIB steal an OID that is already mapped.
			if (!array_key_exists($oid, $by_oid)) {
				$by_oid[$oid] = $name;
			}

			$key = strtolower($name);

			if (!array_key_exists($key, $by_name)) {
				$by_name[$key] = $oid;
			}
		}

		$this->by_oid = $by_oid;
		$this->by_name = $by_name;
		$this->loaded = true;

		$this->ensureDataDir();
		$this->atomicWrite($this->data_dir.'/'.self::INDEX_FILE, json_encode([
			'built' => time(),
			'dirs' => $this->dirs,
			'by_oid' => $by_oid,
			'by_name' => $by_name
		]));

		// The detail cache is keyed by OID, so a MIB change can invalidate it.
		@unlink($this->data_dir.'/'.self::DETAIL_FILE);
		$this->detail = [];

		return [
			'objects' => count($by_oid),
			'errors' => trim($stderr)
		];
	}

	/**
	 * Resolve a symbolic name ("ifDescr", "IF-MIB::ifDescr", "ifDescr.1") to numeric.
	 */
	public function resolve(string $input): ?string {
		$this->load();

		$input = trim($input);
		$suffix = '';

		if (preg_match('/^([^.]+)((?:\.\d+)*)$/', $input, $m)) {
			$input = $m[1];
			$suffix = $m[2];
		}

		if (str_contains($input, '::')) {
			[, $input] = explode('::', $input, 2);
		}

		$oid = $this->by_name[strtolower($input)] ?? null;

		return $oid === null ? null : $oid.$suffix;
	}

	/**
	 * Longest-prefix lookup. Returns the defining object plus the trailing index.
	 *
	 * @return array|null  ['oid' => string, 'name' => string, 'index' => string]
	 */
	public function lookup(string $oid): ?array {
		$this->load();

		$parts = COid::toArray($oid);

		for ($i = count($parts); $i > 0; $i--) {
			$candidate = implode('.', array_slice($parts, 0, $i));

			if (array_key_exists($candidate, $this->by_oid)) {
				return [
					'oid' => $candidate,
					'name' => $this->by_oid[$candidate],
					'index' => implode('.', array_slice($parts, $i))
				];
			}
		}

		return null;
	}

	/**
	 * Annotate varbinds in place with name and MIB module. Index lookups only, so this
	 * is safe to call on an entire walk.
	 *
	 * @param CVarbind[] $varbinds
	 */
	public function annotate(array $varbinds): void {
		$this->load();

		if (!$this->by_oid) {
			return;
		}

		$module_cache = [];

		foreach ($varbinds as $vb) {
			$hit = $this->lookup($vb->oid);

			if ($hit === null) {
				continue;
			}

			$vb->name = $hit['name'].($hit['index'] === '' ? '' : '.'.$hit['index']);

			if (!array_key_exists($hit['oid'], $module_cache)) {
				$detail = $this->detailCached($hit['oid']);
				$module_cache[$hit['oid']] = $detail['mib'] ?? null;
			}

			$vb->mib = $module_cache[$hit['oid']];
		}
	}

	/**
	 * Full object detail. One snmptranslate call per object, memoised to disk.
	 *
	 * @return array  ['name','mib','syntax','access','status','description','enum','index','is_table','is_entry']
	 */
	public function detail(string $oid): array {
		$oid = COid::normalize($oid);

		if (!COid::isNumeric($oid)) {
			return [];
		}

		if (array_key_exists($oid, $this->detail)) {
			return $this->detail[$oid];
		}

		$this->loadDetail();

		if (array_key_exists($oid, $this->detail)) {
			return $this->detail[$oid];
		}

		$args = ['-Td', '-On', '-m', 'ALL'];
		$dirs = $this->mibPath();

		if ($dirs !== '') {
			array_push($args, '-M', $dirs);
		}

		$args[] = '.'.$oid;

		$parsed = [];

		if ($this->run($args, $stdout, $stderr) !== false && trim($stdout) !== '') {
			$parsed = $this->parseDetail($stdout);
		}

		$this->detail[$oid] = $parsed;
		$this->detail_dirty = true;

		return $parsed;
	}

	private function detailCached(string $oid): array {
		$this->loadDetail();

		return $this->detail[$oid] ?? $this->detail($oid);
	}

	/**
	 * Parse "snmptranslate -Td" output.
	 */
	private function parseDetail(string $text): array {
		$out = [
			'name' => null,
			'mib' => null,
			'syntax' => null,
			'units' => null,
			'access' => null,
			'status' => null,
			'description' => null,
			'enum' => [],
			'index' => [],
			'is_table' => false,
			'is_entry' => false
		];

		if (preg_match('/^\s*([A-Za-z][\w-]*)\s+(OBJECT-TYPE|OBJECT IDENTIFIER|NOTIFICATION-TYPE|MODULE-IDENTITY|TRAP-TYPE)/m',
				$text, $m)) {
			$out['name'] = $m[1];
		}

		if (preg_match('/--\s*FROM\s+([\w-]+)/', $text, $m)) {
			$out['mib'] = $m[1];
		}

		if (preg_match('/^\s*SYNTAX\s+(.+?)(?=^\s*(?:UNITS|DISPLAY-HINT|MAX-ACCESS|ACCESS|STATUS|DESCRIPTION|INDEX|AUGMENTS|::=)\b)/ms',
				$text, $m)) {
			$syntax = preg_replace('/\s+/', ' ', trim($m[1]));
			$out['syntax'] = $syntax;

			// INTEGER {up(1), down(2), testing(3)}
			if (preg_match('/\{(.+)\}/s', $syntax, $em)) {
				foreach (explode(',', $em[1]) as $pair) {
					if (preg_match('/([\w-]+)\s*\(\s*(-?\d+)\s*\)/', $pair, $pm)) {
						$out['enum'][$pm[2]] = $pm[1];
					}
				}
			}
		}

		if (preg_match('/^\s*UNITS\s+"([^"]*)"/m', $text, $m)) {
			$out['units'] = trim($m[1]);
		}

		if (preg_match('/^\s*(?:MAX-ACCESS|ACCESS)\s+(.+)$/m', $text, $m)) {
			$out['access'] = trim($m[1]);
		}

		if (preg_match('/^\s*STATUS\s+(.+)$/m', $text, $m)) {
			$out['status'] = trim($m[1]);
		}

		if (preg_match('/DESCRIPTION\s+"(.*?)"/s', $text, $m)) {
			$out['description'] = trim(preg_replace('/\s+/', ' ', $m[1]));
		}

		if (preg_match('/^\s*INDEX\s*\{(.+?)\}/ms', $text, $m)) {
			foreach (explode(',', $m[1]) as $part) {
				$part = trim($part);

				if ($part !== '') {
					$out['index'][] = $part;
				}
			}

			$out['is_entry'] = true;
		}

		if (preg_match('/^\s*AUGMENTS\s*\{(.+?)\}/ms', $text, $m)) {
			$out['index'][] = trim($m[1]);
			$out['is_entry'] = true;
		}

		if ($out['syntax'] !== null && preg_match('/^SEQUENCE OF/', $out['syntax'])) {
			$out['is_table'] = true;
		}

		return $out;
	}

	/**
	 * Colon-separated -M argument. Only directories that exist are passed, otherwise
	 * net-snmp emits a warning per missing path on every call.
	 */
	private function mibPath(): string {
		$dirs = [];

		foreach ($this->dirs as $dir) {
			$dir = (string) $dir;

			if ($dir !== '' && !str_contains($dir, ':') && is_dir($dir)) {
				$dirs[] = rtrim($dir, '/');
			}
		}

		$uploaded = $this->uploadDir();

		if (is_dir($uploaded)) {
			$dirs[] = $uploaded;
		}

		return implode(':', array_unique($dirs));
	}

	public function uploadDir(): string {
		return $this->data_dir.'/mibs';
	}

	/**
	 * @return array[]  ['name','size','mtime']
	 */
	public function uploadedMibs(): array {
		$dir = $this->uploadDir();

		if (!is_dir($dir)) {
			return [];
		}

		$files = [];

		foreach ((array) scandir($dir) as $entry) {
			if ($entry === '.' || $entry === '..' || !is_file($dir.'/'.$entry)) {
				continue;
			}

			$files[] = [
				'name' => $entry,
				'size' => filesize($dir.'/'.$entry) ?: 0,
				'mtime' => filemtime($dir.'/'.$entry) ?: 0
			];
		}

		usort($files, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

		return $files;
	}

	private function load(): void {
		if ($this->loaded) {
			return;
		}

		$this->loaded = true;
		$path = $this->data_dir.'/'.self::INDEX_FILE;

		if (!file_exists($path)) {
			return;
		}

		$raw = json_decode((string) file_get_contents($path), true);

		if (is_array($raw)) {
			$this->by_oid = $raw['by_oid'] ?? [];
			$this->by_name = $raw['by_name'] ?? [];
		}
	}

	private function loadDetail(): void {
		static $done = false;

		if ($done) {
			return;
		}

		$done = true;
		$path = $this->data_dir.'/'.self::DETAIL_FILE;

		if (!file_exists($path)) {
			return;
		}

		$raw = json_decode((string) file_get_contents($path), true);

		if (is_array($raw)) {
			$this->detail += $raw;
		}
	}

	/**
	 * Flush the lazily built detail cache. Called once at the end of a request.
	 */
	public function persist(): void {
		if (!$this->detail_dirty) {
			return;
		}

		$this->detail_dirty = false;
		$this->ensureDataDir();
		$this->atomicWrite($this->data_dir.'/'.self::DETAIL_FILE, (string) json_encode($this->detail));
	}

	private function ensureDataDir(): void {
		if (!is_dir($this->data_dir) && !@mkdir($this->data_dir, 0750, true) && !is_dir($this->data_dir)) {
			throw new \RuntimeException(_s('Cannot create data directory "%1$s". Create it and make it writable by the web server user.',
				$this->data_dir
			));
		}
	}

	private function atomicWrite(string $path, string $contents): void {
		$tmp = $path.'.'.getmypid().'.tmp';

		if (file_put_contents($tmp, $contents) === false || !@rename($tmp, $path)) {
			@unlink($tmp);

			throw new \RuntimeException(_s('Cannot write "%1$s".', $path));
		}
	}

	/**
	 * @param string[] $args
	 */
	private function run(array $args, ?string &$stdout = null, ?string &$stderr = null, bool $quiet = false) {
		$stdout = '';
		$stderr = '';

		if (!function_exists('proc_open')) {
			if ($quiet) {
				return false;
			}

			throw new \RuntimeException(_('proc_open() is disabled in this PHP installation, so MIB translation is unavailable.'));
		}

		$cmd = escapeshellcmd($this->binary);

		foreach ($args as $arg) {
			$cmd .= ' '.escapeshellarg((string) $arg);
		}

		$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$process = @proc_open($cmd, $descriptors, $pipes);

		if (!is_resource($process)) {
			return false;
		}

		$stdout = (string) stream_get_contents($pipes[1]);
		$stderr = (string) stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);

		return proc_close($process);
	}
}
