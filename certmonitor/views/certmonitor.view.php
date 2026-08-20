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
 * Certificate Monitor - detail page of a single monitored website.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\CertMonitor\Includes\CertHelper;

$host = $data['host'];

$controls = new CList();

if ($data['can_edit']) {
	$controls->addItem(new CRedirectButton(_('Edit'),
		(new CUrl('zabbix.php'))
			->setArgument('action', 'certmonitor.edit')
			->setArgument('hostid', $host['hostid'])
	));
}

$html_page = (new CHtmlPage())
	->setTitle(_s('Certificate: %1$s', $host['name']))
	->setDocUrl('https://www.zabbix.com/documentation/current/en/manual/guides/monitor_certificate')
	->setControls(
		(new CTag('nav', true,
			$controls
				->addItem(new CRedirectButton(_('Latest data'),
					(new CUrl('zabbix.php'))
						->setArgument('action', 'latest.view')
						->setArgument('hostids', [$host['hostid']])
						->setArgument('filter_set', '1')
				))
				->addItem(new CRedirectButton(_('Back to list'),
					(new CUrl('zabbix.php'))->setArgument('action', 'certmonitor.list')
				))
		))->setAttribute('aria-label', _('Content controls'))
	);

/**
 * Render a value that may be missing.
 *
 * @param string|null $value
 *
 * @return CSpan|string
 */
$orNoData = static function ($value) {
	return ($value === null || $value === '')
		? (new CSpan(_('No data')))->addClass(ZBX_STYLE_GREY)
		: $value;
};

/**
 * Build a two column key/value table.
 *
 * @param array $rows
 *
 * @return CTableInfo
 */
$kvTable = static function (array $rows): CTableInfo {
	$table = (new CTableInfo())->setHeader([_('Setting'), _('Value')]);

	foreach ($rows as $label => $value) {
		$table->addRow([
			(new CCol($label))->addClass(ZBX_STYLE_NOWRAP),
			(new CCol($value))->addClass(ZBX_STYLE_WORDBREAK)
		]);
	}

	return $table;
};

// ---------------------------------------------------------------- check target --

$target = $data['website'] !== '' ? $data['website'].':'.$data['port'] : _('not set');

$group_names = [];

foreach ($host['hostgroups'] as $group) {
	$group_names[] = $group['name'];
}

$interface = $data['agent_interface'];

if ($interface !== null) {
	$agent_target = ((int) $interface['useip'] === INTERFACE_USE_IP ? $interface['ip'] : $interface['dns'])
		.':'.$interface['port'];

	$agent_value = [$agent_target, ' ', (new CSpan(
		(int) $interface['useip'] === INTERFACE_USE_IP ? _('IP') : _('DNS')
	))->addClass(ZBX_STYLE_GREY)];
}
else {
	$agent_value = (new CSpan(_('No agent interface')))->addClass(ZBX_STYLE_RED);
}

$validation_value = $data['ignore_validation']
	? (new CSpan(_('ignored - the validation trigger exists but is disabled')))->addClass(ZBX_STYLE_ORANGE)
	: (new CSpan(_('enforced - a problem is raised when validation fails')))->addClass(ZBX_STYLE_GREEN);

$configuration = $kvTable([
	_('Monitored website') => $target,
	_('Address override') => $orNoData($data['address'] !== '' ? $data['address'] : null),
	_('Checked by agent at') => $agent_value,
	_('Host (technical name)') => $host['host'],
	_('Host (visible name)') => $host['name'],
	_('Host groups') => implode(', ', $group_names),
	_('Host status') => (int) $host['status'] === HOST_STATUS_MONITORED
		? (new CSpan(_('Enabled')))->addClass(ZBX_STYLE_GREEN)
		: (new CSpan(_('Disabled')))->addClass(ZBX_STYLE_RED),
	_('Description') => $orNoData($host['description']),
	_('Warning thresholds') => $data['warn_days'].' / '.$data['avg_days'].' / '.$data['crit_days'].' '
		._('days (warning / average / high)'),
	_('Certificate validation') => $validation_value
]);

