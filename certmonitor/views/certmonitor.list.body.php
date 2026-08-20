<?php
/*
 * Certificate Monitor - body of the list page.
 *
 * Kept in its own file so that certmonitor.list.php can wrap it in an error boundary. No
 * declare(strict_types = 1) on purpose: Zabbix core view helpers run without strict types.
 *
 * @var CView $this
 * @var array $data
 *
 * Author: Domekologe <support@domekologe.eu>
 */

use Modules\CertMonitor\Includes\CertHelper;

if ($data['uncheck']) {
	uncheckTableRows('certmonitor');
}

$list_url = (new CUrl('zabbix.php'))->setArgument('action', 'certmonitor.list');

$html_page = (new CHtmlPage())
	->setTitle(_('Certificates'))
	->setDocUrl('https://www.zabbix.com/documentation/current/en/manual/guides/monitor_certificate');

// ------------------------------------------------------------------- controls --

$controls = new CList();

if ($data['can_edit']) {
	$controls->addItem(new CRedirectButton(_('Add website'),
		(new CUrl('zabbix.php'))->setArgument('action', 'certmonitor.edit')
	));

	$controls->addItem(
		(new CRedirectButton(_('Bulk import'),
			(new CUrl('zabbix.php'))->setArgument('action', 'certmonitor.import')
		))
			->addClass(ZBX_STYLE_BTN_ALT)
			->setAttribute('aria-label', _('Add several websites at once from a list or a CSV file'))
	);
}

$controls->addItem(
	(new CRedirectButton(_('Export to CSV'),
		(new CUrl('zabbix.php'))->setArgument('action', 'certmonitor.list.csv')
	))
		->addClass(ZBX_STYLE_BTN_ALT)
		->setAttribute('aria-label', _('Export the filtered list to a CSV file'))
);

if ($data['can_settings']) {
	$controls->addItem(
		(new CRedirectButton(_('Settings'),
			(new CUrl('zabbix.php'))->setArgument('action', 'certmonitor.settings')
		))->addClass(ZBX_STYLE_BTN_ALT)
	);
}

$html_page->setControls(
	(new CTag('nav', true, $controls))->setAttribute('aria-label', _('Content controls'))
);

// --------------------------------------------------------------------- filter --

$group_select = (new CSelect('filter_groupid'))
	->setFocusableElementId('filter-groupid')
	->setValue($data['filter']['groupid'])
	->addOption(new CSelectOption('', _('all')));

foreach ($data['host_groups'] as $group) {
	$group_select->addOption(new CSelectOption($group['groupid'], $group['name']));
}

$validation_select = (new CSelect('filter_validation'))
	->setFocusableElementId('filter-validation')
	->setValue($data['filter']['validation'])
	->addOptions(CSelect::createOptionsFromArray([
		'' => _('all'),
		CertHelper::VALIDATION_VALID => _('valid'),
		CertHelper::VALIDATION_SELF_SIGNED => _('valid but self-signed'),
		CertHelper::VALIDATION_INVALID => _('invalid'),
		'nodata' => _('no data')
	]));

$status_select = (new CSelect('filter_status'))
	->setFocusableElementId('filter-status')
	->setValue($data['filter']['status'])
	->addOptions(CSelect::createOptionsFromArray([
		'' => _('any'),
		(string) HOST_STATUS_MONITORED => _('Enabled'),
		(string) HOST_STATUS_NOT_MONITORED => _('Disabled')
	]));

