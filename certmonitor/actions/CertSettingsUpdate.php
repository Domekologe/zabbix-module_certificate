<?php declare(strict_types = 1);
/**
 * Certificate Monitor - stores the module settings.
 *
 * Author: Domekologe <support@domekologe.eu>
 */

namespace Modules\CertMonitor\Actions;

use API;
use CController;
use CControllerResponseRedirect;
use CMessageHelper;
use CUrl;
use Modules\CertMonitor\Includes\CertConfig;
use Modules\CertMonitor\Includes\CertHelper;

class CertSettingsUpdate extends CController {

	protected function checkInput(): bool {
		$fields = [
			'default_port' =>				'required|int32|ge 1|le 65535',
			'default_agent_address' =>		'required|string|not_empty',
			'default_agent_port' =>			'required|int32|ge 1|le 65535',
			'default_groupid' =>			'string',
			'default_warn_days' =>			'required|int32|ge 1|le 3650',
			'default_avg_days' =>			'required|int32|ge 1|le 3650',
			'default_crit_days' =>			'required|int32|ge 1|le 3650',
			// An unchecked checkbox submits nothing at all, therefore this field is optional.
			'default_ignore_validation' =>	'in 0,1',
			'default_delay' =>				'required|string|not_empty',
			'host_prefix' =>				'string',
			// An unchecked checkbox submits nothing at all, therefore these fields are optional.
			'default_sec_issuer_changed' =>			'in 0,1',
			'default_sec_fingerprint_changed' =>	'in 0,1',
			'default_sec_weak_key' =>				'in 0,1',
			'default_sec_weak_signature' =>			'in 0,1',
			'default_weak_key_algorithms' =>		'required|string|not_empty',
			'default_weak_signature_algorithms' =>	'required|string|not_empty',
			'default_issuer_severity' =>			'required|int32',
			'default_fingerprint_severity' =>		'required|int32'
		];

		$ret = $this->validateInput($fields);

		if ($ret) {
			$ret = $this->checkSemantics();
		}

		if (!$ret) {
			$this->setResponse($this->makeErrorResponse());
		}

		return $ret;
	}

