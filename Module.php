<?php declare(strict_types = 1);

namespace Modules\SnmpWalk;

use APP;
use CMenu;
use CMenuItem;
use Zabbix\Core\CModule;

/**
 * SNMP Walk frontend module.
 *
 * Adds Monitoring > SNMP walk (the walk console) and Monitoring > SNMP walk > MIBs
 * (the MIB library used for translation).
 */
class Module extends CModule {

	public function init(): void {
		APP::Component()->get('menu.main')
			->findOrAdd(_('Monitoring'))
			->getSubmenu()
			->add((new CMenuItem(_('SNMP walk')))->setSubMenu(new CMenu([
				(new CMenuItem(_('Console')))->setAction('snmpwalk.view'),
				(new CMenuItem(_('MIBs')))->setAction('snmpwalk.mib.view')
			])));
	}
}
