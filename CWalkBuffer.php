<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Includes;

/**
 * Server-side scratch space for a walk in progress.
 *
 * A chunked walk arrives over many requests, and the follow-up actions (table
 * analysis, coverage, saving a snapshot) all need the whole thing. Buffering it
 * server-side means the browser never has to post several megabytes of varbinds back
 * for every operation. The browser still keeps its own copy for rendering and for the
 * client-side download, so nothing has to be fetched twice.
 *
 * Buffers are per user, keyed by an opaque token, and pruned by age. They are scratch:
 * losing one costs a re-walk, not data.
 */
final class CWalkBuffer {

	private const MAX_AGE = 7200;

	private string $dir;
	private string $token;

	public function __construct(string $data_dir, string $userid, string $token) {
		if (!preg_match('/^[0-9]+$/', $userid) || !preg_match('/^[0-9a-f]{16}$/', $token)) {
			throw new \RuntimeException(_('Invalid walk reference.'));
		}

		$this->dir = rtrim($data_dir, '/').'/scratch/'.$userid;
		$this->token = $token;
	}

	public static function newToken(): string {
		return bin2hex(random_bytes(8));
	}

	public function token(): string {
		return $this->token;
	}

	public function start(array $meta): void {
		$this->ensureDir();
		$this->prune();
		$this->write(['meta' => $meta, 'varbinds' => []]);
	}

	/**
	 * @param CVarbind[] $varbinds
	 *
	 * @return int  total varbinds buffered after the append
	 */
	public function append(array $varbinds): int {
		$state = $this->read();

		foreach ($varbinds as $vb) {
			$state['varbinds'][] = $vb->toArray();
		}

		$this->write($state);

		return count($state['varbinds']);
	}

	public function meta(): array {
		return $this->read()['meta'] ?? [];
	}

	public function updateMeta(array $meta): void {
		$state = $this->read();
		$state['meta'] = $meta + ($state['meta'] ?? []);
		$this->write($state);
	}

	/**
	 * @return CVarbind[]
	 */
	public function varbinds(): array {
		return array_map(
			static fn(array $row): CVarbind => CVarbind::fromArray($row),
			$this->read()['varbinds'] ?? []
		);
	}

	public function count(): int {
		return count($this->read()['varbinds'] ?? []);
	}

	public function discard(): void {
		@unlink($this->path());
	}

	private function path(): string {
		return $this->dir.'/'.$this->token.'.json.gz';
	}

	private function read(): array {
		$path = $this->path();

		if (!file_exists($path)) {
			throw new \RuntimeException(_('This walk has expired. Run it again.'));
		}

		$raw = gzdecode((string) file_get_contents($path));
		$state = $raw === false ? null : json_decode($raw, true);

		if (!is_array($state)) {
			throw new \RuntimeException(_('This walk has expired. Run it again.'));
		}

		return $state;
	}

	private function write(array $state): void {
		$this->ensureDir();
		$body = gzencode((string) json_encode($state), 1);

		if ($body === false) {
			throw new \RuntimeException(_('Cannot buffer walk results.'));
		}

		$tmp = $this->path().'.tmp';

		if (file_put_contents($tmp, $body) === false || !@rename($tmp, $this->path())) {
			@unlink($tmp);

			throw new \RuntimeException(_s('Cannot write to "%1$s". Check that the data directory is writable by the web server user.',
				$this->dir
			));
		}
	}

	private function prune(): void {
		$cutoff = time() - self::MAX_AGE;

		foreach ((array) glob($this->dir.'/*.json.gz') as $file) {
			if (is_string($file) && (int) filemtime($file) < $cutoff) {
				@unlink($file);
			}
		}
	}

	private function ensureDir(): void {
		if (!is_dir($this->dir) && !@mkdir($this->dir, 0750, true) && !is_dir($this->dir)) {
			throw new \RuntimeException(_s('Cannot create "%1$s". Create the data directory and make it writable by the web server user.',
				$this->dir
			));
		}
	}
}
