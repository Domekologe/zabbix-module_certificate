<?php declare(strict_types = 1);
/**
 * Certificate Monitor - Zabbix frontend module.
 *
 * Registers the "Certificates" entry in the "Monitoring" section of the main menu.
 *
 * Compatible with Zabbix 7.2 and 7.4 (manifest_version 2.0).
 *
 * Author: Domekologe <support@domekologe.eu>
 */

namespace Modules\CertMonitor;

use APP;
use CMenu;
use CMenuItem;
use Zabbix\Core\CModule;

class Module extends CModule {

	/**
	 * Initialize the module: insert the "Certificates" item into the "Monitoring" submenu.
	 *
	 * The item is inserted after "Latest data". If that label cannot be found (for example, because the
	 * user role hides that page, or because of a different locale), CMenu::insertAfter() appends the item
	 * at the end of the submenu instead, so the entry is never lost.
	 */
	public function init(): void {
		$menu_item = (new CMenuItem(_('Certificates')))
			->setAction('certmonitor.list')
			->setAliases([
				'certmonitor.view',
				'certmonitor.edit',
				'certmonitor.create',
				'certmonitor.import',
				'certmonitor.import.create',
				'certmonitor.update',
				'certmonitor.delete',
				'certmonitor.enable',
				'certmonitor.disable',
				'certmonitor.execute',
				'certmonitor.settings',
				'certmonitor.settings.update'
			]);

		$monitoring = APP::Component()->get('menu.main')->findOrAdd(_('Monitoring'));

		if (!$monitoring->hasSubMenu()) {
			$monitoring->setSubMenu(new CMenu());
		}

		$monitoring
			->getSubMenu()
			->insertAfter(_('Latest data'), $menu_item);
	}
}
