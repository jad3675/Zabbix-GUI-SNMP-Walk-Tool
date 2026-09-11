<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Actions;

use Modules\SnmpWalk\Includes\CWalkService;

/**
 * Accept vendor MIB files.
 *
 * Uploads are treated as hostile input: the filename is rebuilt from scratch rather
 * than sanitised, the contents must look like an ASN.1 module definition, and the file
 * lands in a directory that is only ever read by snmptranslate.
 */
class MibUpload extends CWalkAction {

	private const MAX_BYTES = 8388608;

	protected function checkPermissions(): bool {
		return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function checkInput(): bool {
		return true;
	}

	protected function doAction(): void {
		$this->guard(function (): void {
			$files = $_FILES['mib'] ?? null;

			if (!is_array($files) || !isset($files['name'])) {
				$this->fail(_('No file was uploaded.'));

				return;
			}

			$names = (array) $files['name'];
			$tmp_names = (array) $files['tmp_name'];
			$errors = (array) $files['error'];
			$sizes = (array) $files['size'];

			$mib = CWalkService::mib();
			$dir = $mib->uploadDir();

			if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
				$this->fail(_s('Cannot create "%1$s". Create it and make it writable by the web server user.', $dir));

				return;
			}

			$stored = [];
			$rejected = [];

			foreach ($names as $i => $name) {
				if ((int) ($errors[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
					$rejected[] = ['name' => (string) $name, 'reason' => _('Upload failed.')];
					continue;
				}

				if ((int) ($sizes[$i] ?? 0) > self::MAX_BYTES) {
					$rejected[] = ['name' => (string) $name, 'reason' => _('File is too large.')];
					continue;
				}

				$contents = (string) file_get_contents((string) $tmp_names[$i]);

				if (!preg_match('/\bDEFINITIONS\s*::=\s*BEGIN\b/', $contents)) {
					$rejected[] = [
						'name' => (string) $name,
						'reason' => _('This does not look like a MIB file (no "DEFINITIONS ::= BEGIN").')
					];
					continue;
				}

				// Prefer the module name declared inside the file over whatever the
				// browser sent, then strip it down to a safe basename regardless.
				$module = preg_match('/^\s*([A-Za-z][\w-]*)\s+DEFINITIONS\s*::=\s*BEGIN/m', $contents, $m)
					? $m[1]
					: pathinfo((string) $name, PATHINFO_FILENAME);

				$safe = preg_replace('/[^A-Za-z0-9._-]/', '', (string) $module);

				if ($safe === '' || $safe[0] === '.') {
					$rejected[] = ['name' => (string) $name, 'reason' => _('Cannot derive a safe filename.')];
					continue;
				}

				if (file_put_contents($dir.'/'.$safe, $contents) === false) {
					$rejected[] = ['name' => (string) $name, 'reason' => _('Cannot write the file.')];
					continue;
				}

				$stored[] = $safe;
			}

			$this->log('mib.upload', ['stored' => implode(',', $stored)]);

			$result = ['stored' => $stored, 'rejected' => $rejected, 'reindexed' => false];

			if ($stored) {
				try {
					$result['index'] = $mib->reindex();
					$result['reindexed'] = true;
				}
				catch (\Throwable $e) {
					$result['index_error'] = $e->getMessage();
				}
			}

			$result['uploaded'] = $mib->uploadedMibs();

			$this->json($result);
		});
	}
}
