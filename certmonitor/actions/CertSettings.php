<?php declare(strict_types = 1);
/**
 * Certificate Monitor - the module settings form.
 *
 * These settings only pre-fill the "Add website" form. Changing them never touches an existing host.
 *
 * Writing the settings goes through API::Module()->update(), which Zabbix restricts to Super admins
 * (see CertConfig for the verification details), therefore this page is Super admin only.
 *
 * Author: Domekologe <support@domekologe.eu>
 */

namespace Modules\CertMonitor\Actions;

use API;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use Modules\CertMonitor\Includes\CertConfig;

class CertSettings extends CController {

	protected function init(): void {
		// The form is opened with GET; the CSRF token is validated by "certmonitor.settings.update".
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'default_port' =>				'string',
			'default_agent_address' =>		'string',
			'default_agent_port' =>			'string',
			'default_groupid' =>			'string',
			'default_warn_days' =>			'string',
			'default_avg_days' =>			'string',
			'default_crit_days' =>			'string',
			'default_ignore_validation' =>	'in 0,1',
			'default_delay' =>				'string',
			'host_prefix' =>				'string',
			// An unchecked checkbox submits nothing at all, therefore these fields are optional.
			'default_sec_issuer_changed' =>			'in 0,1',
			'default_sec_fingerprint_changed' =>	'in 0,1',
			'default_sec_weak_key' =>				'in 0,1',
			'default_sec_weak_signature' =>			'in 0,1',
			'default_weak_key_algorithms' =>		'string',
			'default_weak_signature_algorithms' =>	'string',
			'default_issuer_severity' =>			'string',
			'default_fingerprint_severity' =>		'string',
			'form_refresh' =>				'in 1'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function doAction(): void {
		$stored = CertConfig::get();

		$data = [
			'default_port' => trim((string) $this->getInput(CertConfig::DEFAULT_PORT,
				$stored[CertConfig::DEFAULT_PORT]
			)),
			'default_agent_address' => trim((string) $this->getInput(CertConfig::DEFAULT_AGENT_ADDRESS,
				$stored[CertConfig::DEFAULT_AGENT_ADDRESS]
			)),
			'default_agent_port' => trim((string) $this->getInput(CertConfig::DEFAULT_AGENT_PORT,
				$stored[CertConfig::DEFAULT_AGENT_PORT]
			)),
			'default_groupid' => trim((string) $this->getInput(CertConfig::DEFAULT_GROUPID,
				$stored[CertConfig::DEFAULT_GROUPID]
			)),
			'default_warn_days' => trim((string) $this->getInput(CertConfig::DEFAULT_WARN_DAYS,
				$stored[CertConfig::DEFAULT_WARN_DAYS]
			)),
			'default_avg_days' => trim((string) $this->getInput(CertConfig::DEFAULT_AVG_DAYS,
				$stored[CertConfig::DEFAULT_AVG_DAYS]
			)),
			'default_crit_days' => trim((string) $this->getInput(CertConfig::DEFAULT_CRIT_DAYS,
				$stored[CertConfig::DEFAULT_CRIT_DAYS]
			)),
			'default_ignore_validation' => $this->hasInput('form_refresh')
				? (int) $this->getInput(CertConfig::DEFAULT_IGNORE_VALIDATION, 0) === 1
				: $stored[CertConfig::DEFAULT_IGNORE_VALIDATION] === '1',
			'default_delay' => trim((string) $this->getInput(CertConfig::DEFAULT_DELAY,
				$stored[CertConfig::DEFAULT_DELAY]
			)),
			'host_prefix' => trim((string) $this->getInput(CertConfig::HOST_PREFIX,
				$stored[CertConfig::HOST_PREFIX]
			)),
			'default_sec_issuer_changed' => $this->getCheckboxState(CertConfig::DEFAULT_SEC_ISSUER_CHANGED,
				$stored
			),
			'default_sec_fingerprint_changed' => $this->getCheckboxState(
				CertConfig::DEFAULT_SEC_FINGERPRINT_CHANGED, $stored
			),
			'default_sec_weak_key' => $this->getCheckboxState(CertConfig::DEFAULT_SEC_WEAK_KEY, $stored),
			'default_sec_weak_signature' => $this->getCheckboxState(CertConfig::DEFAULT_SEC_WEAK_SIGNATURE,
				$stored
			),
			'default_weak_key_algorithms' => trim((string) $this->getInput(
				CertConfig::DEFAULT_WEAK_KEY_ALGORITHMS, $stored[CertConfig::DEFAULT_WEAK_KEY_ALGORITHMS]
			)),
			'default_weak_signature_algorithms' => trim((string) $this->getInput(
				CertConfig::DEFAULT_WEAK_SIGNATURE_ALGORITHMS,
				$stored[CertConfig::DEFAULT_WEAK_SIGNATURE_ALGORITHMS]
			)),
			'default_issuer_severity' => trim((string) $this->getInput(CertConfig::DEFAULT_ISSUER_SEVERITY,
				$stored[CertConfig::DEFAULT_ISSUER_SEVERITY]
			)),
			'default_fingerprint_severity' => trim((string) $this->getInput(
				CertConfig::DEFAULT_FINGERPRINT_SEVERITY, $stored[CertConfig::DEFAULT_FINGERPRINT_SEVERITY]
			)),
			'host_groups' => $this->getHostGroups(),
			'builtin_defaults' => CertConfig::getDefaults(),
			'is_stored' => CertConfig::isStored()
		];

		$response = new CControllerResponseData($data);
		$response->setTitle(_('Certificate Monitor settings'));

		$this->setResponse($response);
	}

	/**
	 * State of one checkbox of the settings form.
	 *
	 * An unchecked checkbox submits nothing at all, so only a request that comes back from a rejected
	 * submit ("form_refresh") may read a missing value as "unchecked". Otherwise the stored value wins.
	 *
	 * @param string $name    Setting name.
	 * @param array  $stored  Stored settings.
	 *
	 * @return bool
	 */
	private function getCheckboxState(string $name, array $stored): bool {
		if ($this->hasInput('form_refresh')) {
			return (int) $this->getInput($name, 0) === 1;
		}

		return $stored[$name] === '1';
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