$html_page->addItem(
	(new CFilter())
		->setResetUrl($list_url)
		->setProfile($data['profileIdx'])
		->setActiveTab($data['active_tab'])
		->addVar('action', 'certmonitor.list')
		->addFilterTab(_('Filter'), [
			(new CFormGrid())
				->addClass(CFormGrid::ZBX_STYLE_FORM_GRID_LABEL_WIDTH_TRUE)
				->addItem([
					new CLabel(_('Website'), 'filter_website'),
					new CFormField(
						(new CTextBox('filter_website', $data['filter']['website']))
							->setWidth(ZBX_TEXTAREA_FILTER_SMALL_WIDTH)
							->setAttribute('placeholder', _('substring of website, host or visible name'))
							->setAttribute('autofocus', 'autofocus')
					)
				])
				->addItem([
					new CLabel(_('Host group'), 'filter-groupid'),
					new CFormField($group_select)
				]),
			(new CFormGrid())
				->addClass(CFormGrid::ZBX_STYLE_FORM_GRID_LABEL_WIDTH_TRUE)
				->addItem([
					new CLabel(_('Validation'), 'filter-validation'),
					new CFormField($validation_select)
				])
				->addItem([
					new CLabel(_('Monitoring'), 'filter-status'),
					new CFormField($status_select)
				]),
			(new CFormGrid())
				->addClass(CFormGrid::ZBX_STYLE_FORM_GRID_LABEL_WIDTH_TRUE)
				->addItem([
					new CLabel(_('Expiring within (days)'), 'filter_expiring_days'),
					new CFormField([
						(new CTextBox('filter_expiring_days', $data['filter']['expiring_days']))
							->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
							->setAttribute('inputmode', 'numeric')
							->setAttribute('placeholder', _('any')),
						(new CDiv(
							_('Shows only websites whose certificate expires within this many days. Certificates that already expired are always included.')
						))->addClass('certmonitor-hint')
					])
				])
		])
);

// ------------------------------------------------------------------- summary --

$summary = $data['summary'];

$counters = [
	[_('Total'), $summary['total'], ''],
	[_('OK'), $summary['ok'], ZBX_STYLE_GREEN],
	[_('Expiring soon'), $summary['expiring'], ZBX_STYLE_YELLOW],
	[_('Expired'), $summary['expired'], ZBX_STYLE_RED],
	[_('Invalid'), $summary['invalid'], ZBX_STYLE_RED],
	[_('No data'), $summary['nodata'], ZBX_STYLE_GREY],
	[_('Disabled'), $summary['disabled'], ZBX_STYLE_GREY]
];

$summary_items = [];

foreach ($counters as [$label, $count, $style]) {
	$value = (new CSpan((string) $count))->addClass('certmonitor-summary-value');

	if ($style !== '') {
		$value->addClass($style);
	}

	$summary_items[] = (new CDiv([
		(new CSpan($label))->addClass('certmonitor-summary-label'),
		$value
	]))->addClass('certmonitor-summary-item');
}

$html_page->addItem(
	(new CDiv($summary_items))
		->addClass('certmonitor-summary')
		->setAttribute('role', 'group')
		->setAttribute('aria-label', _('Certificate summary'))
);

// ---------------------------------------------------------------------- table --

$form = (new CForm())
	->setName('certmonitor_list')
	->setAction((new CUrl('zabbix.php'))->getUrl());

$sort_url = $list_url->getUrl();

$header = [];

if ($data['can_edit']) {
	$header[] = (new CColHeader(
		(new CCheckBox('all_websites'))
			->onClick("checkAll('".$form->getName()."', 'all_websites', 'hostids');")
			->setAttribute('aria-label', _('Select all websites'))
	))->addClass(ZBX_STYLE_CELL_WIDTH);
}

$header = array_merge($header, [
	make_sorting_header(_('Website'), 'website', $data['sort'], $data['sortorder'], $sort_url),
	make_sorting_header(_('Host'), 'name', $data['sort'], $data['sortorder'], $sort_url),
	_('Host groups'),
	_('Tags'),
	make_sorting_header(_('Expires on'), 'not_after', $data['sort'], $data['sortorder'], $sort_url),
	make_sorting_header(_('Days left'), 'not_after', $data['sort'], $data['sortorder'], $sort_url),
	make_sorting_header(_('Validation'), 'validation', $data['sort'], $data['sortorder'], $sort_url),
	_('Issuer'),
	make_sorting_header(_('Last checked'), 'last_check', $data['sort'], $data['sortorder'], $sort_url),
	make_sorting_header(_('Monitoring'), 'status', $data['sort'], $data['sortorder'], $sort_url),
	_('Thresholds'),
	_('Actions')
]);

$table = (new CTableInfo())
	->setHeader($header)
	->setPageNavigation($data['paging'])
	->setNoDataMessage(_('No websites match the filter. Use "Add website" to start monitoring a certificate.'));

$execute_token = CCsrfTokenHelper::get('certmonitor.execute');

