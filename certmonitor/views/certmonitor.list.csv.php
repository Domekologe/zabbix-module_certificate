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
 * Certificate Monitor - CSV export of the list of monitored websites.
 *
 * Rendered through the "layout.csv" layout, which sets the Content-Type and the Content-Disposition
 * header from the file name that the controller passed to CControllerResponseData::setFileName().
 *
 * The export contains exactly the rows that the filter selects, in the current sort order, and is not
 * paginated - an export of a single page is rarely useful.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\CertMonitor\Includes\CertHelper;

$csv = [];

$csv[] = [
	_('Website'),
	_('Port'),
	_('Address override'),
	_('Host (technical name)'),
	_('Host (visible name)'),
	_('Host groups'),
	_('Tags'),
	_('Monitoring'),
	_('Valid from'),
	_('Expires on'),
	_('Days left'),
	_('Validation'),
	_('Validation message'),
	_('Validation ignored'),
	_('Subject'),
	_('Issuer'),
	_('Fingerprint (SHA-256)'),
	_('Last checked'),
	_('Warning (days)'),
	_('Average (days)'),
	_('High (days)'),
	_('Threshold warning'),
	_('Description')
];

foreach ($data['websites'] as $website) {
	$group_names = [];

	foreach ($website['groups'] as $group) {
		$group_names[] = $group['name'];
	}

	$tags = [];

	foreach ($website['tags'] as $tag) {
		if (CertHelper::isReservedTag((string) $tag['tag'])) {
			continue;
		}

		$tags[] = $tag['value'] !== '' ? $tag['tag'].'='.$tag['value'] : (string) $tag['tag'];
	}

	$csv[] = [
		$website['website'],
		$website['port'],
		$website['address'],
		$website['host'],
		$website['name'],
		implode(', ', $group_names),
		implode(', ', $tags),
		$website['status'] === HOST_STATUS_MONITORED ? _('Enabled') : _('Disabled'),
		$website['not_before'] !== null ? zbx_date2str(DATE_TIME_FORMAT, $website['not_before']) : '',
		$website['not_after'] !== null ? zbx_date2str(DATE_TIME_FORMAT, $website['not_after']) : '',
		$website['days_left'] !== null ? (string) $website['days_left'] : '',
		(string) $website['validation'],
		(string) $website['message'],
		$website['ignore_validation'] ? _('yes') : _('no'),
		(string) $website['subject'],
		(string) $website['issuer'],
		(string) $website['fingerprint'],
		$website['last_check'] !== null ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $website['last_check']) : '',
		$website['warn_days'],
		$website['avg_days'],
		$website['crit_days'],
		$website['threshold_warning'],
		(string) $website['description']
	];
}

echo zbx_toCSV($csv);
