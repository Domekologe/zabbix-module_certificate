<?php declare(strict_types = 1);
/**
 * Certificate Monitor - dashboard widget.
 *
 * A Zabbix module is either a frontend module or a widget: ui/include/classes/core/CModuleManager.php
 * accepts only the two values CModule::TYPE_MODULE and CModule::TYPE_WIDGET for the manifest key "type",
 * and it instantiates either "Module.php" (extending CModule) or "Widget.php" (extending CWidget) from
 * that single value. A menu entry can therefore only be added by a module, and a dashboard widget can
 * only be provided by a widget - one manifest cannot be both.
 *
 * That is why this widget is a SECOND, separate module. Both directories have to be installed for the
 * full functionality; the widget on its own still works, because it reads nothing but the hosts that
 * carry the tag "certmonitor: website".
 *
 * @see https://www.zabbix.com/documentation/7.4/en/devel/modules
 * @see https://www.zabbix.com/documentation/7.4/en/devel/modules/file_structure/manifest
 *
 * Author: Domekologe <support@domekologe.eu>
 */

namespace Modules\CertMonitorWidget;

use Zabbix\Core\CWidget;

class Widget extends CWidget {

	/**
	 * The name shown in the "Add widget" dialog and used as the default widget header.
	 *
	 * @return string
	 */
	public function getDefaultName(): string {
		return _('Certificate expiry');
	}
}
