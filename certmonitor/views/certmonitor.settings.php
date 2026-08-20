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
 * Certificate Monitor - module settings.
 *
 * @var CView $this
 * @var array $data
 */

$html_page = (new CHtmlPage())
	->setTitle(_('Certificate Monitor settings'))
	->setDocUrl('https://www.zabbix.com/documentation/current/en/manual/modules')
	->setControls(
		(new CTag('nav', true,
			(new CList())->addItem(new CRedirectButton(_('Back to list'),
				(new CUrl('zabbix.php'))->setArgument('action', 'certmonitor.list')
			))
		))->setAttribute('aria-label', _('Content controls'))
	);

$group_select = (new CSelect('default_groupid'))
	->setFocusableElementId('label-default-groupid')
	->setValue($data['default_groupid'] !== '' ? $data['default_groupid'] : '')
	->addOption(new CSelectOption('', _('not preselected')));

foreach ($data['host_groups'] as $group) {
	$group_select->addOption(new CSelectOption($group['groupid'], $group['name']));
}

// The trigger severities offered for the two "changed" triggers. CSeverityHelper::getName() returns the
// name configured in Administration -> General -> Trigger displaying options, so a renamed severity is
// shown here with its own name.
$severity_options = [];

for ($severity = TRIGGER_SEVERITY_NOT_CLASSIFIED; $severity < TRIGGER_SEVERITY_COUNT; $severity++) {
	$severity_options[(string) $severity] = CSeverityHelper::getName($severity);
}

$issuer_severity_select = (new CSelect('default_issuer_severity'))
	->setFocusableElementId('label-default-issuer-severity')
	->setValue($data['default_issuer_severity'])
	->addOptions(CSelect::createOptionsFromArray($severity_options));

$fingerprint_severity_select = (new CSelect('default_fingerprint_severity'))
	->setFocusableElementId('label-default-fingerprint-severity')
	->setValue($data['default_fingerprint_severity'])
	->addOptions(CSelect::createOptionsFromArray($severity_options));

$intro = (new CDiv([
	(new CDiv(
		_('These values only pre-fill the "Add website" form. Changing them never modifies an existing website: to change a website, open it with "Edit" in the list.')
	))->addClass('certmonitor-hint'),
	(new CDiv(
		_('The settings are stored in the "config" section of this module, which Zabbix keeps in its database. Writing them requires Super admin permissions.')
	))->addClass('certmonitor-hint')
]))->addClass('certmonitor-intro');

