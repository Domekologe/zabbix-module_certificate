<?php declare(strict_types = 1);
/**
 * Certificate Monitor - shared base for the "Enable" and "Disable" bulk actions.
 *
 * Enabling or disabling a monitored website means switching the status of its host, which stops or
 * resumes all of its items and triggers at once.
 *
 * Author: Domekologe <support@domekologe.eu>
 */

namespace Modules\CertMonitor\Actions;

use API;
use CController;
use CControllerResponseRedirect;
use CMessageHelper;
use CRoleHelper;
use CUrl;
use Modules\CertMonitor\Includes\CertHelper;

abstract class CertStatusUpdate extends CController {

	/**
	 * The host status this action switches to.
	 *
	 * @return int  HOST_STATUS_MONITORED or HOST_STATUS_NOT_MONITORED.
	 */
	abstract protected function getTargetStatus(): int;

	protected function checkInput(): bool {
		$fields = [
			'hostids' => 'required|array_id'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			CMessageHelper::setErrorTitle($this->getErrorTitle(1));

			$this->setResponse($this->makeListRedirect());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN
			&& $this->checkAccess(CRoleHelper::UI_CONFIGURATION_HOSTS);
	}

	protected function doAction(): void {
		$hostids = $this->getInput('hostids');
		$status = $this->getTargetStatus();

		// Only hosts that are writable AND tagged as managed by this module may be switched here.
		$hosts = API::Host()->get([
			'output' => ['hostid'],
			'hostids' => $hostids,
			'tags' => [[
				'tag' => CertHelper::HOST_TAG,
				'value' => CertHelper::HOST_TAG_VALUE,
				'operator' => TAG_OPERATOR_EQUAL
			]],
			'editable' => true,
			'preservekeys' => true
		]);

		$allowed_hostids = array_keys($hosts);
		$count = count($allowed_hostids);
		$response = $this->makeListRedirect();

		if ($count != count($hostids)) {
			error(_('No permissions to referred object or it does not exist.'));
			CMessageHelper::setErrorTitle($this->getErrorTitle(count($hostids)));

			$this->setResponse($response);

			return;
		}

		$update = [];

		foreach ($allowed_hostids as $hostid) {
			$update[] = ['hostid' => (string) $hostid, 'status' => $status];
		}

		if (API::Host()->update($update)) {
			$response->setFormData(['uncheck' => '1']);
			CMessageHelper::setSuccessTitle($this->getSuccessTitle($count));
		}
		else {
			CMessageHelper::setErrorTitle($this->getErrorTitle($count));
		}

		$this->setResponse($response);
	}

	/**
	 * @param int $count
	 *
	 * @return string
	 */
	private function getSuccessTitle(int $count): string {
		return $this->getTargetStatus() === HOST_STATUS_MONITORED
			? _n('Monitoring enabled', 'Monitoring enabled', $count)
			: _n('Monitoring disabled', 'Monitoring disabled', $count);
	}

	/**
	 * @param int $count
	 *
	 * @return string
	 */
	private function getErrorTitle(int $count): string {
		return $this->getTargetStatus() === HOST_STATUS_MONITORED
			? _n('Cannot enable monitoring', 'Cannot enable monitoring', $count)
			: _n('Cannot disable monitoring', 'Cannot disable monitoring', $count);
	}

	/**
	 * @return CControllerResponseRedirect
	 */
	private function makeListRedirect(): CControllerResponseRedirect {
		return new CControllerResponseRedirect(
			(new CUrl('zabbix.php'))->setArgument('action', 'certmonitor.list')
		);
	}
}
