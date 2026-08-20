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
 * Certificate Monitor - the "Add website" / "Edit website" form.
 *
 * The same form serves creating and editing; only the title, the target action and the submit button
 * change. In edit mode the technical host name is shown read-only, because it is referenced by every
 * trigger expression of the host and is therefore not editable.
 *
 * @var CView $this
 * @var array $data
 */

$is_edit = $data['is_edit'];

$title = $is_edit ? _('Edit website') : _('Add website');
$submit_action = $is_edit ? 'certmonitor.update' : 'certmonitor.create';
$submit_label = $is_edit ? _('Update') : _('Add');

$html_page = (new CHtmlPage())
	->setTitle($title)
	->setDocUrl('https://www.zabbix.com/documentation/current/en/manual/guides/monitor_certificate');

$group_select = (new CSelect('groupid'))
	->setFocusableElementId('label-groupid')
	->setValue($data['groupid'] !== '' ? $data['groupid'] : null)
	->setAriaRequired();

if (!$data['host_groups']) {
	$group_select->addOption(new CSelectOption('', _('No writable host groups available')));
}

foreach ($data['host_groups'] as $group) {
	$group_select->addOption(new CSelectOption($group['groupid'], $group['name']));
}

$form_grid = new CFormGrid();

if ($is_edit) {
	$form_grid->addItem([
		new CLabel(_('Host (technical name)')),
		new CFormField([
			(new CSpan($data['host_name']))->addClass('certmonitor-mono'),
			(new CDiv(
				_('The technical host name is fixed: it is part of every trigger expression of this host. The monitored target is defined by the {$CERT.WEBSITE.*} macros below and can be changed freely.')
			))->addClass('certmonitor-hint')
		])
	]);
}
elseif ($data['is_clone']) {
	$form_grid->addItem([
		new CLabel(_('Cloning from')),
		new CFormField([
			(new CSpan($data['host_name']))->addClass('certmonitor-mono'),
			(new CDiv(
				_('All settings were copied from that entry. Enter the hostname of the new website; a new host will be created.')
			))->addClass('certmonitor-hint')
		])
	]);
}

