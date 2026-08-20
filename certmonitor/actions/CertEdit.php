<?php declare(strict_types = 1);
/**
 * Certificate Monitor - the "Add website" / "Edit website" form.
 *
 * One controller and one view serve three purposes, selected by the request parameters:
 *
 *   - no parameters              -> "Add website", pre-filled from the module settings;
 *   - hostid=<id>                -> "Edit website", pre-filled from that host;
 *   - hostid=<id> and clone=1    -> "Add website", pre-filled from that host but without its identity.
 *
 * Submitted values always win over the pre-filled ones, so that a rejected form comes back with exactly
 * what the user typed.
 *
 * Author: Domekologe <support@domekologe.eu>
 */

namespace Modules\CertMonitor\Actions;

use API;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CRoleHelper;
use Modules\CertMonitor\Includes\CertConfig;
use Modules\CertMonitor\Includes\CertHelper;

class CertEdit extends CController {

	/**
	 * The host being edited, or null in add mode.
	 *
	 * @var array|null
	 */
	private ?array $host = null;

	protected function init(): void {
		// The form itself is opened with GET; the CSRF token is validated by create/update.
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'hostid' =>			'db hosts.hostid',
			'clone' =>			'in 1',
			'hostname' =>		'string',
			'visible_name' =>	'string',
			'port' =>			'string',
			'address' =>		'string',
			'groupid' =>		'string',
			'agent_address' =>	'string',
			'agent_port' =>		'string',
			'description' =>	'string',
			'tags' =>			'string',
			'warn_days' =>		'string',
			'avg_days' =>		'string',
			'crit_days' =>		'string',
			// An unchecked checkbox submits nothing at all, therefore these fields are optional.
			'ignore_validation' =>			'in 0,1',
			'sec_issuer_changed' =>			'in 0,1',
			'sec_fingerprint_changed' =>	'in 0,1',
			'sec_weak_key' =>				'in 0,1',
			'sec_weak_signature' =>			'in 0,1',
			'host_status' =>	'in '.HOST_STATUS_MONITORED.','.HOST_STATUS_NOT_MONITORED,
			'form_refresh' =>	'in 1'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		if ($this->getUserType() < USER_TYPE_ZABBIX_ADMIN
				|| !$this->checkAccess(CRoleHelper::UI_CONFIGURATION_HOSTS)) {
			return false;
		}

		if (!$this->hasInput('hostid')) {
			return true;
		}

		// Only a writable host that is really managed by this module may be opened in this form.
		$hosts = API::Host()->get([
			'output' => ['hostid', 'host', 'name', 'status', 'description'],
			'hostids' => [$this->getInput('hostid')],
			'selectHostGroups' => ['groupid', 'name'],
			'selectMacros' => ['macro', 'value'],
			'selectInterfaces' => ['interfaceid', 'type', 'main', 'useip', 'ip', 'dns', 'port'],
			'selectTags' => ['tag', 'value'],
			'tags' => [[
				'tag' => CertHelper::HOST_TAG,
				'value' => CertHelper::HOST_TAG_VALUE,
				'operator' => TAG_OPERATOR_EQUAL
			]],
			'editable' => true,
			'limit' => 1
		]);

		if (!$hosts) {
			return false;
		}

		$this->host = reset($hosts);

		return true;
	}

