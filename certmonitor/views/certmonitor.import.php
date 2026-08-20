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
 * Certificate Monitor - the bulk import page.
 *
 * Two independent forms are shown:
 *
 *   - the input form, which posts the pasted list or the uploaded CSV file back to "certmonitor.import"
 *     and gets a preview in return;
 *   - the preview form, which posts the very same text plus the selected line numbers to
 *     "certmonitor.import.create", which is the only action of the two that writes anything.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\CertMonitor\Includes\CertImportParser;

$html_page = (new CHtmlPage())
	->setTitle(_('Import websites'))
	->setDocUrl('https://www.zabbix.com/documentation/current/en/manual/guides/monitor_certificate')
	->setControls(
		(new CTag('nav', true,
			(new CList())->addItem(new CRedirectButton(_('Back to list'),
				(new CUrl('zabbix.php'))->setArgument('action', 'certmonitor.list')
			))
		))->setAttribute('aria-label', _('Content controls'))
	);

// ------------------------------------------------------------------------------ format description --

$format_hints = [
	_('One website per line, in the form: host[:port][,hostgroup][,description]'),
	_s('The port defaults to %1$s.', $data['default_port']),
	$data['default_group_name'] !== ''
		? _s('The host group defaults to "%1$s".', $data['default_group_name'])
		: _('No default host group is configured, so every line must name an existing host group.'),
	_('A host group is never created by the import: an unknown group name makes the line invalid.'),
	_('Every line is read as a CSV record, so a field containing a comma can be quoted with double quotes. Everything after the second comma belongs to the description.'),
	_('Empty lines and lines starting with "#" are ignored, as is a leading header line whose first field is "host" or "hostname".'),
	_s('At most %1$s entries can be imported at once.', (string) $data['max_lines']),
	_s('All other values - the Zabbix agent address and port, the update interval, the warning thresholds and the security triggers - are taken from the module settings. The technical host name is built as "%1$s<host>_<port>".',
		$data['host_prefix']
	)
];

$hint_list = new CList();

foreach ($format_hints as $hint) {
	$hint_list->addItem($hint);
}

$example = "www.example.com\n"
	."api.example.com:8443,Web servers\n"
	."intranet.example.org,Internal,\"Reverse proxy, HQ site\"\n"
	."# a comment line is ignored";

$intro = (new CDiv([
	(new CDiv($hint_list))->addClass('certmonitor-hint'),
	(new CDiv(_('Example:')))->addClass('certmonitor-hint'),
	(new CDiv($example))->addClass('certmonitor-mono')
]))->addClass('certmonitor-intro');

// ------------------------------------------------------------------------------------- input form --

$input_form_grid = (new CFormGrid())
	->addItem([
		new CLabel(_('List'), 'import_text'),
		new CFormField(
			(new CTextArea('import_text', $data['import_text']))
				->setWidth(ZBX_TEXTAREA_BIG_WIDTH)
				->setRows(12)
				->setAttribute('autofocus', 'autofocus')
				->setAttribute('placeholder', "www.example.com:443,Web servers,Public site")
		)
	])
	->addItem([
		new CLabel(_('CSV file'), 'import_file'),
		new CFormField([
			new CFile('import_file'),
			(new CDiv(
				_('Optional. A UTF-8 text or CSV file in exactly the format described above. An uploaded file replaces the contents of the list field and is previewed immediately.')
			))->addClass('certmonitor-hint')
		])
	]);

$input_form = (new CForm('post'))
	->setName('certmonitor_import')
	->setAction((new CUrl('zabbix.php'))->setArgument('action', 'certmonitor.import')->getUrl())
	// Required for the file upload; without it the browser submits the file name only.
	->setAttribute('enctype', 'multipart/form-data')
	->addItem($input_form_grid)
	->addItem(
		new CFormActions(
			(new CSubmitButton(_('Preview'), 'preview', 1))
		)
	);

$html_page
	->addItem($intro)
	->addItem($input_form);

// ----------------------------------------------------------------------------------- preview table --

if ($data['has_preview']) {
	$preview_form = (new CForm('post'))
		->setName('certmonitor_import_preview')
		->setAction((new CUrl('zabbix.php'))->setArgument('action', 'certmonitor.import.create')->getUrl())
		->addItem((new CVar(CSRF_TOKEN_NAME, CCsrfTokenHelper::get('certmonitor.import.create')))->removeId())
		->addItem((new CVar('import_text', $data['import_text']))->removeId());

	// A small inline handler is used instead of the global checkAll(): that helper drives the
	// chkbxRange object of a standard Zabbix list, which this ad-hoc preview table is not part of.
	$select_all = (new CCheckBox('all_records'))
		->setChecked(true)
		->onClick(
			"var form = document.forms['".$preview_form->getName()."'];"
			."for (var i = 0; i < form.elements.length; i++) {"
			."var element = form.elements[i];"
			."if (element.type === 'checkbox' && element.name.indexOf('selected[') === 0) {"
			."element.checked = this.checked;"
			."}}"
		)
		->setAttribute('aria-label', _('Select all importable lines'));

	$table = (new CTableInfo())
		->setId('certmonitor_import_rows')
		->setHeader([
			$data['summary'][CertImportParser::STATUS_OK] > 0 ? $select_all : '',
			_('Line'),
			_('Website'),
			_('Port'),
			_('Host group'),
			_('Description'),
			_('Host (technical name)'),
			_('Status')
		]);

	foreach ($data['rows'] as $row) {
		switch ($row['status']) {
			case CertImportParser::STATUS_OK:
				$status_cell = (new CSpan(_('OK')))->addClass(ZBX_STYLE_GREEN);
				$checkbox = (new CCheckBox('selected['.$row['line'].']', (string) $row['line']))
					->setChecked(true);
				break;

			case CertImportParser::STATUS_DUPLICATE:
				$status_cell = [
					(new CSpan(_('Already monitored')))->addClass(ZBX_STYLE_ORANGE),
					(new CDiv($row['message']))->addClass('certmonitor-hint')
				];
				$checkbox = '';
				break;

			default:
				$status_cell = [
					(new CSpan(_('Invalid')))->addClass(ZBX_STYLE_RED),
					(new CDiv($row['message']))->addClass('certmonitor-hint')
				];
				$checkbox = '';
		}

		$is_parsed = $row['hostname'] !== '';

		$table->addRow([
			$checkbox,
			$row['line'],
			$is_parsed ? $row['hostname'] : (new CSpan($row['raw']))->addClass('certmonitor-mono'),
			$is_parsed ? (string) $row['port'] : '',
			$row['group_name'],
			$row['description'],
			$row['host_name'] !== '' ? (new CSpan($row['host_name']))->addClass('certmonitor-mono') : '',
			$status_cell
		]);
	}

	$counts = (new CDiv(
		_s('%1$s line(s) ready to import, %2$s already monitored, %3$s invalid.',
			(string) $data['summary'][CertImportParser::STATUS_OK],
			(string) $data['summary'][CertImportParser::STATUS_DUPLICATE],
			(string) $data['summary'][CertImportParser::STATUS_INVALID]
		)
	))->addClass('certmonitor-hint');

	$preview_form
		->addItem($counts)
		->addItem($table)
		->addItem(
			new CFormActions(
				(new CSubmitButton(_('Import selected'), 'action', 'certmonitor.import.create'))
					->setEnabled($data['summary'][CertImportParser::STATUS_OK] > 0),
				[new CRedirectButton(_('Cancel'),
					(new CUrl('zabbix.php'))->setArgument('action', 'certmonitor.list')
				)]
			)
		);

	$html_page->addItem($preview_form);
}

$html_page->show();