	/**
	 * Additional checks that the declarative validator cannot express.
	 *
	 * @return bool
	 */
	private function checkSemantics(): bool {
		$ret = true;

		$agent_address = trim((string) $this->getInput('default_agent_address'));

		if (!CertHelper::isValidHostname($agent_address)) {
			error(_s('Incorrect value for field "%1$s": %2$s.', _('Default Zabbix agent address'),
				_('a valid DNS name or IP address is expected')
			));
			$ret = false;
		}

		$warn_days = (int) $this->getInput('default_warn_days');
		$avg_days = (int) $this->getInput('default_avg_days');
		$crit_days = (int) $this->getInput('default_crit_days');

		if (!($warn_days > $avg_days && $avg_days > $crit_days)) {
			error(_('Warning thresholds must satisfy: warning > average > high (in days).'));
			$ret = false;
		}

		// A Zabbix update interval is either a plain number of seconds or a number with a time suffix.
		$delay = trim((string) $this->getInput('default_delay'));

		if (!preg_match('/^[0-9]+[smhdw]?$/', $delay) || (int) $delay < 1) {
			error(_s('Incorrect value for field "%1$s": %2$s.', _('Default update interval'),
				_('a positive number with an optional s, m, h, d or w suffix is expected')
			));
			$ret = false;
		}

		$prefix = trim((string) $this->getInput('host_prefix', ''));

		if ($prefix !== '' && CertHelper::sanitizeHostPrefix($prefix) !== $prefix) {
			error(_s('Incorrect value for field "%1$s": %2$s.', _('Host name prefix'),
				_('only letters, digits, dots, dashes and underscores are allowed')
			));
			$ret = false;
		}

		foreach ([
			'default_weak_key_algorithms' => _('Weak public key algorithms'),
			'default_weak_signature_algorithms' => _('Weak signature algorithms')
		] as $field => $label) {
			if (!CertHelper::isValidAlgorithmPattern(trim((string) $this->getInput($field)))) {
				error(_s('Incorrect value for field "%1$s": %2$s.', $label,
					_('a valid regular expression without double quotes or backslashes is expected')
				));
				$ret = false;
			}
		}

		foreach ([
			'default_issuer_severity' => _('Severity of "issuer changed"'),
			'default_fingerprint_severity' => _('Severity of "certificate replaced"')
		] as $field => $label) {
			if (!CertHelper::isValidSeverity((int) $this->getInput($field))) {
				error(_s('Incorrect value for field "%1$s": %2$s.', $label, _('a trigger severity is expected')));
				$ret = false;
			}
		}

		$groupid = trim((string) $this->getInput('default_groupid', ''));

		if ($groupid !== '') {
			if (!ctype_digit($groupid)) {
				error(_s('Incorrect value for field "%1$s": %2$s.', _('Default host group'),
					_('a host group is expected')
				));
				$ret = false;
			}
			else {
				$groups = API::HostGroup()->get([
					'output' => ['groupid'],
					'groupids' => [$groupid],
					'editable' => true
				]);

				if (!$groups) {
					error(_('No permissions to the selected host group or it does not exist.'));
					$ret = false;
				}
			}
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		// API::Module()->update() is restricted to Super admins, so anything less cannot store settings.
		return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function doAction(): void {
		$config = [
			CertConfig::DEFAULT_PORT => (string) (int) $this->getInput('default_port'),
			CertConfig::DEFAULT_AGENT_ADDRESS => trim((string) $this->getInput('default_agent_address')),
			CertConfig::DEFAULT_AGENT_PORT => (string) (int) $this->getInput('default_agent_port'),
			CertConfig::DEFAULT_GROUPID => trim((string) $this->getInput('default_groupid', '')),
			CertConfig::DEFAULT_WARN_DAYS => (string) (int) $this->getInput('default_warn_days'),
			CertConfig::DEFAULT_AVG_DAYS => (string) (int) $this->getInput('default_avg_days'),
			CertConfig::DEFAULT_CRIT_DAYS => (string) (int) $this->getInput('default_crit_days'),
			CertConfig::DEFAULT_IGNORE_VALIDATION =>
				(int) $this->getInput('default_ignore_validation', 0) === 1 ? '1' : '0',
			CertConfig::DEFAULT_DELAY => trim((string) $this->getInput('default_delay')),
			CertConfig::HOST_PREFIX => CertHelper::sanitizeHostPrefix(
				trim((string) $this->getInput('host_prefix', ''))
			),
			CertConfig::DEFAULT_SEC_ISSUER_CHANGED =>
				(int) $this->getInput('default_sec_issuer_changed', 0) === 1 ? '1' : '0',
			CertConfig::DEFAULT_SEC_FINGERPRINT_CHANGED =>
				(int) $this->getInput('default_sec_fingerprint_changed', 0) === 1 ? '1' : '0',
			CertConfig::DEFAULT_SEC_WEAK_KEY =>
				(int) $this->getInput('default_sec_weak_key', 0) === 1 ? '1' : '0',
			CertConfig::DEFAULT_SEC_WEAK_SIGNATURE =>
				(int) $this->getInput('default_sec_weak_signature', 0) === 1 ? '1' : '0',
			CertConfig::DEFAULT_WEAK_KEY_ALGORITHMS => trim((string) $this->getInput(
				'default_weak_key_algorithms'
			)),
			CertConfig::DEFAULT_WEAK_SIGNATURE_ALGORITHMS => trim((string) $this->getInput(
				'default_weak_signature_algorithms'
			)),
			CertConfig::DEFAULT_ISSUER_SEVERITY => (string) (int) $this->getInput('default_issuer_severity'),
			CertConfig::DEFAULT_FINGERPRINT_SEVERITY =>
				(string) (int) $this->getInput('default_fingerprint_severity')
		];

		try {
			CertConfig::save($config);
		}
		catch (\Exception $e) {
			error($e->getMessage());
			CMessageHelper::setErrorTitle(_('Cannot update settings'));

			$this->setResponse($this->makeErrorResponse());

			return;
		}

		CMessageHelper::setSuccessTitle(_('Settings updated'));

		$this->setResponse(new CControllerResponseRedirect(
			(new CUrl('zabbix.php'))->setArgument('action', 'certmonitor.settings')
		));
	}

	/**
	 * Build a redirect back to the settings form, keeping the submitted values.
	 *
	 * @return CControllerResponseRedirect
	 */
	private function makeErrorResponse(): CControllerResponseRedirect {
		$response = new CControllerResponseRedirect(
			(new CUrl('zabbix.php'))->setArgument('action', 'certmonitor.settings')
		);

		$form_data = $this->getInputAll();
		unset($form_data[CSRF_TOKEN_NAME], $form_data['action']);
		$form_data['form_refresh'] = 1;

		$response->setFormData($form_data);

		if (CMessageHelper::getTitle() === null) {
			CMessageHelper::setErrorTitle(_('Cannot update settings'));
		}

		return $response;
	}
}
