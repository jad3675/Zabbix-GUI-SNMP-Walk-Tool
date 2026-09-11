<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Actions;

use Modules\SnmpWalk\Includes\CWalkService;

/**
 * Remove an uploaded MIB and rebuild the index.
 */
class MibDelete extends CWalkAction {

	protected function checkPermissions(): bool {
		return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function checkInput(): bool {
		return $this->validate(['name' => 'required|string']);
	}

	protected function doAction(): void {
		$this->guard(function (): void {
			$name = $this->getInput('name');

			// The name comes from the browser, so it is rebuilt rather than trusted.
			if ($name !== basename($name) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name)) {
				$this->fail(_('Invalid file name.'));

				return;
			}

			$mib = CWalkService::mib();
			$path = $mib->uploadDir().'/'.$name;

			if (!is_file($path)) {
				$this->fail(_('File not found.'));

				return;
			}

			if (!@unlink($path)) {
				$this->fail(_s('Cannot delete "%1$s".', $name));

				return;
			}

			$this->log('mib.delete', ['name' => $name]);

			$result = ['deleted' => $name];

			try {
				$result['index'] = $mib->reindex();
			}
			catch (\Throwable $e) {
				$result['index_error'] = $e->getMessage();
			}

			$result['uploaded'] = $mib->uploadedMibs();

			$this->json($result);
		});
	}
}
