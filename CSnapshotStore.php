<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Includes;

/**
 * Snapshot storage on the filesystem.
 *
 * Deliberately not in the Zabbix database: a module cannot add tables without owning a
 * migration story across upgrades, and a walk of a large chassis is megabytes of text
 * that has no business in a table the housekeeper does not know about. Walks are
 * gzipped JSON under <data_dir>/snapshots/<hostid>/, with a small per-host index so
 * listing does not have to open every file.
 *
 * A snapshot is the baseline for a diff, and is what turns "onboard the customer's new
 * switch" into an artifact instead of a session that disappears when the tab closes.
 */
final class CSnapshotStore {

	private string $root;

	public function __construct(string $data_dir) {
		$this->root = rtrim($data_dir, '/').'/snapshots';
	}

	/**
	 * @param CVarbind[] $varbinds
	 *
	 * @return string  snapshot id
	 */
	public function save(string $hostid, array $meta, array $varbinds): string {
		$this->assertId($hostid);

		$id = date('Ymd-His').'-'.bin2hex(random_bytes(4));
		$dir = $this->hostDir($hostid);

		$this->ensureDir($dir);

		$payload = [
			'id' => $id,
			'hostid' => $hostid,
			'meta' => $meta,
			'varbinds' => array_map(static fn(CVarbind $vb): array => $vb->toArray(), $varbinds)
		];

		$body = gzencode((string) json_encode($payload), 6);

		if ($body === false) {
			throw new \RuntimeException(_('Cannot compress the snapshot.'));
		}

		$this->atomicWrite($dir.'/'.$id.'.json.gz', $body);

		$index = $this->index($hostid);
		$index[$id] = [
			'id' => $id,
			'created' => time(),
			'root' => $meta['root'] ?? '',
			'engine' => $meta['engine'] ?? '',
			'origin' => $meta['origin'] ?? '',
			'user' => $meta['user'] ?? '',
			'label' => $meta['label'] ?? '',
			'count' => count($varbinds),
			'host_name' => $meta['host_name'] ?? ''
		];

		$this->writeIndex($hostid, $index);

		return $id;
	}

	/**
	 * @return array[]  newest first
	 */
	public function listForHost(string $hostid): array {
		$this->assertId($hostid);

		$index = array_values($this->index($hostid));
		usort($index, static fn(array $a, array $b): int => $b['created'] <=> $a['created']);

		return $index;
	}

	/**
	 * Every snapshot across every host the caller passes in. Used by the diff picker so
	 * a walk of one device can be compared against a walk of another.
	 *
	 * @param string[] $hostids
	 */
	public function listForHosts(array $hostids): array {
		$out = [];

		foreach ($hostids as $hostid) {
			foreach ($this->listForHost((string) $hostid) as $entry) {
				$entry['hostid'] = (string) $hostid;
				$out[] = $entry;
			}
		}

		usort($out, static fn(array $a, array $b): int => $b['created'] <=> $a['created']);

		return $out;
	}

	/**
	 * @return array  ['meta' => array, 'varbinds' => CVarbind[]]
	 */
	public function load(string $hostid, string $id): array {
		$this->assertId($hostid);
		$this->assertId($id, '/^[0-9]{8}-[0-9]{6}-[0-9a-f]{8}$/');

		$path = $this->hostDir($hostid).'/'.$id.'.json.gz';

		if (!file_exists($path)) {
			throw new \RuntimeException(_('Snapshot not found.'));
		}

		$raw = gzdecode((string) file_get_contents($path));

		if ($raw === false) {
			throw new \RuntimeException(_('Snapshot is corrupt.'));
		}

		$payload = json_decode($raw, true);

		if (!is_array($payload)) {
			throw new \RuntimeException(_('Snapshot is corrupt.'));
		}

		return [
			'meta' => $payload['meta'] ?? [],
			'varbinds' => array_map(
				static fn(array $row): CVarbind => CVarbind::fromArray($row),
				$payload['varbinds'] ?? []
			)
		];
	}

	public function delete(string $hostid, string $id): void {
		$this->assertId($hostid);
		$this->assertId($id, '/^[0-9]{8}-[0-9]{6}-[0-9a-f]{8}$/');

		@unlink($this->hostDir($hostid).'/'.$id.'.json.gz');

		$index = $this->index($hostid);
		unset($index[$id]);
		$this->writeIndex($hostid, $index);
	}

	/**
	 * Drop snapshots older than the configured retention. Called opportunistically on
	 * save rather than from cron, because a module has no scheduler.
	 */
	public function prune(string $hostid, int $days): int {
		if ($days <= 0) {
			return 0;
		}

		$cutoff = time() - $days * 86400;
		$index = $this->index($hostid);
		$removed = 0;

		foreach ($index as $id => $entry) {
			if ((int) ($entry['created'] ?? 0) < $cutoff) {
				@unlink($this->hostDir($hostid).'/'.$id.'.json.gz');
				unset($index[$id]);
				$removed++;
			}
		}

		if ($removed > 0) {
			$this->writeIndex($hostid, $index);
		}

		return $removed;
	}

	private function index(string $hostid): array {
		$path = $this->hostDir($hostid).'/index.json';

		if (!file_exists($path)) {
			return [];
		}

		$raw = json_decode((string) file_get_contents($path), true);

		return is_array($raw) ? $raw : [];
	}

	private function writeIndex(string $hostid, array $index): void {
		$this->ensureDir($this->hostDir($hostid));
		$this->atomicWrite($this->hostDir($hostid).'/index.json', (string) json_encode($index));
	}

	private function hostDir(string $hostid): string {
		return $this->root.'/'.$hostid;
	}

	/**
	 * Path components come from the URL, so they are checked rather than trusted.
	 */
	private function assertId(string $value, string $pattern = '/^[0-9]+$/'): void {
		if (!preg_match($pattern, $value)) {
			throw new \RuntimeException(_('Invalid snapshot reference.'));
		}
	}

	private function ensureDir(string $dir): void {
		if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
			throw new \RuntimeException(_s('Cannot create "%1$s". Create the data directory and make it writable by the web server user.',
				$dir
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
}
