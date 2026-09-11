<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Actions;

use CController;
use CControllerResponseData;
use CRoleHelper;
use CWebUser;
use Modules\SnmpWalk\Includes\CWalkService;

require_once dirname(__DIR__).'/includes/bootstrap.php';

/**
 * Shared plumbing for the module's controllers.
 *
 * Every walk is a live SNMP request against customer equipment, so the permission
 * check is not a formality. Read access is gated on user type, and anything that
 * writes configuration additionally requires write access to the host itself, checked
 * against the API rather than inferred from the user's type.
 */
abstract class CWalkAction extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkPermissions(): bool {
		$minimum = (int) CWalkService::config('min_user_type', USER_TYPE_ZABBIX_ADMIN);

		if ($this->getUserType() < $minimum) {
			return false;
		}

		// Gate on the UI element rather than the user type alone, so a read-only NOC
		// role can be given the console without being given host configuration.
		if (class_exists('CRoleHelper') && defined('CRoleHelper::UI_MONITORING_HOSTS')) {
			return CRoleHelper::checkAccess(CRoleHelper::UI_MONITORING_HOSTS, CWebUser::$data['roleid']);
		}

		return true;
	}

	/**
	 * Validate input and, on failure, answer with the reason.
	 *
	 * CController::run() throws "Unexpected response for action X" when checkInput()
	 * returns false without a response being set, and CNewValidator's actual complaint
	 * goes to the message stack where nothing reads it. That turns "you sent an empty
	 * interfaceid" into a generic error with no clue attached, so every JSON action
	 * here validates through this instead.
	 */
	protected function validate(array $rules): bool {
		if ($this->validateInput($rules)) {
			return true;
		}

		$messages = [];

		foreach ((array) get_and_clear_messages() as $message) {
			$text = is_array($message) ? ($message['message'] ?? '') : (string) $message;

			if ($text !== '') {
				$messages[] = $text;
			}
		}

		$this->log('input-rejected', [
			'action' => $this->getAction(),
			'messages' => implode('; ', $messages)
		]);

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
			'error' => [
				'title' => _('The request was rejected before it ran.'),
				'messages' => $messages
					?: [_('Input validation failed but reported no reason. Check the module log.')]
			]
		])]));

		return false;
	}

	/**
	 * Send a JSON body. Every JSON action in this module answers with either
	 * {"error": "..."} or a payload, so the client has exactly one thing to check.
	 */
	protected function json(array $data): void {
		$this->setResponse(new CControllerResponseData(['main_block' => json_encode($data)]));
	}

	protected function fail(string $message): void {
		$this->json(['error' => $message]);
	}

	/**
	 * Record a walk or a configuration change made through this module.
	 *
	 * The Zabbix audit log has no entry point for a module action, so this writes to
	 * the web server error log instead. It is not a substitute for audit, but it means
	 * "who walked this customer's core switch at 3am" has an answer.
	 */
	protected function log(string $action, array $context = []): void {
		$parts = ['snmpwalk', $action, 'user='.CWebUser::$data['username']];

		foreach ($context as $key => $value) {
			$parts[] = $key.'='.(is_scalar($value) ? (string) $value : json_encode($value));
		}

		error_log(implode(' ', $parts));
	}

	/**
	 * Run the body of a JSON action, turning any exception into a clean error payload
	 * rather than a 500 and an empty response the console cannot explain.
	 */
	protected function guard(callable $body): void {
		try {
			$body();
		}
		catch (\Throwable $e) {
			$this->log('exception', [
				'action' => $this->getAction(),
				'class' => get_class($e),
				'message' => $e->getMessage(),
				'at' => $e->getFile().':'.$e->getLine()
			]);

			$this->fail($e->getMessage().' ('.get_class($e).' at '
				.basename($e->getFile()).':'.$e->getLine().')'
			);

			return;
		}

		// CController::run() returns null when an action finishes without setting a
		// response, and the router turns that into "Unexpected response for action X"
		// with nothing else attached. That message should never be the last word on a
		// bug in here, so make the gap explicit.
		if ($this->getResponse() === null) {
			$this->log('no-response', ['action' => $this->getAction()]);
			$this->fail(_s('%1$s finished without producing a response. This is a bug in the module, not a device problem.',
				$this->getAction()
			));
		}
	}
}