	protected function doAction(): void {
		$settings = CertConfig::get();
		$is_clone = $this->host !== null && $this->getInput('clone', 0) == 1;
		$is_edit = $this->host !== null && !$is_clone;

		$defaults = $this->host !== null
			? $this->getHostDefaults($this->host)
			: $this->getSettingsDefaults($settings);

		if ($is_clone) {
			// A clone must not reuse the identity of the original host.
			$defaults['visible_name'] = '';
			$defaults['hostname'] = '';
		}

		$data = [
			'hostid' => $is_edit ? (string) $this->host['hostid'] : '',
			'is_edit' => $is_edit,
			'is_clone' => $is_clone,
			'host_name' => $this->host !== null ? (string) $this->host['host'] : '',
			'hostname' => trim((string) $this->getInput('hostname', $defaults['hostname'])),
			'visible_name' => trim((string) $this->getInput('visible_name', $defaults['visible_name'])),
			'port' => trim((string) $this->getInput('port', $defaults['port'])),
			'address' => trim((string) $this->getInput('address', $defaults['address'])),
			'groupid' => trim((string) $this->getInput('groupid', $defaults['groupid'])),
			'agent_address' => trim((string) $this->getInput('agent_address', $defaults['agent_address'])),
			'agent_port' => trim((string) $this->getInput('agent_port', $defaults['agent_port'])),
			'description' => (string) $this->getInput('description', $defaults['description']),
			'tags' => (string) $this->getInput('tags', $defaults['tags']),
			'warn_days' => trim((string) $this->getInput('warn_days', $defaults['warn_days'])),
			'avg_days' => trim((string) $this->getInput('avg_days', $defaults['avg_days'])),
			'crit_days' => trim((string) $this->getInput('crit_days', $defaults['crit_days'])),
			'ignore_validation' => $this->getCheckboxState('ignore_validation', $defaults),
			'sec_issuer_changed' => $this->getCheckboxState('sec_issuer_changed', $defaults),
			'sec_fingerprint_changed' => $this->getCheckboxState('sec_fingerprint_changed', $defaults),
			'sec_weak_key' => $this->getCheckboxState('sec_weak_key', $defaults),
			'sec_weak_signature' => $this->getCheckboxState('sec_weak_signature', $defaults),
			'host_status' => (int) $this->getInput('host_status', $defaults['host_status']),
			'host_groups' => $this->getHostGroups()
		];

		$response = new CControllerResponseData($data);
		$response->setTitle($is_edit ? _('Edit website') : _('Add website'));

		$this->setResponse($response);
	}

	/**
	 * State of one checkbox of the form.
	 *
	 * An unchecked checkbox submits nothing at all, so a submitted value cannot be distinguished from an
	 * absent one by presence alone. "form_refresh" marks a request that comes back from a rejected
	 * submit: in that case a missing value really means "unchecked", while otherwise it means "not
	 * submitted at all" and the pre-filled default wins.
	 *
	 * @param string $field
	 * @param array  $defaults
	 *
	 * @return bool
	 */
	private function getCheckboxState(string $field, array $defaults): bool {
		if ($this->hasInput($field) || $this->hasInput('form_refresh')) {
			return (int) $this->getInput($field, 0) === 1;
		}

		return (bool) $defaults[$field];
	}

	/**
	 * Form defaults for a new entry, taken from the module settings.
	 *
	 * @param array $settings
	 *
	 * @return array
	 */
	private function getSettingsDefaults(array $settings): array {
		return [
			'hostname' => '',
			'visible_name' => '',
			'port' => $settings[CertConfig::DEFAULT_PORT],
			'address' => '',
			'groupid' => $settings[CertConfig::DEFAULT_GROUPID],
			'agent_address' => $settings[CertConfig::DEFAULT_AGENT_ADDRESS],
			'agent_port' => $settings[CertConfig::DEFAULT_AGENT_PORT],
			'description' => '',
			'tags' => '',
			'warn_days' => $settings[CertConfig::DEFAULT_WARN_DAYS],
			'avg_days' => $settings[CertConfig::DEFAULT_AVG_DAYS],
			'crit_days' => $settings[CertConfig::DEFAULT_CRIT_DAYS],
			'ignore_validation' => $settings[CertConfig::DEFAULT_IGNORE_VALIDATION] === '1',
			'sec_issuer_changed' => $settings[CertConfig::DEFAULT_SEC_ISSUER_CHANGED] === '1',
			'sec_fingerprint_changed' => $settings[CertConfig::DEFAULT_SEC_FINGERPRINT_CHANGED] === '1',
			'sec_weak_key' => $settings[CertConfig::DEFAULT_SEC_WEAK_KEY] === '1',
			'sec_weak_signature' => $settings[CertConfig::DEFAULT_SEC_WEAK_SIGNATURE] === '1',
			'host_status' => HOST_STATUS_MONITORED
		];
	}

