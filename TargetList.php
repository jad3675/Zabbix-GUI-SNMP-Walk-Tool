<?php declare(strict_types = 1);

namespace Modules\SnmpWalk\Actions;

use API;
use Modules\SnmpWalk\Includes\CWalkService;

/**
 * Templates and template groups the user can write to.
 *
 * Feeds the target picker. Only editable templates are returned, so the picker cannot
 * offer somewhere the create would then be refused.
 *
 * The list is capped and searched server-side rather than sent whole and filtered in the
 * browser. An MSP install has thousands of templates, and an alphabetical first hundred
 * silently stops somewhere in the D's, which looks like the list being complete.
 */
class TargetList extends CWalkAction {

	protected function checkInput(): bool {
		return $this->validate([
			'search' => 'string',
			'groups' => 'in 0,1'
		]);
	}

	protected function doAction(): void {
		$this->guard(function (): void {
			$search = trim($this->getInput('search', ''));
			$limit = CWalkService::templateLimit();

			$options = [
				'output' => ['templateid', 'name'],
				'editable' => true,
				'sortfield' => 'name',
				// One more than the limit, so "there are more" is a fact rather than an
				// inference from hitting the cap exactly.
				'limit' => $limit + 1
			];

			if ($search !== '') {
				// Search both the visible name and the technical host name. On an MSP
				// install the two frequently differ, and an engineer types whichever
				// they happen to remember.
				$options['search'] = ['name' => $search, 'host' => $search];
				$options['searchByAny'] = true;
			}

			$found = API::Template()->get($options);
			$truncated = count($found) > $limit;
			$found = array_slice($found, 0, $limit);

			$templates = [];

			foreach ($found as $template) {
				$templates[] = [
					'id' => $template['templateid'],
					'name' => $template['name']
				];
			}

			$result = [
				'templates' => $templates,
				'truncated' => $truncated,
				'limit' => $limit
			];

			// Groups do not change while a dialog is open, so they are fetched once and
			// omitted from every subsequent search.
			if ((int) $this->getInput('groups', 1) === 1) {
				$groups = [];

				// TemplateGroup split out from HostGroup in 6.2; fall back for older
				// builds rather than assuming either one is present.
				$group_api = method_exists(API::class, 'TemplateGroup')
					? API::TemplateGroup()
					: API::HostGroup();

				foreach ($group_api->get([
					'output' => ['groupid', 'name'],
					'editable' => true,
					'sortfield' => 'name',
					'limit' => $limit
				]) as $group) {
					$groups[] = [
						'id' => $group['groupid'],
						'name' => $group['name']
					];
				}

				$result['groups'] = $groups;
			}

			$this->json($result);
		});
	}
}