// ------------------------------------------------------------------- macros --

$macro_table = (new CTableInfo())
	->setHeader([_('Macro'), _('Value'), _('Description')])
	->setNoDataMessage(_('No {$CERT.*} macros are set on this host.'));

foreach ($data['cert_macros'] as $macro) {
	$macro_table->addRow([
		(new CCol($macro['macro']))->addClass(ZBX_STYLE_NOWRAP),
		(new CCol($orNoData($macro['value'])))->addClass(ZBX_STYLE_WORDBREAK),
		(new CCol($macro['description'] ?? ''))->addClass(ZBX_STYLE_WORDBREAK)
	]);
}

// -------------------------------------------------------------------- items --

$item_table = (new CTableInfo())
	->setHeader([_('Item'), _('Key'), _('Interval'), _('Status'), _('Last value'), _('Last check')])
	->setNoDataMessage(_('This host has no items.'));

foreach ($data['items'] as $item) {
	$status = (int) $item['status'] === ITEM_STATUS_ACTIVE
		? (new CSpan(_('Enabled')))->addClass(ZBX_STYLE_GREEN)
		: (new CSpan(_('Disabled')))->addClass(ZBX_STYLE_RED);

	// An item that collected data but reports an error is worth showing prominently.
	if ($item['error'] !== '') {
		$status = [$status, ' ', (new CSpan(_('error')))
			->addClass(ZBX_STYLE_RED)
			->setHint($item['error'])
		];
	}

	$value = $item['last_value'];

	if ($value !== null && $item['units'] === 'unixtime' && ctype_digit((string) $value)) {
		$value = zbx_date2str(DATE_TIME_FORMAT, (int) $value);
	}

	$item_table->addRow([
		(new CCol($item['name']))->addClass(ZBX_STYLE_WORDBREAK),
		(new CCol((new CSpan($item['key_']))->addClass('certmonitor-mono')))->addClass(ZBX_STYLE_WORDBREAK),
		(new CCol($item['delay'] !== '' ? $item['delay'] : _('depends')))->addClass(ZBX_STYLE_NOWRAP),
		$status,
		(new CCol($orNoData($value)))->addClass(ZBX_STYLE_WORDBREAK),
		(new CCol($item['last_clock'] !== null
			? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $item['last_clock'])
			: $orNoData(null)
		))->addClass(ZBX_STYLE_NOWRAP)
	]);
}

// ----------------------------------------------------------------- triggers --

$trigger_table = (new CTableInfo())
	->setHeader([_('Trigger'), _('Severity'), _('Status'), _('State'), _('Expression')])
	->setNoDataMessage(_('This host has no triggers.'));

foreach ($data['triggers'] as $trigger) {
	$severity = (new CSpan(CSeverityHelper::getName((int) $trigger['priority'])))
		->addClass(CSeverityHelper::getStyle((int) $trigger['priority']));

	$status = (int) $trigger['status'] === TRIGGER_STATUS_ENABLED
		? (new CSpan(_('Enabled')))->addClass(ZBX_STYLE_GREEN)
		: (new CSpan(_('Disabled')))->addClass(ZBX_STYLE_RED);

	$state = (int) $trigger['value'] === TRIGGER_VALUE_TRUE
		? (new CSpan(_('PROBLEM')))->addClass(ZBX_STYLE_RED)
		: (new CSpan(_('OK')))->addClass(ZBX_STYLE_GREEN);

	$description = [(new CDiv($trigger['description']))->addClass(ZBX_STYLE_WORDBREAK)];

	if ($trigger['comments'] !== '') {
		$description[] = (new CDiv($trigger['comments']))
			->addClass(ZBX_STYLE_GREY)
			->addClass(ZBX_STYLE_WORDBREAK);
	}

	$trigger_table->addRow([
		$description,
		(new CCol($severity))->addClass(ZBX_STYLE_NOWRAP),
		(new CCol($status))->addClass(ZBX_STYLE_NOWRAP),
		(new CCol($state))->addClass(ZBX_STYLE_NOWRAP),
		(new CCol((new CSpan($trigger['expression']))->addClass('certmonitor-mono')))->addClass(ZBX_STYLE_WORDBREAK)
	]);
}