	/**
	 * Form defaults taken from an existing host.
	 *
	 * @param array $host
	 *
	 * @return array
	 */
	private function getHostDefaults(array $host): array {
		$interface = null;

		foreach ($host['interfaces'] as $candidate) {
			if ((int) $candidate['type'] === INTERFACE_TYPE_AGENT
					&& (int) $candidate['main'] === INTERFACE_PRIMARY) {
				$interface = $candidate;
				break;
			}
		}

		$settings = CertConfig::get();

		$agent_address = $settings[CertConfig::DEFAULT_AGENT_ADDRESS];
		$agent_port = $settings[CertConfig::DEFAULT_AGENT_PORT];

		if ($interface !== null) {
			$agent_address = (int) $interface['useip'] === INTERFACE_USE_IP
				? (string) $interface['ip']
				: (string) $interface['dns'];
			$agent_port = (string) $interface['port'];
		}

		$groupid = '';

		if ($host['hostgroups']) {
			$first_group = reset($host['hostgroups']);
			$groupid = (string) $first_group['groupid'];
		}

		return [
			'hostname' => CertHelper::getMacroValue($host['macros'], CertHelper::MACRO_HOSTNAME),
			'visible_name' => (string) $host['name'],
			'port' => CertHelper::getMacroValue($host['macros'], CertHelper::MACRO_PORT,
				(string) CertHelper::DEFAULT_PORT
			),
			'address' => CertHelper::getMacroValue($host['macros'], CertHelper::MACRO_IP),
			'groupid' => $groupid,
			'agent_address' => $agent_address,
			'agent_port' => $agent_port,
			'description' => (string) $host['description'],
			'tags' => CertHelper::tagsToText($host['tags']),
			'warn_days' => CertHelper::getMacroValue($host['macros'], CertHelper::MACRO_EXPIRY_WARN,
				(string) CertHelper::DEFAULT_WARN_DAYS
			),
			'avg_days' => CertHelper::getMacroValue($host['macros'], CertHelper::MACRO_EXPIRY_AVG,
				(string) CertHelper::DEFAULT_AVG_DAYS
			),
			'crit_days' => CertHelper::getMacroValue($host['macros'], CertHelper::MACRO_EXPIRY_CRIT,
				(string) CertHelper::DEFAULT_CRIT_DAYS
			),
			'ignore_validation' => CertHelper::isValidationIgnored($host['macros']),
			// A host created by an older version of this module has no {$CERT.SEC.*} macro at all. In that
			// case the org-wide default decides what the checkbox shows, so that saving such a host once
			// applies the organisation's policy to it.
			'sec_issuer_changed' => CertHelper::isSecurityTriggerEnabled($host['macros'],
				CertHelper::MACRO_SEC_ISSUER_CHANGED,
				$settings[CertConfig::DEFAULT_SEC_ISSUER_CHANGED] === '1'
			),
			'sec_fingerprint_changed' => CertHelper::isSecurityTriggerEnabled($host['macros'],
				CertHelper::MACRO_SEC_FINGERPRINT_CHANGED,
				$settings[CertConfig::DEFAULT_SEC_FINGERPRINT_CHANGED] === '1'
			),
			'sec_weak_key' => CertHelper::isSecurityTriggerEnabled($host['macros'],
				CertHelper::MACRO_SEC_WEAK_KEY,
				$settings[CertConfig::DEFAULT_SEC_WEAK_KEY] === '1'
			),
			'sec_weak_signature' => CertHelper::isSecurityTriggerEnabled($host['macros'],
				CertHelper::MACRO_SEC_WEAK_SIGNATURE,
				$settings[CertConfig::DEFAULT_SEC_WEAK_SIGNATURE] === '1'
			),
			'host_status' => (int) $host['status']
		];
	}

	/**
	 * Return all host groups the current user is allowed to write to.
	 *
	 * @return array  Array of ['groupid' => ..., 'name' => ...].
	 */
	private function getHostGroups(): array {
		$groups = API::HostGroup()->get([
			'output' => ['groupid', 'name'],
			'editable' => true,
			'sortfield' => 'name'
		]);

		return $groups ?: [];
	}
}
