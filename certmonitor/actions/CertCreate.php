<?php declare(strict_types = 1);
/**
 * Certificate Monitor - creates the host, items, triggers and macros for a monitored website.
 *
 * This controller only validates the form and hands the values to CertProvision::create(), which is the
 * single place where the Zabbix configuration is actually built. The bulk import uses the very same
 * method, so a change to the created objects automatically applies to both paths.
 *
 * See https://www.zabbix.com/documentation/7.4/en/manual/config/items/itemtypes/zabbix_agent/zabbix_agent2
 *
 * Author: Domekologe <support@domekologe.eu>
 */

namespace Modules\CertMonitor\Actions;

use CController;
use CControllerResponseRedirect;
use CMessageHelper;
use CRoleHelper;
use CUrl;
use Modules\CertMonitor\Includes\CertConfig;
use Modules\CertMonitor\Includes\CertHelper;
use Modules\CertMonitor\Includes\CertProvision;

class CertCreate extends CController {

	protected function checkInput(): bool {
		$fields = [
			'hostname' =>		'required|string|not_empty',
			'visible_name' =>	'string',
			'port' =>			'required|int32|ge 1|le 65535',
			'address' =>		'string',
			'groupid' =>		'required|id',
			'agent_address' =>	'required|string|not_empty',
			'agent_port' =>		'required|int32|ge 1|le 65535',
			'description' =>	'db hosts.description',
			'tags' =>			'string',
			'warn_days' =>		'required|int32|ge 1|le 3650',
			'avg_days' =>		'required|int32|ge 1|le 3650',
			'crit_days' =>		'required|int32|ge 1|le 3650',
			// An unchecked checkbox submits nothing at all, therefore these fields are optional.
			'ignore_validation' =>			'in 0,1',
			'sec_issuer_changed' =>			'in 0,1',
			'sec_fingerprint_changed' =>	'in 0,1',
			'sec_weak_key' =>				'in 0,1',
			'sec_weak_signature' =>			'in 0,1',
			'host_status' =>	'in '.HOST_STATUS_MONITORED.','.HOST_STATUS_NOT_MONITORED
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

		$hostname = trim((string) $this->getInput('hostname'));
		$address = trim((string) $this->getInput('address', ''));
		$agent_address = trim((string) $this->getInput('agent_address'));

		if (!CertHelper::isValidHostname($hostname)) {
			error(_s('Incorrect value for field "%1$s": %2$s.', _('Hostname/FQDN'),
				_('a valid DNS name or IP address is expected')
			));
			$ret = false;
		}

		if ($address !== '' && !CertHelper::isValidHostname($address)) {
			error(_s('Incorrect value for field "%1$s": %2$s.', _('IP/address override'),
				_('a valid DNS name or IP address is expected')
			));
			$ret = false;
		}

		if (!CertHelper::isValidHostname($agent_address)) {
			error(_s('Incorrect value for field "%1$s": %2$s.', _('Zabbix agent address'),
				_('a valid DNS name or IP address is expected')
			));
			$ret = false;
		}

		$warn_days = (int) $this->getInput('warn_days');
		$avg_days = (int) $this->getInput('avg_days');
		$crit_days = (int) $this->getInput('crit_days');

		if (!($warn_days > $avg_days && $avg_days > $crit_days)) {
			error(_('Warning thresholds must satisfy: warning > average > high (in days).'));
			$ret = false;
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN
			&& $this->checkAccess(CRoleHelper::UI_CONFIGURATION_HOSTS);
	}

	protected function doAction(): void {
		$hostname = trim((string) $this->getInput('hostname'));
		$port = (int) $this->getInput('port');

		$settings = CertConfig::get();

		// The org-wide defaults are the base; every field of the form overrides its own default only.
		$params = CertProvision::paramsFromSettings($settings);

		$params['hostname'] = $hostname;
		$params['port'] = $port;
		$params['address'] = trim((string) $this->getInput('address', ''));
		$params['groupid'] = (string) $this->getInput('groupid');
		$params['agent_address'] = trim((string) $this->getInput('agent_address'));
		$params['agent_port'] = (int) $this->getInput('agent_port');
		$params['visible_name'] = trim((string) $this->getInput('visible_name', ''));
		$params['description'] = (string) $this->getInput('description', '');
		$params['tags'] = CertHelper::parseTagsText((string) $this->getInput('tags', ''));
		$params['warn_days'] = (int) $this->getInput('warn_days');
		$params['avg_days'] = (int) $this->getInput('avg_days');
		$params['crit_days'] = (int) $this->getInput('crit_days');
		$params['ignore_validation'] = (int) $this->getInput('ignore_validation', 0) === 1;
		$params['host_status'] = (int) $this->getInput('host_status', HOST_STATUS_MONITORED);
		$params['sec_issuer_changed'] = (int) $this->getInput('sec_issuer_changed', 0) === 1;
		$params['sec_fingerprint_changed'] = (int) $this->getInput('sec_fingerprint_changed', 0) === 1;
		$params['sec_weak_key'] = (int) $this->getInput('sec_weak_key', 0) === 1;
		$params['sec_weak_signature'] = (int) $this->getInput('sec_weak_signature', 0) === 1;

		try {
			CertProvision::create($params);
		}
		catch (\Exception $e) {
			// CertProvision::create() already rolled back a partially created host.
			error($e->getMessage());
			CMessageHelper::setErrorTitle(_('Cannot add website'));

			$this->setResponse($this->makeErrorResponse());

			return;
		}

		CMessageHelper::setSuccessTitle(_s('Website "%1$s" added.', CertHelper::makeTarget($hostname, $port)));

		$this->setResponse(new CControllerResponseRedirect(
			(new CUrl('zabbix.php'))->setArgument('action', 'certmonitor.list')
		));
	}

	/**
	 * Build a redirect back to the "Add website" form, keeping the submitted values.
	 *
	 * @return CControllerResponseRedirect
	 */
	private function makeErrorResponse(): CControllerResponseRedirect {
		$response = new CControllerResponseRedirect(
			(new CUrl('zabbix.php'))->setArgument('action', 'certmonitor.edit')
		);

		$form_data = $this->getInputAll();
		unset($form_data[CSRF_TOKEN_NAME], $form_data['action']);
		$form_data['form_refresh'] = 1;

		$response->setFormData($form_data);

		if (CMessageHelper::getTitle() === null) {
			CMessageHelper::setErrorTitle(_('Cannot add website'));
		}

		return $response;
	}
}