foreach ($data['websites'] as $website) {
	$target = $website['website'] !== ''
		? CertHelper::makeTarget($website['website'], (int) $website['port'])
		: $website['host'];

	if ($website['address'] !== '') {
		$target .= ' ('.$website['address'].')';
	}

	$detail_url = (new CUrl('zabbix.php'))
		->setArgument('action', 'certmonitor.view')
		->setArgument('hostid', $website['hostid']);

	// Expiry date and remaining days, coloured with the thresholds of this very host.
	if ($website['not_after'] !== null) {
		$days_left = (int) $website['days_left'];

		$crit_days = ctype_digit($website['crit_days'])
			? (int) $website['crit_days']
			: CertHelper::DEFAULT_CRIT_DAYS;
		$avg_days = ctype_digit($website['avg_days'])
			? (int) $website['avg_days']
			: CertHelper::DEFAULT_AVG_DAYS;
		$warn_days = ctype_digit($website['warn_days'])
			? (int) $website['warn_days']
			: CertHelper::DEFAULT_WARN_DAYS;

		$expires_on = zbx_date2str(DATE_TIME_FORMAT, $website['not_after']);
		$days_cell = (new CSpan($days_left < 0 ? _('expired') : (string) $days_left))
			->addClass(CertHelper::daysLeftStyle($days_left, $warn_days, $avg_days, $crit_days))
			->setAttribute('aria-label', $days_left < 0
				? _s('%1$s: the certificate has expired', $target)
				: _s('%1$s: %2$s days left', $target, (string) $days_left)
			);
	}
	else {
		$expires_on = (new CSpan(_('No data')))->addClass(ZBX_STYLE_GREY);
		$days_cell = (new CSpan(_('No data')))->addClass(ZBX_STYLE_GREY);
	}

	// Validation result. The agent's own message says WHY a certificate was rejected - hostname
	// mismatch, unknown authority, expired - so it is attached as a hint instead of being hidden on
	// the detail page. Without it, "invalid" alone sends people hunting.
	if ($website['validation'] !== null && $website['validation'] !== '') {
		$validation_span = (new CSpan($website['validation']))
			->addClass(CertHelper::validationStyle($website['validation']));

		if ($website['message'] !== null && $website['message'] !== '') {
			$validation_span
				->addClass('certmonitor-marker')
				->setHint($website['message']);
		}

		$validation_cell = [$validation_span];
	}
	else {
		$validation_cell = [(new CSpan(_('No data')))->addClass(ZBX_STYLE_GREY)];
	}

	// The validation trigger of this host was created disabled ({$CERT.IGNORE.VALIDATION} = 1).
	if ($website['ignore_validation']) {
		$validation_cell[] = (new CSpan(_('validation ignored')))
			->addClass(ZBX_STYLE_STATUS_GREY)
			->addClass('certmonitor-marker')
			->setHint(
				_('Certificate validation errors are ignored for this website. The validation trigger exists but is disabled.')
			);
	}

	$group_names = [];

	foreach ($website['groups'] as $group) {
		$group_names[] = $group['name'];
	}

	$tag_cells = [];

	foreach ($website['tags'] as $tag) {
		if (CertHelper::isReservedTag((string) $tag['tag'])) {
			continue;
		}

		$tag_cells[] = (new CSpan($tag['value'] !== '' ? $tag['tag'].': '.$tag['value'] : $tag['tag']))
			->addClass(ZBX_STYLE_TAG);
	}

	$thresholds = (new CSpan(
		$website['warn_days'].' / '.$website['avg_days'].' / '.$website['crit_days'].' '._('days')
	))->setHint(_('Warning / average / high, in days before expiry.'));

	$threshold_cell = [$thresholds];

	if ($website['threshold_warning'] !== '') {
		$threshold_cell[] = ' ';
		$threshold_cell[] = (new CSpan(_('check macros')))
			->addClass(ZBX_STYLE_RED)
			->addClass('certmonitor-marker')
			->setHint($website['threshold_warning']);
	}

	// --------------------------------------------------------------- row actions --

	$actions = [new CLink(_('Details'), $detail_url)];

	if ($data['can_edit']) {
		$actions[] = new CLink(_('Edit'), (new CUrl('zabbix.php'))
			->setArgument('action', 'certmonitor.edit')
			->setArgument('hostid', $website['hostid'])
		);
		$actions[] = new CLink(_('Clone'), (new CUrl('zabbix.php'))
			->setArgument('action', 'certmonitor.edit')
			->setArgument('hostid', $website['hostid'])
			->setArgument('clone', 1)
		);
	}

	if ($data['can_execute'] && $website['master_itemid'] !== null) {
		$actions[] = (new CSimpleButton(_('Check now')))
			->addClass(ZBX_STYLE_BTN_LINK)
			->setAttribute('data-certmonitor-action', 'certmonitor.execute')
			->setAttribute('data-hostid', $website['hostid'])
			->setAttribute('data-csrf-name', CSRF_TOKEN_NAME)
			->setAttribute('data-csrf-token', $execute_token)
			->setAttribute('aria-label', _s('Check the certificate of %1$s now', $target));

		$actions[] = new CLink(_('History'), (new CUrl('history.php'))
			->setArgument('action', HISTORY_VALUES)
			->setArgument('itemids', [$website['master_itemid']])
		);
	}

	$action_cell = [];

	foreach ($actions as $index => $action) {
		if ($index > 0) {
			$action_cell[] = (new CSpan('|'))->addClass('certmonitor-action-separator');
		}

		$action_cell[] = $action;
	}

	$status_cell = $website['status'] === HOST_STATUS_MONITORED
		? (new CSpan(_('Enabled')))->addClass(ZBX_STYLE_GREEN)
		: (new CSpan(_('Disabled')))->addClass(ZBX_STYLE_RED);

	$row = [];

	if ($data['can_edit']) {
		$row[] = (new CCheckBox('hostids['.$website['hostid'].']', $website['hostid']))
			->setAttribute('aria-label', _s('Select %1$s', $target));
	}

	$latest_data_url = (new CUrl('zabbix.php'))
		->setArgument('action', 'latest.view')
		->setArgument('hostids', [$website['hostid']])
		->setArgument('filter_set', '1');

	$table->addRow(array_merge($row, [
		(new CCol(new CLink($target, $latest_data_url)))->addClass(ZBX_STYLE_WORDBREAK),
		(new CCol(new CLink($website['name'], $detail_url)))->addClass(ZBX_STYLE_WORDBREAK),
		(new CCol(implode(', ', $group_names)))->addClass(ZBX_STYLE_WORDBREAK),
		(new CCol($tag_cells))->addClass(ZBX_STYLE_WORDBREAK),
		(new CCol($expires_on))->addClass(ZBX_STYLE_NOWRAP),
		(new CCol($days_cell))->addClass(ZBX_STYLE_NOWRAP),
		$validation_cell,
		(new CCol($website['issuer'] !== null ? $website['issuer'] : ''))->addClass(ZBX_STYLE_WORDBREAK),
		(new CCol($website['last_check'] !== null
			? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $website['last_check'])
			: (new CSpan(_('never')))->addClass(ZBX_STYLE_GREY)
		))->addClass(ZBX_STYLE_NOWRAP),
		(new CCol($status_cell))->addClass(ZBX_STYLE_NOWRAP),
		(new CCol($threshold_cell))->addClass(ZBX_STYLE_NOWRAP),
		(new CCol($action_cell))->addClass(ZBX_STYLE_NOWRAP)
	]));
}

