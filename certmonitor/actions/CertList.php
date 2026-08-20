<?php declare(strict_types = 1);
/**
 * Certificate Monitor - list of monitored websites.
 *
 * Serves both "certmonitor.list" (the HTML page) and "certmonitor.list.csv" (the CSV export of exactly
 * the same, filtered and sorted, data set). The CSV export is not paginated on purpose - an export of
 * one page of a list is rarely what anybody wants.
 *
 * Author: Domekologe <support@domekologe.eu>
 */

namespace Modules\CertMonitor\Actions;

use API;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CPagerHelper;
use CProfile;
use CRoleHelper;
use CSettingsHelper;
use CUrl;
use Manager;
use Modules\CertMonitor\Includes\CertHelper;

class CertList extends CController {

	/**
	 * Profile keys used to remember the filter and the sorting between requests.
	 */
	private const PROFILE_PREFIX = 'web.certmonitor.list';

	/**
	 * Filter value that means "do not filter by this property at all".
	 */
	private const FILTER_ANY = '';

	protected function init(): void {
		// Read-only GET pages, so no CSRF token is expected.
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'sort' =>					'in name,website,not_after,validation,last_check,status',
			'sortorder' =>				'in '.ZBX_SORT_DOWN.','.ZBX_SORT_UP,
			'page' =>					'ge 1',
			'uncheck' =>				'in 1',
			'filter_set' =>				'in 1',
			'filter_rst' =>				'in 1',
			'filter_website' =>			'string',
			'filter_groupid' =>			'string',
			'filter_validation' =>		'string',
			'filter_expiring_days' =>	'string',
			'filter_status' =>			'string'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_MONITORING_HOSTS);
	}

	/**
	 * Error boundary around the data collection.
	 *
	 * A fatal error here would produce a bare HTTP 500 with nothing to go on. PHP 8 allows catching
	 * Error as well as Exception, so the page can instead render a message that names the cause.
	 * The view checks for the "fatal" key first.
	 */
	protected function doAction(): void {
		try {
			$this->buildResponse();
		}
		catch (\Throwable $e) {
			$response = new CControllerResponseData([
				'fatal' => get_class($e).': '.$e->getMessage(),
				'fatal_origin' => $e->getFile().':'.$e->getLine()
			]);
			$response->setTitle(_('Certificates'));

			$this->setResponse($response);
		}
	}

	/**
	 * Collect everything the list view needs.
	 */
	private function buildResponse(): void {
		$is_csv = $this->getAction() === 'certmonitor.list.csv';

		$sort_field = $this->getInput('sort', CProfile::get(self::PROFILE_PREFIX.'.sort', 'name'));
		$sort_order = $this->getInput('sortorder', CProfile::get(self::PROFILE_PREFIX.'.sortorder', ZBX_SORT_UP));

		CProfile::update(self::PROFILE_PREFIX.'.sort', $sort_field, PROFILE_TYPE_STR);
		CProfile::update(self::PROFILE_PREFIX.'.sortorder', $sort_order, PROFILE_TYPE_STR);

		$filter = $this->readFilter();

		$websites = $this->getWebsites($filter);
		$summary = $this->summarize($websites);

		$this->sortWebsites($websites, $sort_field, $sort_order);

		$data = [
			'action' => $this->getAction(),
			'websites' => $websites,
			'summary' => $summary,
			'filter' => $filter,
			'host_groups' => $this->getHostGroups(),
			'sort' => $sort_field,
			'sortorder' => $sort_order,
			'profileIdx' => self::PROFILE_PREFIX.'.filter',
			'active_tab' => CProfile::get(self::PROFILE_PREFIX.'.filter.active', 1),
			'can_edit' => $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN
				&& $this->checkAccess(CRoleHelper::UI_CONFIGURATION_HOSTS),
			'can_settings' => $this->getUserType() == USER_TYPE_SUPER_ADMIN,
			'can_execute' => $this->getUserType() >= USER_TYPE_ZABBIX_USER,
			'uncheck' => $this->hasInput('uncheck')
		];

		if (!$is_csv) {
			// CPagerHelper::paginate() declares int; getInput() hands back the raw request string, and
			// declare(strict_types = 1) in this file turns that into a TypeError. Hence the explicit cast.
			$data['paging'] = CPagerHelper::paginate((int) $this->getInput('page', 1), $data['websites'], $sort_order,
				(new CUrl('zabbix.php'))->setArgument('action', 'certmonitor.list')
			);
		}

		$response = new CControllerResponseData($data);

		if ($is_csv) {
			$response->setFileName('zbx_certificates.csv');
		}
		else {
			$response->setTitle(_('Certificates'));
		}

		$this->setResponse($response);
	}

	/**
	 * Read the filter from the request, persist it in the user profile, and return the effective values.
	 *
	 * @return array
	 */
	private function readFilter(): array {
		if ($this->hasInput('filter_set')) {
			CProfile::update(self::PROFILE_PREFIX.'.filter_website',
				trim((string) $this->getInput('filter_website', '')), PROFILE_TYPE_STR
			);
			CProfile::update(self::PROFILE_PREFIX.'.filter_groupid',
				trim((string) $this->getInput('filter_groupid', '')), PROFILE_TYPE_STR
			);
			CProfile::update(self::PROFILE_PREFIX.'.filter_validation',
				trim((string) $this->getInput('filter_validation', '')), PROFILE_TYPE_STR
			);
			CProfile::update(self::PROFILE_PREFIX.'.filter_expiring_days',
				trim((string) $this->getInput('filter_expiring_days', '')), PROFILE_TYPE_STR
			);
			CProfile::update(self::PROFILE_PREFIX.'.filter_status',
				trim((string) $this->getInput('filter_status', '')), PROFILE_TYPE_STR
			);

			CPagerHelper::resetPage();
		}
		elseif ($this->hasInput('filter_rst')) {
			CProfile::delete(self::PROFILE_PREFIX.'.filter_website');
			CProfile::delete(self::PROFILE_PREFIX.'.filter_groupid');
			CProfile::delete(self::PROFILE_PREFIX.'.filter_validation');
			CProfile::delete(self::PROFILE_PREFIX.'.filter_expiring_days');
			CProfile::delete(self::PROFILE_PREFIX.'.filter_status');

			CPagerHelper::resetPage();
		}

		$expiring_days = (string) CProfile::get(self::PROFILE_PREFIX.'.filter_expiring_days', '');

		// A non-numeric value would silently filter everything away, so it is treated as "not set".
		if ($expiring_days !== '' && !ctype_digit($expiring_days)) {
			$expiring_days = '';
		}

		return [
			'website' => (string) CProfile::get(self::PROFILE_PREFIX.'.filter_website', ''),
			'groupid' => (string) CProfile::get(self::PROFILE_PREFIX.'.filter_groupid', self::FILTER_ANY),
			'validation' => (string) CProfile::get(self::PROFILE_PREFIX.'.filter_validation', self::FILTER_ANY),
			'expiring_days' => $expiring_days,
			'status' => (string) CProfile::get(self::PROFILE_PREFIX.'.filter_status', self::FILTER_ANY)
		];
	}

	/**
	 * All host groups that contain at least one host managed by this module.
	 *
	 * @return array  Array of ['groupid' => ..., 'name' => ...].
	 */
	private function getHostGroups(): array {
		$groups = API::HostGroup()->get([
			'output' => ['groupid', 'name'],
			'with_hosts' => true,
			'sortfield' => 'name'
		]);

		return $groups ?: [];
	}

	/**
	 * Collect all hosts managed by this module together with the latest values of their certificate items.
	 *
	 * Host group and host status are filtered by the API, everything that depends on collected values is
	 * filtered here, because those values do not live in the hosts table.
	 *
	 * @param array $filter
	 *
	 * @return array
	 */
	private function getWebsites(array $filter): array {
		$options = [
			'output' => ['hostid', 'host', 'name', 'status', 'description'],
			'selectHostGroups' => ['groupid', 'name'],
			'selectMacros' => ['macro', 'value'],
			'selectTags' => ['tag', 'value'],
			'tags' => [[
				'tag' => CertHelper::HOST_TAG,
				'value' => CertHelper::HOST_TAG_VALUE,
				'operator' => TAG_OPERATOR_EQUAL
			]],
			'sortfield' => 'name',
			'limit' => CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT) + 1,
			'preservekeys' => true
		];

		if ($filter['groupid'] !== self::FILTER_ANY && ctype_digit($filter['groupid'])) {
			$options['groupids'] = [$filter['groupid']];
		}

		if ($filter['status'] === (string) HOST_STATUS_MONITORED
				|| $filter['status'] === (string) HOST_STATUS_NOT_MONITORED) {
			$options['filter']['status'] = (int) $filter['status'];
		}

		$hosts = API::Host()->get($options);

		if (!$hosts) {
			return [];
		}

		$wanted_keys = [
			CertHelper::KEY_NOT_AFTER,
			CertHelper::KEY_NOT_AFTER_STR,
			CertHelper::KEY_NOT_BEFORE,
			CertHelper::KEY_VALIDATION,
			CertHelper::KEY_MESSAGE,
			CertHelper::KEY_ISSUER,
			CertHelper::KEY_SUBJECT,
			CertHelper::KEY_FINGERPRINT
		];

		$items = API::Item()->get([
			'output' => ['itemid', 'hostid', 'key_', 'value_type', 'status', 'error', 'type', 'master_itemid'],
			'hostids' => array_keys($hosts),
			'preservekeys' => true
		]);

		// Look back two years, so that long-lived certificates with a slow update interval are still shown.
		$last_values = $items ? Manager::History()->getLastValues($items, 1, 2 * SEC_PER_YEAR) : [];

		$now = time();
		$websites = [];

		foreach ($hosts as $hostid => $host) {
			$values = [];
			$clocks = [];
			$itemids = [];
			$master_itemid = null;
			$master_error = '';

			foreach ($items as $itemid => $item) {
				if ((string) $item['hostid'] !== (string) $hostid) {
					continue;
				}

				if ((int) $item['type'] !== ITEM_TYPE_DEPENDENT) {
					$master_itemid = (string) $itemid;
					$master_error = (string) $item['error'];
				}

				if (!in_array($item['key_'], $wanted_keys, true)) {
					continue;
				}

				$itemids[$item['key_']] = (string) $itemid;
				$values[$item['key_']] = array_key_exists($itemid, $last_values)
					? $last_values[$itemid][0]['value']
					: null;
				$clocks[$item['key_']] = array_key_exists($itemid, $last_values)
					? (int) $last_values[$itemid][0]['clock']
					: null;
			}

			$not_after = array_key_exists(CertHelper::KEY_NOT_AFTER, $values)
					&& $values[CertHelper::KEY_NOT_AFTER] !== null
				? (int) $values[CertHelper::KEY_NOT_AFTER]
				: null;

			$warn_days = CertHelper::getMacroValue($host['macros'], CertHelper::MACRO_EXPIRY_WARN);
			$avg_days = CertHelper::getMacroValue($host['macros'], CertHelper::MACRO_EXPIRY_AVG);
			$crit_days = CertHelper::getMacroValue($host['macros'], CertHelper::MACRO_EXPIRY_CRIT);

			// The most recent collection time of any certificate item is the "last checked" moment.
			$last_check = null;

			foreach ($clocks as $clock) {
				if ($clock !== null && ($last_check === null || $clock > $last_check)) {
					$last_check = $clock;
				}
			}

			$websites[] = [
				'hostid' => (string) $hostid,
				'host' => $host['host'],
				'name' => $host['name'],
				'status' => (int) $host['status'],
				'description' => $host['description'],
				'groups' => $host['hostgroups'],
				'tags' => $host['tags'],
				'website' => CertHelper::getMacroValue($host['macros'], CertHelper::MACRO_HOSTNAME),
				'port' => CertHelper::getMacroValue($host['macros'], CertHelper::MACRO_PORT,
					(string) CertHelper::DEFAULT_PORT
				),
				'address' => CertHelper::getMacroValue($host['macros'], CertHelper::MACRO_IP),
				'warn_days' => $warn_days,
				'avg_days' => $avg_days,
				'crit_days' => $crit_days,
				'threshold_warning' => CertHelper::checkThresholdSanity($warn_days, $avg_days, $crit_days),
				'ignore_validation' => CertHelper::isValidationIgnored($host['macros']),
				'not_after' => $not_after,
				'days_left' => $not_after !== null ? CertHelper::daysLeft($not_after, $now) : null,
				'not_after_str' => $values[CertHelper::KEY_NOT_AFTER_STR] ?? null,
				'not_before' => array_key_exists(CertHelper::KEY_NOT_BEFORE, $values)
						&& $values[CertHelper::KEY_NOT_BEFORE] !== null
					? (int) $values[CertHelper::KEY_NOT_BEFORE]
					: null,
				'validation' => $values[CertHelper::KEY_VALIDATION] ?? null,
				'message' => $values[CertHelper::KEY_MESSAGE] ?? null,
				'issuer' => $values[CertHelper::KEY_ISSUER] ?? null,
				'subject' => $values[CertHelper::KEY_SUBJECT] ?? null,
				'fingerprint' => $values[CertHelper::KEY_FINGERPRINT] ?? null,
				'last_check' => $last_check,
				'master_itemid' => $master_itemid,
				'master_error' => $master_error,
				'itemids' => $itemids
			];
		}

		return $this->applyValueFilters($websites, $filter);
	}

	/**
	 * Apply the filter conditions that depend on collected values.
	 *
	 * @param array $websites
	 * @param array $filter
	 *
	 * @return array
	 */
	private function applyValueFilters(array $websites, array $filter): array {
		$needle = mb_strtolower(trim($filter['website']));
		$expiring_days = $filter['expiring_days'] !== '' ? (int) $filter['expiring_days'] : null;

		$result = [];

		foreach ($websites as $website) {
			if ($needle !== '') {
				$haystack = mb_strtolower($website['website'].' '.$website['name'].' '.$website['host']);

				if (mb_strpos($haystack, $needle) === false) {
					continue;
				}
			}

			if ($filter['validation'] !== self::FILTER_ANY) {
				$validation = $website['validation'];

				if ($filter['validation'] === 'nodata') {
					if ($validation !== null && $validation !== '') {
						continue;
					}
				}
				elseif ((string) $validation !== $filter['validation']) {
					continue;
				}
			}

			// "Expiring within N days" also includes certificates that already expired - they are the
			// most urgent members of that set. Hosts without data cannot match.
			if ($expiring_days !== null
					&& ($website['days_left'] === null || $website['days_left'] > $expiring_days)) {
				continue;
			}

			$result[] = $website;
		}

		return $result;
	}

	/**
	 * Counters shown above the list, computed over the filtered set.
	 *
	 * @param array $websites
	 *
	 * @return array
	 */
	private function summarize(array $websites): array {
		$summary = [
			'total' => count($websites),
			'ok' => 0,
			'expiring' => 0,
			'expired' => 0,
			'invalid' => 0,
			'nodata' => 0,
			'disabled' => 0
		];

		foreach ($websites as $website) {
			if ($website['status'] === HOST_STATUS_NOT_MONITORED) {
				$summary['disabled']++;
			}

			if ($website['days_left'] === null && ($website['validation'] === null
					|| $website['validation'] === '')) {
				$summary['nodata']++;

				continue;
			}

			$is_invalid = $website['validation'] === CertHelper::VALIDATION_INVALID;
			$is_expired = $website['days_left'] !== null && $website['days_left'] < 0;

			if ($is_invalid) {
				$summary['invalid']++;
			}

			if ($is_expired) {
				$summary['expired']++;
			}

			if (!$is_invalid && !$is_expired) {
				$warn_days = $website['warn_days'] !== '' && ctype_digit($website['warn_days'])
					? (int) $website['warn_days']
					: CertHelper::DEFAULT_WARN_DAYS;

				if ($website['days_left'] !== null && $website['days_left'] < $warn_days) {
					$summary['expiring']++;
				}
				else {
					$summary['ok']++;
				}
			}
		}

		return $summary;
	}

	/**
	 * Sort the collected rows in PHP.
	 *
	 * The interesting columns ("days left", validation result, last check) come from the history tables
	 * and not from the hosts table, so host.get() cannot sort by them.
	 *
	 * @param array  $websites
	 * @param string $sort_field
	 * @param string $sort_order
	 */
	private function sortWebsites(array &$websites, string $sort_field, string $sort_order): void {
		$direction = $sort_order === ZBX_SORT_DOWN ? -1 : 1;

		usort($websites, static function (array $a, array $b) use ($sort_field, $direction): int {
			switch ($sort_field) {
				case 'website':
					$result = strnatcasecmp($a['website'].':'.$a['port'], $b['website'].':'.$b['port']);
					break;

				case 'not_after':
					// Rows without a value always sort to the end, in both directions, because "unknown"
					// is not "very soon" and not "very late".
					if ($a['not_after'] === null || $b['not_after'] === null) {
						if ($a['not_after'] === $b['not_after']) {
							$result = 0;
							break;
						}

						return $a['not_after'] === null ? 1 : -1;
					}

					$result = $a['not_after'] <=> $b['not_after'];
					break;

				case 'validation':
					$result = strcmp((string) $a['validation'], (string) $b['validation']);
					break;

				case 'last_check':
					if ($a['last_check'] === null || $b['last_check'] === null) {
						if ($a['last_check'] === $b['last_check']) {
							$result = 0;
							break;
						}

						return $a['last_check'] === null ? 1 : -1;
					}

					$result = $a['last_check'] <=> $b['last_check'];
					break;

				case 'status':
					$result = $a['status'] <=> $b['status'];
					break;

				case 'name':
				default:
					$result = strnatcasecmp($a['name'], $b['name']);
					break;
			}

			if ($result === 0) {
				// Stable, predictable secondary ordering.
				$result = strnatcasecmp($a['name'], $b['name']);

				return $result;
			}

			return $result * $direction;
		});
	}
}