// -------------------------------------------------------------- certificate --

$certificate = $data['certificate'];

$certificate_notes = [];

// The item is unsupported: its error is the single most useful thing to show.
if ($certificate['item_error'] !== '') {
	$certificate_notes[] = (new CDiv([
		(new CSpan(_('The master item is unsupported:')))->addClass(ZBX_STYLE_RED),
		' ',
		(new CSpan($certificate['item_error']))->addClass('certmonitor-mono')
	]))->addClass('certmonitor-note');
}

if ($certificate['item_status'] !== null && $certificate['item_status'] !== ITEM_STATUS_ACTIVE) {
	$certificate_notes[] = (new CDiv(
		(new CSpan(_('The master item is disabled, so no new certificate data is collected.')))
			->addClass(ZBX_STYLE_ORANGE)
	))->addClass('certmonitor-note');
}

if ($certificate['json_error'] !== '') {
	$certificate_notes[] = (new CDiv(
		(new CSpan($certificate['json_error']))->addClass(ZBX_STYLE_RED)
	))->addClass('certmonitor-note');
}

if ($certificate['source'] === 'items') {
	$certificate_notes[] = (new CDiv(
		(new CSpan(
			_('The raw certificate document is not stored for this host, so the values below were read from the individual items instead. Open this website with "Edit" and save it once to enable raw value storage.')
		))->addClass(ZBX_STYLE_GREY)
	))->addClass('certmonitor-note');
}

if ($certificate['source'] === '') {
	$certificate_table = (new CTableInfo())->setNoDataMessage(
		_('No certificate has been collected yet. The first value appears after the master item has been polled once - use "Check now" to poll immediately.')
	);
}
else {
	$fields = $certificate['fields'];

	/**
	 * Format a UNIX timestamp coming from the certificate document.
	 *
	 * @param string|null $timestamp
	 *
	 * @return string|CSpan
	 */
	$asDate = static function ($timestamp) use ($orNoData) {
		if ($timestamp === null || !ctype_digit((string) $timestamp)) {
			return $orNoData(null);
		}

		return zbx_date2str(DATE_TIME_FORMAT_SECONDS, (int) $timestamp);
	};

	// Days remaining, coloured with the thresholds of this very host.
	if ($certificate['days_left'] === null) {
		$days_value = $orNoData(null);
	}
	else {
		$days_left = (int) $certificate['days_left'];

		$warn_days = ctype_digit($data['warn_days']) ? (int) $data['warn_days'] : CertHelper::DEFAULT_WARN_DAYS;
		$avg_days = ctype_digit($data['avg_days']) ? (int) $data['avg_days'] : CertHelper::DEFAULT_AVG_DAYS;
		$crit_days = ctype_digit($data['crit_days']) ? (int) $data['crit_days'] : CertHelper::DEFAULT_CRIT_DAYS;

		$days_value = (new CSpan($days_left < 0
			? _s('expired %1$s days ago', (string) abs($days_left))
			: _n('%1$s day', '%1$s days', $days_left)
		))->addClass(CertHelper::daysLeftStyle($days_left, $warn_days, $avg_days, $crit_days));
	}

	$validation_result = $fields['result_value'];

	$result_value = $validation_result === null || $validation_result === ''
		? $orNoData(null)
		: (new CSpan($validation_result))->addClass(CertHelper::validationStyle($validation_result));

	$certificate_rows = [
		_('Version') => $orNoData($fields['version']),
		_('Serial number') => $fields['serial_number'] === null
			? $orNoData(null)
			: (new CSpan($fields['serial_number']))->addClass('certmonitor-mono'),
		_('Signature algorithm') => $orNoData($fields['signature_algorithm']),
		_('Public key algorithm') => $orNoData($fields['public_key_algorithm']),
		_('Subject') => $orNoData($fields['subject']),
		_('Issuer') => $orNoData($fields['issuer']),
		_('Subject alternative names') => $orNoData($fields['alternative_names']),
		_('Valid from (reported)') => $orNoData($fields['not_before_value']),
		_('Valid from (timestamp)') => $asDate($fields['not_before_timestamp']),
		_('Valid until (reported)') => $orNoData($fields['not_after_value']),
		_('Valid until (timestamp)') => $asDate($fields['not_after_timestamp']),
		_('Days remaining') => $days_value,
		_('Validation result') => $result_value,
		_('Validation message') => $orNoData($fields['result_message']),
		_('Fingerprint (SHA-1)') => $fields['sha1_fingerprint'] === null
			? $orNoData(null)
			: (new CSpan($fields['sha1_fingerprint']))->addClass('certmonitor-mono'),
		_('Fingerprint (SHA-256)') => $fields['sha256_fingerprint'] === null
			? $orNoData(null)
			: (new CSpan($fields['sha256_fingerprint']))->addClass('certmonitor-mono'),
		_('Collected at') => $certificate['clock'] !== null
			? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $certificate['clock'])
			: $orNoData(null)
	];

	// Certificates for appliances often carry IP SANs next to the DNS names. They matter whenever the
	// service is reached by address, so they get their own row directly below the alternative names -
	// but only when the certificate actually contains one, otherwise it is just an empty row.
	if (array_key_exists('alternative_names_ips', $fields) && $fields['alternative_names_ips'] !== null) {
		$insert_after = _('Subject alternative names');
		$ip_label = _('IP addresses in certificate');
		$ip_value = (new CSpan($fields['alternative_names_ips']))->addClass('certmonitor-mono');

		$ordered = [];

		foreach ($certificate_rows as $label => $value) {
			$ordered[$label] = $value;

			if ($label === $insert_after) {
				$ordered[$ip_label] = $ip_value;
			}
		}

		$certificate_rows = array_key_exists($ip_label, $ordered)
			? $ordered
			: $certificate_rows + [$ip_label => $ip_value];
	}

	$certificate_table = $kvTable($certificate_rows);
}

