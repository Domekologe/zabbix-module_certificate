<?php declare(strict_types = 1);
/**
 * Certificate Monitor - deletes monitored websites (hosts) created by this module.
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

class CertDelete extends CController {

	protected function checkInput(): bool {
		$fields = [
			'hostids' => 'required|array_id'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			CMessageHelper::setErrorTitle(_('Cannot delete website'));

			$this->setResponse(new CControllerResponseRedirect(
				(new CUrl('zabbix.php'))->setArgument('action', 'certmonitor.list')
			));
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN
			&& $this->checkAccess(CRoleHelper::UI_CONFIGURATION_HOSTS);
	}

	protected function doAction(): void {
		$hostids = $this->getInput('hostids');

		// Only hosts that are writable AND tagged as managed by this module may be deleted here.
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

		$response = new CControllerResponseRedirect(
			(new CUrl('zabbix.php'))->setArgument('action', 'certmonitor.list')
		);

		if ($count != count($hostids)) {
			error(_('No permissions to referred object or it does not exist.'));
			CMessageHelper::setErrorTitle(_n('Cannot delete website', 'Cannot delete websites', count($hostids)));

			$this->setResponse($response);

			return;
		}

		$result = API::Host()->delete($allowed_hostids);

		if ($result) {
			$response->setFormData(['uncheck' => '1']);
			CMessageHelper::setSuccessTitle(_n('Website deleted', 'Websites deleted', $count));
		}
		else {
			CMessageHelper::setErrorTitle(_n('Cannot delete website', 'Cannot delete websites', $count));
		}

		$this->setResponse($response);
	}
}