$form_grid = (new CFormGrid())
	->addItem([
		(new CLabel(_('Default port'), 'default_port'))->setAsteriskMark(),
		new CFormField(
			(new CNumericBox('default_port', $data['default_port'], 5, false, false, false))
				->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
				->setAriaRequired()
		)
	])
	->addItem([
		(new CLabel(_('Default Zabbix agent address'), 'default_agent_address'))->setAsteriskMark(),
		new CFormField([
			(new CTextBox('default_agent_address', $data['default_agent_address'], false, 255))
				->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
				->setAriaRequired(),
			(new CDiv(
				_('Address of the machine running Zabbix agent 2 that will perform the checks - not the monitored website.')
			))->addClass('certmonitor-hint')
		])
	])
	->addItem([
		(new CLabel(_('Default Zabbix agent port'), 'default_agent_port'))->setAsteriskMark(),
		new CFormField(
			(new CNumericBox('default_agent_port', $data['default_agent_port'], 5, false, false, false))
				->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
				->setAriaRequired()
		)
	])
	->addItem([
		new CLabel(_('Default host group'), 'label-default-groupid'),
		new CFormField([
			$group_select,
			(new CDiv(
				_('Preselected in the "Add website" form. Only host groups you can write to are listed.')
			))->addClass('certmonitor-hint')
		])
	])
	->addItem([
		(new CLabel(_('Default update interval'), 'default_delay'))->setAsteriskMark(),
		new CFormField([
			(new CTextBox('default_delay', $data['default_delay'], false, 32))
				->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
				->setAriaRequired(),
			(new CDiv(
				_('Update interval of the master item, for example "1h", "30m" or "3600". Certificates change rarely, so a long interval is normal.')
			))->addClass('certmonitor-hint')
		])
	])
	->addItem([
		new CLabel(_('Host name prefix'), 'host_prefix'),
		new CFormField([
			(new CTextBox('host_prefix', $data['host_prefix'], false, 32))
				->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
				->setAttribute('placeholder', $data['builtin_defaults']['host_prefix']),
			(new CDiv(
				_('Prepended to the technical host name of newly created hosts, for example "cert_" produces "cert_www.example.com_443". Letters, digits, dots, dashes and underscores only.')
			))->addClass('certmonitor-hint')
		])
	])
	->addItem([
		new CLabel(_('Ignore certificate validation errors by default'), 'default_ignore_validation'),
		new CFormField(
			(new CCheckBox('default_ignore_validation'))->setChecked($data['default_ignore_validation'])
		)
	])
	->addItem(
		(new CFormFieldset(_('Default security triggers')))
			->addItem(
				(new CFormGrid())
					->addItem([
						new CLabel(_('Issuer changed'), 'default_sec_issuer_changed'),
						new CFormField(
							(new CCheckBox('default_sec_issuer_changed'))
								->setChecked($data['default_sec_issuer_changed'])
						)
					])
					->addItem([
						(new CLabel(_('Severity of "issuer changed"'), 'label-default-issuer-severity')),
						new CFormField($issuer_severity_select)
					])
					->addItem([
						new CLabel(_('Certificate replaced (SHA-256 fingerprint changed)'),
							'default_sec_fingerprint_changed'
						),
						new CFormField([
							(new CCheckBox('default_sec_fingerprint_changed'))
								->setChecked($data['default_sec_fingerprint_changed']),
							(new CDiv(
								_('Every legitimate renewal replaces the certificate and therefore fires this trigger as well. That is why its default severity is only WARNING.')
							))->addClass('certmonitor-hint')
						])
					])
					->addItem([
						(new CLabel(_('Severity of "certificate replaced"'),
							'label-default-fingerprint-severity'
						)),
						new CFormField($fingerprint_severity_select)
					])
					->addItem([
						new CLabel(_('Weak public key algorithm'), 'default_sec_weak_key'),
						new CFormField(
							(new CCheckBox('default_sec_weak_key'))->setChecked($data['default_sec_weak_key'])
						)
					])
					->addItem([
						(new CLabel(_('Weak public key algorithms'), 'default_weak_key_algorithms'))
							->setAsteriskMark(),
						new CFormField([
							(new CTextBox('default_weak_key_algorithms', $data['default_weak_key_algorithms'],
								false, 255
							))
								->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
								->setAriaRequired(),
							(new CDiv(
								_('Case-insensitive regular expression, stored per host as {$CERT.KEY.ALGO.WEAK}. The agent reports the key ALGORITHM only - "RSA", "DSA", "ECDSA", "Ed25519" or "Unknown" - and never the key LENGTH, so a "below 2048 bit" check is not possible from this item.')
							))->addClass('certmonitor-hint')
						])
					])
					->addItem([
						new CLabel(_('Weak signature algorithm'), 'default_sec_weak_signature'),
						new CFormField(
							(new CCheckBox('default_sec_weak_signature'))
								->setChecked($data['default_sec_weak_signature'])
						)
					])
					->addItem([
						(new CLabel(_('Weak signature algorithms'), 'default_weak_signature_algorithms'))
							->setAsteriskMark(),
						new CFormField([
							(new CTextBox('default_weak_signature_algorithms',
								$data['default_weak_signature_algorithms'], false, 255
							))
								->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
								->setAriaRequired(),
							(new CDiv(
								_('Case-insensitive regular expression, stored per host as {$CERT.SIG.ALGO.WEAK}. It is matched against values such as "SHA1-RSA", "MD5-RSA" or "SHA256-RSA".')
							))->addClass('certmonitor-hint')
						])
					])
					->addItem([
						new CLabel(''),
						new CFormField(
							(new CDiv(
								_('These values pre-fill the "Add website" form and are applied to an existing website when it is opened with "Edit" and saved. The severities are only used while a trigger is created; changing a severity here never rewrites an existing trigger.')
							))->addClass('certmonitor-hint')
						)
					])
			)
	)
	->addItem(
		(new CFormFieldset(_('Default warning thresholds')))
			->addItem(
				(new CFormGrid())
					->addItem([
						(new CLabel(_('Warning (days)'), 'default_warn_days'))->setAsteriskMark(),
						new CFormField(
							(new CNumericBox('default_warn_days', $data['default_warn_days'], 4, false, false,
								false
							))
								->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
								->setAriaRequired()
						)
					])
					->addItem([
						(new CLabel(_('Average (days)'), 'default_avg_days'))->setAsteriskMark(),
						new CFormField(
							(new CNumericBox('default_avg_days', $data['default_avg_days'], 4, false, false, false))
								->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
								->setAriaRequired()
						)
					])
					->addItem([
						(new CLabel(_('High (days)'), 'default_crit_days'))->setAsteriskMark(),
						new CFormField([
							(new CNumericBox('default_crit_days', $data['default_crit_days'], 4, false, false,
								false
							))
								->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
								->setAriaRequired(),
							(new CDiv(_('Warning > average > high is required.')))->addClass('certmonitor-hint')
						])
					])
			)
	);

$form = (new CForm())
	->setName('certmonitor_settings')
	->setAction((new CUrl('zabbix.php'))->setArgument('action', 'certmonitor.settings.update')->getUrl())
	->addItem((new CVar(CSRF_TOKEN_NAME, CCsrfTokenHelper::get('certmonitor.settings.update')))->removeId())
	->addItem($form_grid)
	->addItem(
		new CFormActions(
			new CSubmitButton(_('Update'), 'action', 'certmonitor.settings.update'),
			[new CRedirectButton(_('Cancel'),
				(new CUrl('zabbix.php'))->setArgument('action', 'certmonitor.list')
			)]
		)
	);

$html_page
	->addItem($intro)
	->addItem($form)
	->show();
