<?php declare(strict_types = 1);
/**
 * Certificate Monitor - detail page of a single monitored website.
 *
 * Shows everything the module configured for one host: the check target, the agent interface that
 * performs the check, all {$CERT.*} macros, every item with its latest value, and every trigger with
 * its severity, status and expression.
 *
 * Author: Domekologe <support@domekologe.eu>
 */

namespace Modules\CertMonitor\Actions;

use API;
use CController;
use CControllerResponseData;
use CControllerResponseRedirect;
use CMessageHelper;
use CRoleHelper;
use CUrl;
use Manager;
use Modules\CertMonitor\Includes\CertHelper;

class CertView extends CController {

	protected function init(): void {
		// Read-only GET page, so no CSRF token is expected.
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'hostid' => 'required|db hosts.hostid'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse($this->makeListRedirect(_('Invalid request.')));
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_MONITORING_HOSTS);
	}

	protected function doAction(): void {
		$hostid = (string) $this->getInput('hostid');

		$hosts = API::Host()->get([
			'output' => ['hostid', 'host', 'name', 'status', 'description'],
			'hostids' => [$hostid],
			'selectHostGroups' => ['groupid', 'name'],
			'selectMacros' => ['macro', 'value', 'description'],
			'selectInterfaces' => ['interfaceid', 'type', 'main', 'useip', 'ip', 'dns', 'port', 'available'],
			'selectTags' => ['tag', 'value'],
			'tags' => [[
				'tag' => CertHelper::HOST_TAG,
				'value' => CertHelper::HOST_TAG_VALUE,
				'operator' => TAG_OPERATOR_EQUAL
			]],
			'limit' => 1
		]);

		if (!$hosts) {
			$this->setResponse($this->makeListRedirect(
				_('This host does not exist, is not managed by Certificate Monitor, or you have no permissions to it.')
			));

			return;
		}

		$host = reset($hosts);

		$items = $this->getItems($hostid);

		$warn_days = CertHelper::getMacroValue($host['macros'], CertHelper::MACRO_EXPIRY_WARN);
		$avg_days = CertHelper::getMacroValue($host['macros'], CertHelper::MACRO_EXPIRY_AVG);
		$crit_days = CertHelper::getMacroValue($host['macros'], CertHelper::MACRO_EXPIRY_CRIT);

		$data = [
			'host' => $host,
			'certificate' => $this->getCertificate($items),
			'threshold_warning' => CertHelper::checkThresholdSanity($warn_days, $avg_days, $crit_days),
			'website' => CertHelper::getMacroValue($host['macros'], CertHelper::MACRO_HOSTNAME),
			'port' => CertHelper::getMacroValue($host['macros'], CertHelper::MACRO_PORT,
				(string) CertHelper::DEFAULT_PORT
			),
			'address' => CertHelper::getMacroValue($host['macros'], CertHelper::MACRO_IP),
			'warn_days' => $warn_days,
			'avg_days' => $avg_days,
			'crit_days' => $crit_days,
			'ignore_validation' => CertHelper::isValidationIgnored($host['macros']),
			'cert_macros' => $this->getCertMacros($host['macros']),
			'agent_interface' => $this->getAgentInterface($host['interfaces']),
			'items' => $items,
			'triggers' => $this->getTriggers($hostid),
			'can_edit' => $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN
				&& $this->checkAccess(CRoleHelper::UI_CONFIGURATION_HOSTS)
		];

		$response = new CControllerResponseData($data);
		$response->setTitle(_s('Certificate: %1$s', $host['name']));

		$this->setResponse($response);
	}

	/**
	 * Keep only the user macros written by this module, in a stable order.
	 *
	 * @param array $macros
	 *
	 * @return array
	 */
	private function getCertMacros(array $macros): array {
		$result = [];

		foreach ($macros as $macro) {
			if (CertHelper::isCertMacro((string) $macro['macro'])) {
				$result[] = $macro;
			}
		}

		usort($result, static function (array $a, array $b): int {
			return strcmp((string) $a['macro'], (string) $b['macro']);
		});

		return $result;
	}

	/**
	 * Return the primary agent interface, which is the one the master item uses.
	 *
	 * @param array $interfaces
	 *
	 * @return array|null
	 */
	private function getAgentInterface(array $interfaces): ?array {
		foreach ($interfaces as $interface) {
			if ((int) $interface['type'] === INTERFACE_TYPE_AGENT
					&& (int) $interface['main'] === INTERFACE_PRIMARY) {
				return $interface;
			}
		}

		return $interfaces ? reset($interfaces) : null;
	}

	/**
	 * All items of the host together with their latest value and the time it was collected.
	 *
	 * @param string $hostid
	 *
	 * @return array
	 */
	private function getItems(string $hostid): array {
		$items = API::Item()->get([
			'output' => ['itemid', 'name', 'key_', 'type', 'value_type', 'units', 'delay', 'status', 'error',
				'master_itemid'
			],
			'hostids' => [$hostid],
			'sortfield' => 'name',
			'preservekeys' => true
		]);

		if (!$items) {
			return [];
		}

		// Look back two years, so long-lived certificates with a slow interval still show a value.
		$last_values = Manager::History()->getLastValues($items, 1, 2 * SEC_PER_YEAR);

		$result = [];

		foreach ($items as $itemid => $item) {
			$has_value = array_key_exists($itemid, $last_values);

			$item['last_value'] = $has_value ? $last_values[$itemid][0]['value'] : null;
			$item['last_clock'] = $has_value ? (int) $last_values[$itemid][0]['clock'] : null;
			$item['is_master'] = ($item['master_itemid'] === null || $item['master_itemid'] == 0);

			$result[] = $item;
		}

		// Master item first, dependent items after it.
		usort($result, static function (array $a, array $b): int {
			if ($a['is_master'] !== $b['is_master']) {
				return $a['is_master'] ? -1 : 1;
			}

			return strcmp((string) $a['name'], (string) $b['name']);
		});

		return $result;
	}

	/**
	 * Build the "Certificate" section from the values that were actually collected.
	 *
	 * Primary source is the latest value of the MASTER item, which is the raw JSON document returned by
	 * web.certificate.get. That is the only source that shows every field of the certificate, including
	 * the ones this module does not create a dependent item for.
	 *
	 * Fallback: hosts created by an older version of this module have a master item with history
	 * disabled, so no raw value can ever be read from them. In that case the section is assembled from
	 * the latest values of the dependent items, which is a subset but still useful. Saving the entry once
	 * in the edit form switches the master item to a real history period and repairs this.
	 *
	 * @param array $items  The list produced by getItems(), which already carries the latest values.
	 *
	 * @return array
	 */
	private function getCertificate(array $items): array {
		$result = [
			// 'json', 'items' or '' when nothing could be read at all.
			'source' => '',
			'raw' => null,
			'clock' => null,
			'item_error' => '',
			'item_status' => null,
			'itemid' => null,
			'json_error' => '',
			'fields' => [],
			'days_left' => null
		];

		$by_key = [];
		$master = null;

		foreach ($items as $item) {
			$by_key[(string) $item['key_']] = $item;

			if ($item['is_master']) {
				$master = $item;
			}
		}

		if ($master !== null) {
			$result['itemid'] = (string) $master['itemid'];
			$result['item_error'] = (string) $master['error'];
			$result['item_status'] = (int) $master['status'];
			$result['clock'] = $master['last_clock'];
			$result['raw'] = $master['last_value'];
		}

		$cert = null;

		if ($result['raw'] !== null && trim((string) $result['raw']) !== '') {
			$cert = CertHelper::decodeCertificateJson((string) $result['raw']);

			if ($cert === null) {
				$result['json_error'] = _('The latest value of the master item is not a valid JSON object.');
			}
		}

		if ($cert !== null) {
			$result['source'] = 'json';
			$result['fields'] = [
				'version' => CertHelper::certValue($cert, 'x509.version'),
				'serial_number' => CertHelper::certValue($cert, 'x509.serial_number'),
				'signature_algorithm' => CertHelper::certValue($cert, 'x509.signature_algorithm'),
				'public_key_algorithm' => CertHelper::certValue($cert, 'x509.public_key_algorithm'),
				'subject' => CertHelper::certValue($cert, 'x509.subject'),
				'issuer' => CertHelper::certValue($cert, 'x509.issuer'),
				'alternative_names' => CertHelper::certValue($cert, 'x509.alternative_names'),
				'not_before_value' => CertHelper::certValue($cert, 'x509.not_before.value'),
				'not_before_timestamp' => CertHelper::certValue($cert, 'x509.not_before.timestamp'),
				'not_after_value' => CertHelper::certValue($cert, 'x509.not_after.value'),
				'not_after_timestamp' => CertHelper::certValue($cert, 'x509.not_after.timestamp'),
				'result_value' => CertHelper::certValue($cert, 'result.value'),
				'result_message' => CertHelper::certValue($cert, 'result.message'),
				'sha1_fingerprint' => CertHelper::certValue($cert, 'sha1_fingerprint'),
				'sha256_fingerprint' => CertHelper::certValue($cert, 'sha256_fingerprint')
			];
		}
		else {
			$value = static function (string $key) use ($by_key) {
				if (!array_key_exists($key, $by_key)) {
					return null;
				}

				$last = $by_key[$key]['last_value'];

				return ($last === null || $last === '') ? null : (string) $last;
			};

			$fields = [
				'version' => $value(CertHelper::KEY_VERSION),
				'serial_number' => $value(CertHelper::KEY_SERIAL),
				'signature_algorithm' => $value(CertHelper::KEY_SIGNATURE_ALGORITHM),
				'public_key_algorithm' => $value(CertHelper::KEY_PUBLIC_KEY_ALGORITHM),
				'subject' => $value(CertHelper::KEY_SUBJECT),
				'issuer' => $value(CertHelper::KEY_ISSUER),
				'alternative_names' => $value(CertHelper::KEY_ALT_NAMES),
				'not_before_value' => null,
				'not_before_timestamp' => $value(CertHelper::KEY_NOT_BEFORE),
				'not_after_value' => $value(CertHelper::KEY_NOT_AFTER_STR),
				'not_after_timestamp' => $value(CertHelper::KEY_NOT_AFTER),
				'result_value' => $value(CertHelper::KEY_VALIDATION),
				'result_message' => $value(CertHelper::KEY_MESSAGE),
				'sha1_fingerprint' => $value(CertHelper::KEY_FINGERPRINT_SHA1),
				'sha256_fingerprint' => $value(CertHelper::KEY_FINGERPRINT)
			];

			foreach ($fields as $field_value) {
				if ($field_value !== null) {
					$result['source'] = 'items';
					break;
				}
			}

			if ($result['source'] === 'items') {
				$result['fields'] = $fields;

				// Without the raw JSON, the newest dependent item value is the best "as of" time.
				foreach ($by_key as $item) {
					if (!$item['is_master'] && $item['last_clock'] !== null
							&& ($result['clock'] === null || $item['last_clock'] > $result['clock'])) {
						$result['clock'] = $item['last_clock'];
					}
				}
			}
		}

		if ($result['fields'] && $result['fields']['not_after_timestamp'] !== null
				&& ctype_digit((string) $result['fields']['not_after_timestamp'])) {
			$result['days_left'] = CertHelper::daysLeft((int) $result['fields']['not_after_timestamp']);
		}

		// Certificates issued for an appliance frequently carry IP SANs next to the DNS names. Those
		// matter when the service is reached by address, so they get their own row instead of being
		// buried in the alternative name list.
		if ($result['fields']) {
			$split = CertHelper::splitAlternativeNames($result['fields']['alternative_names'] ?? null);

			$result['fields']['alternative_names_dns'] = $split['dns'] ? implode(', ', $split['dns']) : null;
			$result['fields']['alternative_names_ips'] = $split['ips'] ? implode(', ', $split['ips']) : null;
		}

		return $result;
	}

	/**
	 * All triggers of the host with a readable expression.
	 *
	 * @param string $hostid
	 *
	 * @return array
	 */
	private function getTriggers(string $hostid): array {
		$triggers = API::Trigger()->get([
			'output' => ['triggerid', 'description', 'expression', 'priority', 'status', 'comments', 'value'],
			'hostids' => [$hostid],
			'expandDescription' => true,
			'expandExpression' => true,
			'sortfield' => 'priority',
			'sortorder' => ZBX_SORT_DOWN
		]);

		return $triggers ? $triggers : [];
	}

	/**
	 * Redirect back to the list with an error message.
	 *
	 * @param string $message
	 *
	 * @return CControllerResponseRedirect
	 */
	private function makeListRedirect(string $message): CControllerResponseRedirect {
		error($message);
		CMessageHelper::setErrorTitle(_('Cannot show website'));

		return new CControllerResponseRedirect(
			(new CUrl('zabbix.php'))->setArgument('action', 'certmonitor.list')
		);
	}
}