$certificate_section = array_merge($certificate_notes, [$certificate_table]);

if ($certificate['itemid'] !== null) {
	$certificate_section[] = (new CDiv(
		new CLink(_('Raw values of the master item'), (new CUrl('history.php'))
			->setArgument('action', HISTORY_VALUES)
			->setArgument('itemids', [$certificate['itemid']])
		)
	))->addClass('certmonitor-note');
}

// A hand-edited macro order silently breaks the severity escalation, so it is reported here as well.
if ($data['threshold_warning'] !== '') {
	$certificate_section[] = (new CDiv(
		(new CSpan($data['threshold_warning']))->addClass(ZBX_STYLE_RED)
	))->addClass('certmonitor-note');
}

// ------------------------------------------------------------------ assembly --

$section = static function (string $title, $content): array {
	return [
		(new CTag('h4', true, $title))->addClass('certmonitor-section'),
		$content
	];
};

$html_page->addItem(new CDiv([
	$section(_('Certificate'), $certificate_section),
	$section(_('Configuration'), $configuration),
	$section(_('User macros'), $macro_table),
	$section(_('Items and latest values'), $item_table),
	$section(_('Triggers'), $trigger_table)
]));

if ($data['can_edit']) {
	$html_page->addItem(
		(new CDiv([
			new CRedirectButton(_('Configure items'),
				(new CUrl('zabbix.php'))
					->setArgument('action', 'item.list')
					->setArgument('context', 'host')
					->setArgument('filter_hostids', [$host['hostid']])
					->setArgument('filter_set', '1')
			),
			' ',
			new CRedirectButton(_('Configure triggers'),
				(new CUrl('zabbix.php'))
					->setArgument('action', 'trigger.list')
					->setArgument('context', 'host')
					->setArgument('filter_hostids', [$host['hostid']])
					->setArgument('filter_set', '1')
			)
		]))->addClass('certmonitor-actions')
	);
}

$html_page->show();
