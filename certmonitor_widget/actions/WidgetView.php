<?php declare(strict_types = 1);
/**
 * Certificate Monitor - data source of the dashboard widget.
 *
 * Reads exactly the hosts that the Certificate Monitor module creates - those carrying the host tag
 * "certmonitor: website" - together with the last value of their "cert.not_after" item, and returns the
 * ones that expire soonest.
 *
 * The tag and the item key are repeated as constants here instead of being imported from the frontend
 * module: the two are separate Zabbix modules with separate namespaces, and the widget has to keep
 * working when only the widget is installed. They must stay in sync with
 * Modules\CertMonitor\Includes\CertHelper.
 *
 * @see https://www.zabbix.com/documentation/7.4/en/devel/modules/widgets
 *
 * Author: Domekologe <support@domekologe.eu>
 */

namespace Modules\CertMonitorWidget\Actions;

use API;
use APP;
use CControllerDashboardWidgetView;
use CControllerResponseData;
use Manager;

class WidgetView extends CControllerDashboardWidgetView {

	/**
	 * Host tag that marks a host as being managed by the Certificate Monitor module.
	 */
	private const HOST_TAG = 'certmonitor';
	private const HOST_TAG_VALUE = 'website';

	/**
	 * Item keys read from those hosts.
	 */
	private const KEY_NOT_AFTER = 'cert.not_after';
	private const KEY_VALIDATION = 'cert.validation';

	/**
	 * Per-host expiry thresholds, in days.
	 */
	private const MACRO_EXPIRY_WARN = '{$CERT.EXPIRY.WARN}';
	private const MACRO_EXPIRY_AVG = '{$CERT.EXPIRY.AVG}';
	private const MACRO_EXPIRY_CRIT = '{$CERT.EXPIRY.CRIT}';

	private const DEFAULT_WARN_DAYS = 30;
	private const DEFAULT_AVG_DAYS = 14;
	private const DEFAULT_CRIT_DAYS = 7;

	/**
	 * ID of the frontend module that provides the certificate detail page.
	 */
	private const FRONTEND_MODULE_ID = 'dks_certmonitor';

	protected function doAction(): void {
		$data = [
			'name' => $this->getInput('name', $this->widget->getDefaultName()),
			'certificates' => [],
			'has_detail_page' => APP::ModuleManager()->getModule(self::FRONTEND_MODULE_ID) !== null,
			'error' => null,
			'user' => ['debug_mode' => $this->getDebugMode()]
		];

		try {
			$data['certificates'] = $this->getCertificates();
		}
		catch (\Exception $e) {
			$data['error'] = $e->getMessage();
		}

		$this->setResponse(new CControllerResponseData($data));
	}

	/**
	 * The certificates that expire soonest, already sorted and limited.
	 *
	 * @return array  A list of rows with the keys hostid, name, days_left, not_after, validation.
	 */
	private function getCertificates(): array {
		$options = [
			'output' => ['hostid', 'host', 'name', 'status'],
			'selectMacros' => ['macro', 'value'],
			'tags' => [[
				'tag' => self::HOST_TAG,
				'value' => self::HOST_TAG_VALUE,
				'operator' => TAG_OPERATOR_EQUAL
			]],
			'preservekeys' => true
		];

		// getSubGroups() expands a selected group to the group and all of its nested groups, which is what
		// the standard Zabbix widgets do as well.
		if (!$this->isTemplateDashboard() && $this->fields_values['groupids']) {
			$options['groupids'] = getSubGroups($this->fields_values['groupids']);
		}

		$hosts = API::Host()->get($options);

		if (!$hosts) {
			return [];
		}

		$items = API::Item()->get([
			'output' => ['itemid', 'hostid', 'key_', 'value_type'],
			'hostids' => array_keys($hosts),
			'filter' => ['key_' => [self::KEY_NOT_AFTER, self::KEY_VALIDATION]],
			'preservekeys' => true
		]);

		if (!$items) {
			return [];
		}

		// Look back two years, so that long-lived certificates with a slow update interval are still shown.
		$last_values = Manager::History()->getLastValues($items, 1, 2 * SEC_PER_YEAR);

		$now = time();
		$certificates = [];

		foreach ($items as $itemid => $item) {
			if ((string) $item['key_'] !== self::KEY_NOT_AFTER) {
				continue;
			}

			if (!array_key_exists($itemid, $last_values) || !$last_values[$itemid]) {
				continue;
			}

			$hostid = (string) $item['hostid'];

			if (!array_key_exists($hostid, $hosts)) {
				continue;
			}

			$host = $hosts[$hostid];
			$not_after = (int) $last_values[$itemid][0]['value'];

			$certificates[] = [
				'hostid' => $hostid,
				'name' => (string) $host['name'],
				'status' => (int) $host['status'],
				'not_after' => $not_after,
				'days_left' => (int) floor(($not_after - $now) / SEC_PER_DAY),
				'validation' => $this->getValidation($items, $last_values, $hostid),
				'warn_days' => $this->getThreshold($host['macros'], self::MACRO_EXPIRY_WARN,
					self::DEFAULT_WARN_DAYS
				),
				'avg_days' => $this->getThreshold($host['macros'], self::MACRO_EXPIRY_AVG,
					self::DEFAULT_AVG_DAYS
				),
				'crit_days' => $this->getThreshold($host['macros'], self::MACRO_EXPIRY_CRIT,
					self::DEFAULT_CRIT_DAYS
				)
			];
		}

		usort($certificates, static fn(array $a, array $b): int => $a['not_after'] <=> $b['not_after']);

		return array_slice($certificates, 0, (int) $this->fields_values['show_lines']);
	}

	/**
	 * Last validation result of one host, or an empty string when it has none.
	 *
	 * @return string
	 */
	private function getValidation(array $items, array $last_values, string $hostid): string {
		foreach ($items as $itemid => $item) {
			if ((string) $item['hostid'] === $hostid && (string) $item['key_'] === self::KEY_VALIDATION
					&& array_key_exists($itemid, $last_values) && $last_values[$itemid]) {
				return (string) $last_values[$itemid][0]['value'];
			}
		}

		return '';
	}

	/**
	 * Read one {$CERT.EXPIRY.*} threshold off a host, falling back to the shipped default whenever the
	 * macro is missing or was hand-edited into something unusable.
	 *
	 * @return int
	 */
	private function getThreshold(array $macros, string $macro, int $default): int {
		foreach ($macros as $host_macro) {
			if ((string) ($host_macro['macro'] ?? '') !== $macro) {
				continue;
			}

			$value = trim((string) $host_macro['value']);

			return ctype_digit($value) && (int) $value > 0 ? (int) $value : $default;
		}

		return $default;
	}
}
