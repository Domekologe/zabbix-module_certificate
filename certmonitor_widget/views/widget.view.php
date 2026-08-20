<?php
/*
 * NOTE: no declare(strict_types = 1) here on purpose. Zabbix core view files and the CView/CTag
 * helpers they call run without strict types, and passing a request string into a core function
 * that declares int would raise a TypeError - a fatal HTTP 500 - only in a strict file. The
 * controllers and helper classes of this module do use strict types.
 *
 * Author: Domekologe <support@domekologe.eu>
 */
/**
 * Certificate Monitor - dashboard widget view.
 *
 * Shows the certificates that expire soonest. The "Days left" cell is coloured with the thresholds of
 * that very host, i.e. with its own {$CERT.EXPIRY.WARN}, {$CERT.EXPIRY.AVG} and {$CERT.EXPIRY.CRIT}
 * macros, so the colours mean the same thing here as on the module's own list page.
 *
 * @var CView $this
 * @var array $data
 */

$table = (new CTableInfo())
	->setHeader([_('Website'), _('Days left'), _('Expires on'), _('Validation')])
	->addClass(ZBX_STYLE_LIST_TABLE_STICKY_HEADER);

if ($data['error'] !== null) {
	$table->setNoDataMessage($data['error'], null, ZBX_ICON_SEARCH_LARGE);
}
else {
	foreach ($data['certificates'] as $certificate) {
		$days_left = $certificate['days_left'];

		if ($days_left < 0 || $days_left < $certificate['crit_days']) {
			$days_style = ZBX_STYLE_RED;
		}
		elseif ($days_left < $certificate['avg_days']) {
			$days_style = ZBX_STYLE_ORANGE;
		}
		elseif ($days_left < $certificate['warn_days']) {
			$days_style = ZBX_STYLE_YELLOW;
		}
		else {
			$days_style = ZBX_STYLE_GREEN;
		}

		// The detail page belongs to the separate frontend module. When only the widget is installed,
		// the name is shown as plain text instead of a dead link.
		$name = $data['has_detail_page']
			? new CLink($certificate['name'], (new CUrl('zabbix.php'))
				->setArgument('action', 'certmonitor.view')
				->setArgument('hostid', $certificate['hostid'])
			)
			: new CSpan($certificate['name']);

		$name->addClass(ZBX_STYLE_WORDBREAK);

		if ($certificate['status'] == HOST_STATUS_NOT_MONITORED) {
			$name->addClass(ZBX_STYLE_COLOR_NEGATIVE);
		}

		switch ($certificate['validation']) {
			case 'valid':
				$validation = (new CSpan(_('valid')))->addClass(ZBX_STYLE_GREEN);
				break;

			case 'valid-but-self-signed':
				$validation = (new CSpan(_('valid but self-signed')))->addClass(ZBX_STYLE_ORANGE);
				break;

			case 'invalid':
				$validation = (new CSpan(_('invalid')))->addClass(ZBX_STYLE_RED);
				break;

			default:
				$validation = (new CSpan(_('no data')))->addClass(ZBX_STYLE_GREY);
		}

		$table->addRow([
			$name,
			(new CSpan($days_left < 0 ? _('expired') : $days_left))->addClass($days_style),
			zbx_date2str(DATE_TIME_FORMAT, $certificate['not_after']),
			$validation
		]);
	}
}

(new CWidgetView($data))
	->addItem($table)
	->show();
