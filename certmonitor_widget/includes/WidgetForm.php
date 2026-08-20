<?php declare(strict_types = 1);
/**
 * Certificate Monitor - configuration form of the dashboard widget.
 *
 * Two settings are offered:
 *   - "Host groups": an optional filter; when empty, every certificate host the user may see is listed.
 *   - "Show lines": how many certificates are shown, ordered by the remaining validity.
 *
 * @see https://www.zabbix.com/documentation/7.4/en/devel/modules/widgets
 *
 * Author: Domekologe <support@domekologe.eu>
 */

namespace Modules\CertMonitorWidget\Includes;

use Zabbix\Widgets\CWidgetField;
use Zabbix\Widgets\CWidgetForm;
use Zabbix\Widgets\Fields\CWidgetFieldIntegerBox;
use Zabbix\Widgets\Fields\CWidgetFieldMultiSelectGroup;

class WidgetForm extends CWidgetForm {

	/**
	 * Number of certificates shown when the widget is added.
	 */
	private const DEFAULT_SHOW_LINES = 10;

	public function addFields(): self {
		return $this
			// A template dashboard has exactly one host, so a host group filter makes no sense there.
			->addField($this->isTemplateDashboard()
				? null
				: new CWidgetFieldMultiSelectGroup('groupids', _('Host groups'))
			)
			->addField(
				(new CWidgetFieldIntegerBox('show_lines', _('Show lines'), ZBX_MIN_WIDGET_LINES,
					ZBX_MAX_WIDGET_LINES
				))
					->setDefault(self::DEFAULT_SHOW_LINES)
					->setFlags(CWidgetField::FLAG_LABEL_ASTERISK)
			);
	}
}
