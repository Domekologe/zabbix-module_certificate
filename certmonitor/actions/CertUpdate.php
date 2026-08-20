<?php declare(strict_types = 1);
/**
 * Certificate Monitor - updates an existing monitored website.
 *
 * The master item key is built from the user macros
 *     web.certificate.get[{$CERT.WEBSITE.HOSTNAME},{$CERT.WEBSITE.PORT},{$CERT.WEBSITE.IP}]
 * so changing the monitored hostname, port or address override is a pure macro update. Items are never
 * recreated here, which means all collected history survives an edit.
 *
 * What is updated besides the macros:
 *   - visible name, description, status and host group of the host;
 *   - the primary agent interface (the machine that actually performs the check);
 *   - the user editable host tags, while the two reserved tags are rewritten;
 *   - the trigger names and event names, which embed "<hostname>:<port>" and would otherwise go stale;
 *   - the status of the validation trigger, following the "ignore validation errors" checkbox;
 *   - the status of the four optional security triggers, following their checkboxes;
 *   - the description of the master item;
 *   - any dependent item, {$CERT.*} macro or security trigger that a previous version of this module
 *     did not create yet. Opening an old entry and pressing "Update" is therefore the supported way of
 *     upgrading it.
 *
 * The host technical name is deliberately NOT changed: it is referenced by every trigger expression of
 * the host, and renaming it would mean rewriting all of them. The name only reflects the state at
 * creation time; the authoritative target is the macro set.
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
use Modules\CertMonitor\Includes\CertConfig;
use Modules\CertMonitor\Includes\CertHelper;
use Modules\CertMonitor\Includes\CertProvision;

class CertUpdate extends CController {

	/**
	 * The host being updated, as read in checkPermissions().
	 *
	 * @var array|null
	 */
	private ?array $host = null;

	protected function checkInput(): bool {
		$fields = [
			'hostid' =>			'required|db hosts.hostid',
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
	 * Everything that ends up inside an item key parameter or a host technical name is validated here.
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
		if ($this->getUserType() < USER_TYPE_ZABBIX_ADMIN
				|| !$this->checkAccess(CRoleHelper::UI_CONFIGURATION_HOSTS)) {
			return false;
		}

		$hosts = API::Host()->get([
			'output' => ['hostid', 'host', 'name', 'status', 'description'],
			'hostids' => [$this->getInput('hostid')],
			'selectHostGroups' => ['groupid'],
			'selectMacros' => ['hostmacroid', 'macro', 'value'],
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
		$hostid = (string) $this->host['hostid'];

		$hostname = trim((string) $this->getInput('hostname'));
		$port = (int) $this->getInput('port');
		$address = trim((string) $this->getInput('address', ''));
		$groupid = (string) $this->getInput('groupid');
		$agent_address = trim((string) $this->getInput('agent_address'));
		$agent_port = (int) $this->getInput('agent_port');
		$description = (string) $this->getInput('description', '');
		$warn_days = (int) $this->getInput('warn_days');
		$avg_days = (int) $this->getInput('avg_days');
		$crit_days = (int) $this->getInput('crit_days');
		$ignore_validation = (int) $this->getInput('ignore_validation', 0) === 1;
		$host_status = (int) $this->getInput('host_status', HOST_STATUS_MONITORED);
		$tags = CertHelper::parseTagsText((string) $this->getInput('tags', ''));

		$visible_name = trim((string) $this->getInput('visible_name', ''));

		if ($visible_name === '') {
			$visible_name = CertHelper::makeVisibleName($hostname, $port);
		}

		// The security trigger settings. The org-wide defaults from CertConfig supply the severities and
		// the weak-algorithm patterns of any trigger that has to be created here for the first time; the
		// four checkboxes of the form decide whether each trigger is enabled.
		$params = CertProvision::paramsFromSettings(CertConfig::get());

		$params['warn_days'] = $warn_days;
		$params['avg_days'] = $avg_days;
		$params['crit_days'] = $crit_days;
		$params['ignore_validation'] = $ignore_validation;
		$params['sec_issuer_changed'] = (int) $this->getInput('sec_issuer_changed', 0) === 1;
		$params['sec_fingerprint_changed'] = (int) $this->getInput('sec_fingerprint_changed', 0) === 1;
		$params['sec_weak_key'] = (int) $this->getInput('sec_weak_key', 0) === 1;
		$params['sec_weak_signature'] = (int) $this->getInput('sec_weak_signature', 0) === 1;

		// The weak-algorithm patterns are per host, so an existing host keeps the value it already has and
		// only a host that has no such macro yet inherits the org-wide default.
		foreach ([
			CertHelper::MACRO_WEAK_KEY_ALGORITHMS => 'weak_key_algorithms',
			CertHelper::MACRO_WEAK_SIGNATURE_ALGORITHMS => 'weak_signature_algorithms'
		] as $macro => $key) {
			$stored = CertHelper::getMacroValue($this->host['macros'], $macro, '');

			if ($stored !== '' && CertHelper::isValidAlgorithmPattern($stored)) {
				$params[$key] = $stored;
			}
		}

		$old_hostname = CertHelper::getMacroValue($this->host['macros'], CertHelper::MACRO_HOSTNAME);
		$old_port = CertHelper::getMacroValue($this->host['macros'], CertHelper::MACRO_PORT,
			(string) CertHelper::DEFAULT_PORT
		);

		try {
			$groups = API::HostGroup()->get([
				'output' => ['groupid'],
				'groupids' => [$groupid],
				'editable' => true
			]);

			if (!$groups) {
				throw new \Exception(_('No permissions to the selected host group or it does not exist.'));
			}

			$this->updateHost($hostid, $visible_name, $description, $host_status, $groupid, $agent_address,
				$agent_port, $hostname, $port, $address, $params, $tags
			);

			$this->updateItems($hostid, $hostname, $port);

			$this->updateTriggers($hostid, (string) $this->host['host'], $hostname, $port,
				CertHelper::makeTarget($old_hostname, (int) $old_port), $params
			);
		}
		catch (\Exception $e) {
			// A partial failure is possible here: the host may already be updated while a later step
			// failed. Nothing is rolled back, because deleting the host would destroy collected history.
			// The message says explicitly what state the configuration is in.
			error($e->getMessage());
			error(_('The website was only partially updated. Open the form again and check every field, or inspect the host in Data collection -> Hosts.'));
			CMessageHelper::setErrorTitle(_('Cannot update website'));

			$this->setResponse($this->makeErrorResponse());

			return;
		}

		CMessageHelper::setSuccessTitle(_s('Website "%1$s" updated.', CertHelper::makeTarget($hostname, $port)));

		$this->setResponse(new CControllerResponseRedirect(
			(new CUrl('zabbix.php'))->setArgument('action', 'certmonitor.list')
		));
	}

	/**
	 * Update the host object: identity, group, interface, macros and tags.
	 *
	 * host.update replaces the whole "macros", "tags", "groups" and "interfaces" collections with what is
	 * passed, so every one of them is rebuilt completely here. Passing the existing "interfaceid" keeps
	 * the interface (and therefore the item -> interface reference) intact instead of replacing it.
	 * See https://www.zabbix.com/documentation/7.4/en/manual/api/reference/host/update
	 *
	 * The macro set is taken from CertProvision, so a host created by an older version of this module
	 * gains the {$CERT.SEC.*} macros simply by being saved once.
	 *
	 * @throws \Exception
	 */
	private function updateHost(string $hostid, string $visible_name, string $description, int $host_status,
			string $groupid, string $agent_address, int $agent_port, string $hostname, int $port, string $address,
			array $params, array $tags): void {
		$use_ip = filter_var($agent_address, FILTER_VALIDATE_IP) !== false;

		$interface = [
			'type' => INTERFACE_TYPE_AGENT,
			'main' => INTERFACE_PRIMARY,
			'useip' => $use_ip ? INTERFACE_USE_IP : INTERFACE_USE_DNS,
			'ip' => $use_ip ? $agent_address : '',
			'dns' => $use_ip ? '' : $agent_address,
			'port' => (string) $agent_port
		];

		foreach ($this->host['interfaces'] as $existing) {
			if ((int) $existing['type'] === INTERFACE_TYPE_AGENT && (int) $existing['main'] === INTERFACE_PRIMARY) {
				$interface['interfaceid'] = (string) $existing['interfaceid'];
				break;
			}
		}

		// Reuse the existing hostmacroid of every macro, so that the macro rows are updated instead of
		// being deleted and inserted again.
		$macro_values = CertProvision::getMacroDefinitions($hostname, $port, $address, $params);

		$existing_macroids = [];

		foreach ($this->host['macros'] as $macro) {
			$existing_macroids[(string) $macro['macro']] = (string) $macro['hostmacroid'];
		}

		$macros = [];

		foreach ($macro_values as $macro => $properties) {
			$entry = ['macro' => $macro] + $properties;

			if (array_key_exists($macro, $existing_macroids)) {
				$entry['hostmacroid'] = $existing_macroids[$macro];
			}

			$macros[] = $entry;
		}

		// Macros that do not belong to this module are kept untouched.
		foreach ($this->host['macros'] as $macro) {
			if (!array_key_exists((string) $macro['macro'], $macro_values)) {
				$macros[] = [
					'hostmacroid' => (string) $macro['hostmacroid'],
					'macro' => (string) $macro['macro'],
					'value' => (string) $macro['value']
				];
			}
		}

		$result = API::Host()->update([[
			'hostid' => $hostid,
			'name' => $visible_name,
			'status' => $host_status,
			'description' => $description,
			'groups' => [['groupid' => $groupid]],
			'interfaces' => [$interface],
			'macros' => $macros,
			'tags' => array_merge([
				['tag' => CertHelper::HOST_TAG, 'value' => CertHelper::HOST_TAG_VALUE],
				['tag' => 'website', 'value' => $hostname]
			], $tags)
		]]);

		if (!$result) {
			throw new \Exception(_('Cannot update the host.'));
		}
	}

	/**
	 * Refresh the master item description and create any dependent item that is missing.
	 *
	 * Hosts created by an older version of this module have fewer dependent items and a master item with
	 * history disabled; both are corrected here, so that simply opening and saving an entry repairs it.
	 *
	 * @throws \Exception
	 */
	private function updateItems(string $hostid, string $hostname, int $port): void {
		$items = API::Item()->get([
			'output' => ['itemid', 'key_', 'type', 'master_itemid'],
			'hostids' => [$hostid],
			'preservekeys' => true
		]);

		$master_itemid = null;
		$existing_keys = [];

		foreach ($items as $itemid => $item) {
			$existing_keys[(string) $item['key_']] = true;

			if ((int) $item['type'] !== ITEM_TYPE_DEPENDENT) {
				$master_itemid = (string) $itemid;
			}
		}

		if ($master_itemid === null) {
			throw new \Exception(_('The master item of this host is missing. Recreate the entry.'));
		}

		$updated = API::Item()->update([[
			'itemid' => $master_itemid,
			'description' => CertProvision::makeMasterItemDescription($hostname, $port),
			'history' => CertHelper::MASTER_HISTORY
		]]);

		if (!$updated) {
			throw new \Exception(_('Cannot update the master item.'));
		}

		$missing = [];

		foreach (CertHelper::getDependentItemDefinitions() as $definition) {
			if (array_key_exists($definition['key'], $existing_keys)) {
				continue;
			}

			$missing[] = CertProvision::buildDependentItem($definition, $hostid, $master_itemid);
		}

		if ($missing && !API::Item()->create($missing)) {
			throw new \Exception(_('Cannot create the missing dependent items.'));
		}
	}

	/**
	 * Keep the trigger names, event names and trigger statuses in sync with the form, and create the
	 * optional security triggers on hosts that do not have them yet.
	 *
	 * The triggers created by this module all carry the monitored target in square brackets, e.g.
	 * "Certificate [www.example.com:443]: expired". Only that bracketed part is rewritten, so a trigger
	 * whose name a user has customised keeps the customisation.
	 *
	 * A trigger created from version 1.2.0 on carries the tag "certmonitor_trigger" whose value says
	 * which of the module's triggers it is. That tag is how a security trigger is recognised here; the
	 * validation trigger is additionally recognised by the item it reads, because hosts created by an
	 * older version have no such tag.
	 *
	 * @param string $hostid
	 * @param string $host_name   Technical host name; needed to build the expressions of new triggers.
	 * @param string $hostname    Monitored DNS name.
	 * @param int    $port        Monitored port.
	 * @param string $old_target  "<hostname>:<port>" as it was before this save.
	 * @param array  $params      Provisioning parameters; see CertProvision::withDefaults().
	 *
	 * @throws \Exception
	 */
	private function updateTriggers(string $hostid, string $host_name, string $hostname, int $port,
			string $old_target, array $params): void {
		$new_target = CertHelper::makeTarget($hostname, $port);

		$triggers = API::Trigger()->get([
			'output' => ['triggerid', 'description', 'event_name', 'status'],
			'hostids' => [$hostid],
			'selectItems' => ['key_'],
			'selectTags' => ['tag', 'value'],
			'editable' => true
		]);

		$definitions = CertProvision::getTriggerDefinitions($host_name, $hostname, $port, $params);

		$updates = [];
		$rename = $old_target !== '' && $old_target !== $new_target;

		// Which of the module's triggers already exist on this host.
		$present = [];

		foreach ($triggers as $trigger) {
			$update = ['triggerid' => (string) $trigger['triggerid']];
			$changed = false;

			if ($rename) {
				$description = str_replace('['.$old_target.']', '['.$new_target.']',
					(string) $trigger['description']
				);
				$event_name = str_replace('['.$old_target.']', '['.$new_target.']',
					(string) $trigger['event_name']
				);

				if ($description !== (string) $trigger['description']) {
					$update['description'] = $description;
					$changed = true;
				}

				if ($event_name !== (string) $trigger['event_name']) {
					$update['event_name'] = $event_name;
					$changed = true;
				}
			}

			$trigger_id = '';

			foreach ($trigger['tags'] as $tag) {
				if ((string) $tag['tag'] === CertProvision::TRIGGER_TAG_ID) {
					$trigger_id = (string) $tag['value'];
					break;
				}
			}

			// The validation trigger of a host created by an older version has no identifying tag, but it
			// is the only trigger that reads the validation item.
			if ($trigger_id === '') {
				foreach ($trigger['items'] as $item) {
					if ((string) $item['key_'] === CertHelper::KEY_VALIDATION) {
						$trigger_id = CertProvision::TRIGGER_VALIDATION;
						break;
					}
				}
			}

			if ($trigger_id !== '') {
				$present[$trigger_id] = true;
			}

			// Only the triggers whose state the form owns are switched; the expiry triggers are left
			// alone, so a user may disable one of them by hand without this page turning it back on.
			$owned = array_merge([CertProvision::TRIGGER_VALIDATION], CertProvision::SECURITY_TRIGGERS);

			if (in_array($trigger_id, $owned, true) && array_key_exists($trigger_id, $definitions)) {
				$status = (int) $definitions[$trigger_id]['status'];

				if ((int) $trigger['status'] !== $status) {
					$update['status'] = $status;
					$changed = true;
				}
			}

			if ($changed) {
				$updates[] = $update;
			}
		}

		if ($updates && !API::Trigger()->update($updates)) {
			throw new \Exception(_('Cannot update the triggers of this host.'));
		}

		// Backfill the security triggers on hosts that were created before they existed.
		$missing = [];

		foreach (CertProvision::SECURITY_TRIGGERS as $trigger_id) {
			if (!array_key_exists($trigger_id, $present)) {
				$missing[] = $definitions[$trigger_id];
			}
		}

		if ($missing && !API::Trigger()->create($missing)) {
			throw new \Exception(_('Cannot create the missing security triggers of this host.'));
		}
	}

	/**
	 * Build a redirect back to the edit form, keeping the submitted values.
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
			CMessageHelper::setErrorTitle(_('Cannot update website'));
		}

		return $response;
	}
}