// ------------------------------------------------------------- bulk actions --

$bulk_actions = [];

if ($data['can_execute']) {
	$bulk_actions['certmonitor.execute'] = [
		'name' => _('Check now'),
		'csrf_token' => $execute_token
	];
}

if ($data['can_edit']) {
	$bulk_actions['certmonitor.enable'] = [
		'name' => _('Enable'),
		'confirm_singular' => _('Enable monitoring of the selected website?'),
		'confirm_plural' => _('Enable monitoring of the selected websites?'),
		'csrf_token' => CCsrfTokenHelper::get('certmonitor.enable')
	];
	$bulk_actions['certmonitor.disable'] = [
		'name' => _('Disable'),
		'confirm_singular' => _('Disable monitoring of the selected website?'),
		'confirm_plural' => _('Disable monitoring of the selected websites?'),
		'csrf_token' => CCsrfTokenHelper::get('certmonitor.disable')
	];
	$bulk_actions['certmonitor.delete'] = [
		'name' => _('Delete'),
		'confirm_singular' => _('Delete selected website? The host, its items and triggers will be removed.'),
		'confirm_plural' => _('Delete selected websites? The hosts, their items and triggers will be removed.'),
		'csrf_token' => CCsrfTokenHelper::get('certmonitor.delete')
	];
}

if ($bulk_actions && $data['can_edit']) {
	$form->addItem([
		$table,
		new CActionButtonList('action', 'hostids', $bulk_actions, 'certmonitor')
	]);
}
else {
	$form->addItem($table);
}

$html_page
	->addItem($form)
	->show();
