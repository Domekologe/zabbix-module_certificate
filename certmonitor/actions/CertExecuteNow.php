<?php declare(strict_types = 1);
/**
 * Certificate Monitor - "Check now" for the selected websites.
 *
 * Queues an immediate poll of the master item of every selected host, so that a fresh certificate value
 * can be pulled without waiting for the next update interval.
 *
 * Verified against the Zabbix 7.4 sources (branch release/7.4):
 *
 * - ui/app/controllers/CControllerItemExecuteNow.php builds
 *       ['type' => ZBX_TM_TASK_CHECK_NOW, 'request' => ['itemid' => <itemid>]]
 *   and passes it to API::Task()->create(). The same shape is used here.
 * - checkNowAllowedTypes() in ui/include/items.inc.php lists ITEM_TYPE_ZABBIX among the allowed types,
 *   so the master item of this module qualifies. Dependent items are allowed too but are resolved to
 *   their master item first - which is why this action targets the master item directly.
 * - The built-in controller requires USER_TYPE_ZABBIX_USER and, unless the role grants
 *   CRoleHelper::ACTIONS_INVOKE_EXECUTE_NOW, write permission on the item. Both rules are applied here.
 * - The item and its host must both be active, otherwise the server rejects the task.
 *
 * @see https://www.zabbix.com/documentation/7.4/en/manual/api/reference/task/create
 * @see https://www.zabbix.com/documentation/7.4/en/manual/config/items/check_now
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

class CertExecuteNow extends CController {

	protected function checkInput(): bool {
		$fields = [
			'hostids' => 'required|array_id'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			CMessageHelper::setErrorTitle(_('Cannot execute operation'));

			$this->setResponse($this->makeListRedirect());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$hostids = $this->getInput('hostids');
		$response = $this->makeListRedirect();

		$hosts = API::Host()->get([
			'output' => ['hostid', 'name', 'status'],
			'hostids' => $hostids,
			'tags' => [[
				'tag' => CertHelper::HOST_TAG,
				'value' => CertHelper::HOST_TAG_VALUE,
				'operator' => TAG_OPERATOR_EQUAL
			]],
			'preservekeys' => true
		]);

		if (!$hosts) {
			error(_('No permissions to referred object or it does not exist.'));
			CMessageHelper::setErrorTitle(_('Cannot execute operation'));

			$this->setResponse($response);

			return;
		}

		// The master item is the only non-dependent item of these hosts.
		$items = API::Item()->get([
			'output' => ['itemid', 'hostid', 'type', 'status'],
			'hostids' => array_keys($hosts),
			'filter' => ['type' => ITEM_TYPE_ZABBIX],
			// Without the "Execute now" role permission, write access to the item is required.
			'editable' => !$this->checkAccess(CRoleHelper::ACTIONS_INVOKE_EXECUTE_NOW)
		]);

		$tasks = [];
		$skipped = 0;

		foreach ($items as $item) {
			$host = $hosts[$item['hostid']];

			// The Zabbix server refuses a task for an item or a host that is not being monitored.
			if ((int) $item['status'] !== ITEM_STATUS_ACTIVE
					|| (int) $host['status'] !== HOST_STATUS_MONITORED) {
				$skipped++;

				continue;
			}

			$tasks[] = [
				'type' => ZBX_TM_TASK_CHECK_NOW,
				'request' => ['itemid' => (string) $item['itemid']]
			];
		}

		if (!$tasks) {
			error($skipped > 0
				? _('Cannot send request: the selected websites are not being monitored.')
				: _('No permissions to referred object or it does not exist.')
			);
			CMessageHelper::setErrorTitle(_('Cannot execute operation'));

			$this->setResponse($response);

			return;
		}

		if (API::Task()->create($tasks)) {
			$response->setFormData(['uncheck' => '1']);

			if ($skipped > 0) {
				CMessageHelper::setSuccessTitle(
					_('Request sent successfully. Some websites were skipped because they are not monitored.')
				);
			}
			else {
				CMessageHelper::setSuccessTitle(_('Request sent successfully'));
			}
		}
		else {
			CMessageHelper::setErrorTitle(_('Cannot execute operation'));
		}

		$this->setResponse($response);
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