$form_grid
	->addItem([
		(new CLabel(_('Hostname/FQDN'), 'hostname'))->setAsteriskMark(),
		new CFormField(
			(new CTextBox('hostname', $data['hostname'], false, 255))
				->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
				->setAriaRequired()
				->setAttribute('autofocus', 'autofocus')
				->setAttribute('placeholder', 'www.example.com')
		)
	])
	->addItem([
		(new CLabel(_('Port'), 'port'))->setAsteriskMark(),
		new CFormField(
			(new CNumericBox('port', $data['port'], 5, false, false, false))
				->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
				->setAriaRequired()
		)
	])
	->addItem([
		new CLabel(_('IP/address override'), 'address'),
		new CFormField([
			(new CTextBox('address', $data['address'], false, 255))
				->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
				->setAttribute('placeholder', _('optional')),
			(new CDiv(
				_('If set, this address is used to connect, while the hostname above is used for SNI and hostname verification.')
			))->addClass('certmonitor-hint')
		])
	])
	// -------------------------------------------------------------- test connection --
	->addItem([
		new CLabel(_('Test connection')),
		new CFormField([
			(new CSimpleButton(_('Test connection')))
				->setId('certmonitor-test-connection')
				->addClass(ZBX_STYLE_BTN_ALT)
				// Must NOT be called "data-url": the Zabbix frontend has a global click handler that
				// turns any element carrying "data-url" into a redirect button, which navigates the
				// browser away before the AJAX request can finish.
				->setAttribute('data-check-url', (new CUrl('zabbix.php'))
					->setArgument('action', 'certmonitor.check')
					->getUrl()
				)
				// The controller reads its input from the JSON request body (POST_CONTENT_TYPE_JSON),
				// so the CSRF token must travel in that body as well - a token in the query string is
				// never seen by the controller and the request is rejected with "Access denied".
				->setAttribute('data-csrf-name', CSRF_TOKEN_NAME)
				->setAttribute('data-csrf-token', CCsrfTokenHelper::get('certmonitor.check'))
				->setAttribute('data-pending-message', _('Connecting...'))
				->setAttribute('data-failed-message', _('Unexpected server error.'))
				->setAttribute('data-empty-message', _('Enter a hostname first.'))
				->setAttribute('aria-controls', 'certmonitor-check-output'),
			(new CDiv())
				->setId('certmonitor-check-output')
				->addClass('certmonitor-check-result')
				->setAttribute('aria-live', 'polite')
				->setAttribute('data-caveat',
					_('This is only a hint. The check is performed by the Zabbix frontend server, while the configured monitoring is performed by a Zabbix agent that may sit in a different network segment, resolve different DNS names and trust a different set of certificate authorities. A failure here does not prevent adding the website.')
				),
			(new CDiv(
				_('Opens a TLS connection from the FRONTEND server and shows what the peer presents. The result never blocks saving - press the button below to add the website anyway.')
			))->addClass('certmonitor-hint')
		])
	])
	->addItem([
		(new CLabel(_('Host group'), 'label-groupid'))->setAsteriskMark(),
		new CFormField($group_select)
	])
	->addItem([
		(new CLabel(_('Zabbix agent address'), 'agent_address'))->setAsteriskMark(),
		new CFormField([
			(new CTextBox('agent_address', $data['agent_address'], false, 255))
				->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
				->setAriaRequired(),
			(new CDiv(
				_('Address of the machine running Zabbix agent 2 that performs the check - not the website itself.')
			))->addClass('certmonitor-hint')
		])
	])
	->addItem([
		(new CLabel(_('Zabbix agent port'), 'agent_port'))->setAsteriskMark(),
		new CFormField(
			(new CNumericBox('agent_port', $data['agent_port'], 5, false, false, false))
				->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
				->setAriaRequired()
		)
	])
	->addItem([
		new CLabel(_('Visible host name'), 'visible_name'),
		new CFormField(
			(new CTextBox('visible_name', $data['visible_name'], false, 128))
				->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
				->setAttribute('placeholder', _('optional'))
		)
	])
	->addItem([
		new CLabel(_('Enabled'), 'host_status'),
		new CFormField([
			// The checked value is "enabled"; an unchecked box still submits "disabled", so the state is
			// never ambiguous.
			(new CCheckBox('host_status', (string) HOST_STATUS_MONITORED))
				->setUncheckedValue((string) HOST_STATUS_NOT_MONITORED)
				->setChecked($data['host_status'] == HOST_STATUS_MONITORED),
			(new CDiv(
				_('When cleared, the host is created but not monitored: no item is polled and no problem is raised.')
			))->addClass('certmonitor-hint')
		])
	])
	->addItem([
		new CLabel(_('Ignore certificate validation errors'), 'ignore_validation'),
		new CFormField([
			(new CCheckBox('ignore_validation'))->setChecked($data['ignore_validation']),
			(new CDiv(
				_('Accept untrusted, self-signed and otherwise invalid certificates. Expiry monitoring stays active; only the validation trigger is disabled.')
			))->addClass('certmonitor-hint'),
			(new CDiv(
				_('The agent item web.certificate.get returns the certificate data even when the certificate is invalid - it only becomes unsupported if the host is unreachable or the TLS handshake fails for another reason. The validation result is reported in $.result.value as "valid", "valid-but-self-signed" or "invalid", so a self-signed certificate does not match the default trigger expression find(...,"like","invalid") anyway.')
			))->addClass('certmonitor-hint')
		])
	])
	->addItem([
		new CLabel(_('Tags'), 'tags'),
		new CFormField([
			(new CTextArea('tags', $data['tags']))
				->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
				->setRows(4)
				->setAttribute('placeholder', "environment=production\ncustomer=acme"),
			(new CDiv(
				_('One host tag per line, written as "name" or "name=value". These are added to the host in addition to the tags this module manages itself ("certmonitor" and "website").')
			))->addClass('certmonitor-hint')
		])
	])
	->addItem([
		new CLabel(_('Description'), 'description'),
		new CFormField(
			(new CTextArea('description', $data['description']))
				->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
				->setAttribute('maxlength', DB::getFieldLength('hosts', 'description'))
		)
	])
	->addItem(
		(new CFormFieldset(_('Security triggers')))
			->addItem(
				(new CFormGrid())
					->addItem([
						new CLabel(_('Issuer changed'), 'sec_issuer_changed'),
						new CFormField([
							(new CCheckBox('sec_issuer_changed'))->setChecked($data['sec_issuer_changed']),
							(new CDiv(
								_('Raises a problem when the certificate is suddenly issued by a different authority than before. Expected after a deliberate change of certificate authority, suspicious otherwise.')
							))->addClass('certmonitor-hint')
						])
					])
					->addItem([
						new CLabel(_('Certificate replaced (SHA-256 fingerprint changed)'),
							'sec_fingerprint_changed'
						),
						new CFormField([
							(new CCheckBox('sec_fingerprint_changed'))
								->setChecked($data['sec_fingerprint_changed']),
							(new CDiv(
								_('Raises a problem whenever the certificate presented by this website is not the same one as before.')
							))->addClass('certmonitor-hint'),
							(new CDiv(
								_('NOTE: every legitimate renewal replaces the certificate and therefore fires this trigger as well. Treat it as an audit signal, not as an incident. Its severity defaults to WARNING and can be changed org-wide in the module settings.')
							))->addClass('certmonitor-hint')
						])
					])
					->addItem([
						new CLabel(_('Weak public key algorithm'), 'sec_weak_key'),
						new CFormField([
							(new CCheckBox('sec_weak_key'))->setChecked($data['sec_weak_key']),
							(new CDiv(
								_('Raises a problem when the public key algorithm matches the pattern in {$CERT.KEY.ALGO.WEAK}, by default "DSA|Unknown".')
							))->addClass('certmonitor-hint'),
							(new CDiv(
								_('IMPORTANT: the agent item web.certificate.get reports the key ALGORITHM only ("RSA", "DSA", "ECDSA", "Ed25519", "Unknown") and never the key LENGTH, so a check such as "RSA below 2048 bit" is not possible from this data. Use a separate script item if you need the key size.')
							))->addClass('certmonitor-hint')
						])
					])
					->addItem([
						new CLabel(_('Weak signature algorithm'), 'sec_weak_signature'),
						new CFormField([
							(new CCheckBox('sec_weak_signature'))->setChecked($data['sec_weak_signature']),
							(new CDiv(
								_('Raises a problem when the signature algorithm matches the pattern in {$CERT.SIG.ALGO.WEAK}, by default "SHA1|MD5|MD2", against values such as "SHA1-RSA".')
							))->addClass('certmonitor-hint')
						])
					])
					->addItem([
						new CLabel(''),
						new CFormField(
							(new CDiv(
								_('All four triggers are always created on the host. Clearing a checkbox disables the trigger instead of deleting it, so it stays visible and can be switched back on at any time. The state is stored in the {$CERT.SEC.*} macros of the host.')
							))->addClass('certmonitor-hint')
						)
					])
			)
	)
	->addItem(
		(new CFormFieldset(_('Warning thresholds')))
			->addItem(
				(new CFormGrid())
					->addItem([
						(new CLabel(_('Warning (days)'), 'warn_days'))->setAsteriskMark(),
						new CFormField(
							(new CNumericBox('warn_days', $data['warn_days'], 4, false, false, false))
								->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
								->setAriaRequired()
						)
					])
					->addItem([
						(new CLabel(_('Average (days)'), 'avg_days'))->setAsteriskMark(),
						new CFormField(
							(new CNumericBox('avg_days', $data['avg_days'], 4, false, false, false))
								->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
								->setAriaRequired()
						)
					])
					->addItem([
						(new CLabel(_('High (days)'), 'crit_days'))->setAsteriskMark(),
						new CFormField([
							(new CNumericBox('crit_days', $data['crit_days'], 4, false, false, false))
								->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
								->setAriaRequired(),
							(new CDiv(
								_('Stored as the user macros {$CERT.EXPIRY.WARN}, {$CERT.EXPIRY.AVG} and {$CERT.EXPIRY.CRIT} on the host, so they can be changed later. Warning > average > high is required.')
							))->addClass('certmonitor-hint')
						])
					])
			)
	);

$form = (new CForm())
	->setName('certmonitor_edit')
	->setAction((new CUrl('zabbix.php'))->setArgument('action', $submit_action)->getUrl())
	->addItem((new CVar(CSRF_TOKEN_NAME, CCsrfTokenHelper::get($submit_action)))->removeId());

if ($is_edit) {
	$form->addItem((new CVar('hostid', $data['hostid']))->removeId());
}

$form
	->addItem($form_grid)
	->addItem(
		new CFormActions(
			(new CSubmitButton($submit_label, 'action', $submit_action))
				->setEnabled((bool) $data['host_groups']),
			[new CRedirectButton(_('Cancel'),
				(new CUrl('zabbix.php'))->setArgument('action', 'certmonitor.list')
			)]
		)
	);

$html_page
	->addItem($form)
	->show();
