<?php declare(strict_types = 1);
/**
 * Certificate Monitor - shared provisioning of one monitored website.
 *
 * Everything that is needed to turn "a hostname and a port" into a working Zabbix configuration lives
 * here: the host with its agent interface, macros and tags, the master item, the dependent items and
 * the triggers. Both the single "Add website" form (CertCreate) and the bulk import (CertImportCreate)
 * call CertProvision::create(), so the two paths cannot drift apart.
 *
 * The created host uses the Zabbix agent 2 item key "web.certificate.get[hostname,<port>,<address>]"
 * as a master item and derives all other metrics from it via JSONPath preprocessing.
 *
 * See https://www.zabbix.com/documentation/7.4/en/manual/config/items/itemtypes/zabbix_agent/zabbix_agent2
 * and https://www.zabbix.com/documentation/7.4/en/manual/api/reference/host/create
 *
 * Author: Domekologe <support@domekologe.eu>
 */

namespace Modules\CertMonitor\Includes;

use API;

class CertProvision {

	/**
	 * Trigger tag that identifies which of the module's triggers a trigger is.
	 *
	 * Written on every trigger created from version 1.2.0 on. It lets the update path recognise the
	 * optional security triggers without having to parse their expressions, and it survives a user
	 * renaming the trigger.
	 */
	public const TRIGGER_TAG_ID = 'certmonitor_trigger';

	/**
	 * Values of the TRIGGER_TAG_ID tag.
	 */
	public const TRIGGER_VALIDATION = 'validation';
	public const TRIGGER_EXPIRED = 'expired';
	public const TRIGGER_EXPIRY_CRIT = 'expiry_crit';
	public const TRIGGER_EXPIRY_AVG = 'expiry_avg';
	public const TRIGGER_EXPIRY_WARN = 'expiry_warn';
	public const TRIGGER_ISSUER_CHANGED = 'issuer_changed';
	public const TRIGGER_FINGERPRINT_CHANGED = 'fingerprint_changed';
	public const TRIGGER_WEAK_KEY = 'weak_key';
	public const TRIGGER_WEAK_SIGNATURE = 'weak_signature';

	/**
	 * The four optional security triggers, in the order in which they are shown in the form.
	 */
	public const SECURITY_TRIGGERS = [
		self::TRIGGER_ISSUER_CHANGED,
		self::TRIGGER_FINGERPRINT_CHANGED,
		self::TRIGGER_WEAK_KEY,
		self::TRIGGER_WEAK_SIGNATURE
	];

	/**
	 * Fill a parameter array with every key that create() expects, so callers only have to pass what
	 * differs from the defaults.
	 *
	 * @param array $params
	 *
	 * @return array
	 */
	public static function withDefaults(array $params): array {
		return $params + [
			'hostname' => '',
			'port' => CertHelper::DEFAULT_PORT,
			'address' => '',
			'groupid' => '',
			'agent_address' => CertHelper::DEFAULT_AGENT_ADDRESS,
			'agent_port' => CertHelper::DEFAULT_AGENT_PORT,
			'visible_name' => '',
			'description' => '',
			'tags' => [],
			'warn_days' => CertHelper::DEFAULT_WARN_DAYS,
			'avg_days' => CertHelper::DEFAULT_AVG_DAYS,
			'crit_days' => CertHelper::DEFAULT_CRIT_DAYS,
			'ignore_validation' => false,
			'host_status' => HOST_STATUS_MONITORED,
			'delay' => CertHelper::MASTER_DELAY,
			'host_prefix' => CertHelper::HOST_PREFIX,
			'sec_issuer_changed' => CertHelper::DEFAULT_SEC_ISSUER_CHANGED,
			'sec_fingerprint_changed' => CertHelper::DEFAULT_SEC_FINGERPRINT_CHANGED,
			'sec_weak_key' => CertHelper::DEFAULT_SEC_WEAK_KEY,
			'sec_weak_signature' => CertHelper::DEFAULT_SEC_WEAK_SIGNATURE,
			'weak_key_algorithms' => CertHelper::DEFAULT_WEAK_KEY_ALGORITHMS,
			'weak_signature_algorithms' => CertHelper::DEFAULT_WEAK_SIGNATURE_ALGORITHMS,
			'issuer_severity' => CertHelper::DEFAULT_ISSUER_SEVERITY,
			'fingerprint_severity' => CertHelper::DEFAULT_FINGERPRINT_SEVERITY
		];
	}

	/**
	 * Build the parameter defaults that come from the module settings.
	 *
	 * @param array $settings  As returned by CertConfig::get().
	 *
	 * @return array
	 */
	public static function paramsFromSettings(array $settings): array {
		return [
			'port' => (int) $settings[CertConfig::DEFAULT_PORT],
			'groupid' => $settings[CertConfig::DEFAULT_GROUPID],
			'agent_address' => $settings[CertConfig::DEFAULT_AGENT_ADDRESS],
			'agent_port' => (int) $settings[CertConfig::DEFAULT_AGENT_PORT],
			'warn_days' => (int) $settings[CertConfig::DEFAULT_WARN_DAYS],
			'avg_days' => (int) $settings[CertConfig::DEFAULT_AVG_DAYS],
			'crit_days' => (int) $settings[CertConfig::DEFAULT_CRIT_DAYS],
			'ignore_validation' => $settings[CertConfig::DEFAULT_IGNORE_VALIDATION] === '1',
			'delay' => $settings[CertConfig::DEFAULT_DELAY],
			'host_prefix' => $settings[CertConfig::HOST_PREFIX],
			'sec_issuer_changed' => $settings[CertConfig::DEFAULT_SEC_ISSUER_CHANGED] === '1',
			'sec_fingerprint_changed' => $settings[CertConfig::DEFAULT_SEC_FINGERPRINT_CHANGED] === '1',
			'sec_weak_key' => $settings[CertConfig::DEFAULT_SEC_WEAK_KEY] === '1',
			'sec_weak_signature' => $settings[CertConfig::DEFAULT_SEC_WEAK_SIGNATURE] === '1',
			'weak_key_algorithms' => $settings[CertConfig::DEFAULT_WEAK_KEY_ALGORITHMS],
			'weak_signature_algorithms' => $settings[CertConfig::DEFAULT_WEAK_SIGNATURE_ALGORITHMS],
			'issuer_severity' => (int) $settings[CertConfig::DEFAULT_ISSUER_SEVERITY],
			'fingerprint_severity' => (int) $settings[CertConfig::DEFAULT_FINGERPRINT_SEVERITY]
		];
	}

	/**
	 * Create the complete configuration of one monitored website.
	 *
	 * All input is expected to be validated already; the two values that end up inside an item key or a
	 * host technical name are re-checked here regardless, because this method is reachable from the bulk
	 * import as well.
	 *
	 * On any failure the partially created host is deleted again, so the caller never has to clean up.
	 *
	 * @param array $params  See withDefaults() for the accepted keys.
	 *
	 * @return string  Host ID of the created host.
	 *
	 * @throws \Exception
	 */
	public static function create(array $params): string {
		$p = self::withDefaults($params);

		$hostname = trim((string) $p['hostname']);
		$port = (int) $p['port'];
		$address = trim((string) $p['address']);
		$agent_address = trim((string) $p['agent_address']);

		if (!CertHelper::isValidHostname($hostname)) {
			throw new \Exception(_s('"%1$s" is not a valid DNS name or IP address.', $hostname));
		}

		if ($address !== '' && !CertHelper::isValidHostname($address)) {
			throw new \Exception(_s('"%1$s" is not a valid DNS name or IP address.', $address));
		}

		if (!CertHelper::isValidHostname($agent_address)) {
			throw new \Exception(_s('"%1$s" is not a valid DNS name or IP address.', $agent_address));
		}

		if ($port < 1 || $port > 65535) {
			throw new \Exception(_s('"%1$s" is not a valid port number.', (string) $p['port']));
		}

		$host_name = CertHelper::makeHostName($hostname, $port, (string) $p['host_prefix']);

		$visible_name = trim((string) $p['visible_name']);

		if ($visible_name === '') {
			$visible_name = CertHelper::makeVisibleName($hostname, $port);
		}

		$groups = API::HostGroup()->get([
			'output' => ['groupid'],
			'groupids' => [(string) $p['groupid']],
			'editable' => true
		]);

		if (!$groups) {
			throw new \Exception(_('No permissions to the selected host group or it does not exist.'));
		}

		$existing = API::Host()->get([
			'output' => ['hostid'],
			'filter' => ['host' => $host_name],
			'limit' => 1
		]);

		if ($existing) {
			throw new \Exception(
				_s('Host "%1$s" already exists. This website is monitored already.', $host_name)
			);
		}

		$hostid = null;

		try {
			$hostid = self::createHost($host_name, $visible_name, $hostname, $port, $address, $agent_address,
				$p
			);

			// host.create returns the host IDs only, so the interface ID has to be looked up afterwards.
			$interfaceid = self::getAgentInterfaceId($hostid);

			$master_itemid = self::createMasterItem($hostid, $interfaceid, $hostname, $port,
				(string) $p['delay']
			);

			self::createDependentItems($hostid, $master_itemid);
			self::createTriggers($host_name, $hostname, $port, $p);
		}
		catch (\Exception $e) {
			// Roll back the partially created configuration.
			if ($hostid !== null) {
				API::Host()->delete([$hostid]);
			}

			throw $e;
		}

		return $hostid;
	}

	/**
	 * Create the host that represents one monitored website.
	 *
	 * The item type is "Zabbix agent" (passive), therefore an agent interface is mandatory. The interface
	 * must point to the machine that runs Zabbix agent 2 and performs the outgoing TLS connection - not to
	 * the monitored website itself.
	 *
	 * @return string  Host ID.
	 *
	 * @throws \Exception
	 */
	private static function createHost(string $host_name, string $visible_name, string $hostname, int $port,
			string $address, string $agent_address, array $p): string {
		$use_ip = filter_var($agent_address, FILTER_VALIDATE_IP) !== false;

		$result = API::Host()->create([
			'host' => $host_name,
			'name' => $visible_name,
			'status' => (int) $p['host_status'],
			'description' => (string) $p['description'],
			'groups' => [['groupid' => (string) $p['groupid']]],
			'interfaces' => [[
				'type' => INTERFACE_TYPE_AGENT,
				'main' => INTERFACE_PRIMARY,
				'useip' => $use_ip ? INTERFACE_USE_IP : INTERFACE_USE_DNS,
				'ip' => $use_ip ? $agent_address : '',
				'dns' => $use_ip ? '' : $agent_address,
				'port' => (string) (int) $p['agent_port']
			]],
			'macros' => self::buildMacroValues($hostname, $port, $address, $p),
			// The two reserved tags are always written; CertHelper::parseTagsText() already removed any
			// user supplied tag that would collide with them.
			'tags' => array_merge([
				['tag' => CertHelper::HOST_TAG, 'value' => CertHelper::HOST_TAG_VALUE],
				['tag' => 'website', 'value' => $hostname]
			], (array) $p['tags'])
		]);

		if (!$result || !array_key_exists('hostids', $result)) {
			throw new \Exception(_('Cannot create host.'));
		}

		return (string) $result['hostids'][0];
	}

	/**
	 * The complete {$CERT.*} macro set of a monitored host, as a host.create "macros" array.
	 *
	 * Shared with CertUpdate, which rewrites exactly the same macros on every save and therefore
	 * backfills the ones that an older version of this module did not write yet.
	 *
	 * @return array  Array of ['macro' => ..., 'value' => ..., 'description' => ...].
	 */
	public static function buildMacroValues(string $hostname, int $port, string $address, array $params): array {
		$p = self::withDefaults($params);

		$macros = [];

		foreach (self::getMacroDefinitions($hostname, $port, $address, $p) as $macro => $properties) {
			$macros[] = ['macro' => $macro] + $properties;
		}

		return $macros;
	}

	/**
	 * Macro name => ['value' => ..., 'description' => ...] for every macro this module manages.
	 *
	 * @return array
	 */
	public static function getMacroDefinitions(string $hostname, int $port, string $address,
			array $params): array {
		$p = self::withDefaults($params);

		return [
			CertHelper::MACRO_HOSTNAME => [
				'value' => $hostname,
				'description' => _('DNS name of the monitored website.')
			],
			CertHelper::MACRO_PORT => [
				'value' => (string) $port,
				'description' => _('TLS/SSL port number of the monitored website.')
			],
			CertHelper::MACRO_IP => [
				'value' => $address,
				'description' => _('Optional address used for the connection instead of the DNS name.')
			],
			CertHelper::MACRO_EXPIRY_WARN => [
				'value' => (string) (int) $p['warn_days'],
				'description' => _('Days before expiry that raise a WARNING problem.')
			],
			CertHelper::MACRO_EXPIRY_AVG => [
				'value' => (string) (int) $p['avg_days'],
				'description' => _('Days before expiry that raise an AVERAGE problem.')
			],
			CertHelper::MACRO_EXPIRY_CRIT => [
				'value' => (string) (int) $p['crit_days'],
				'description' => _('Days before expiry that raise a HIGH problem.')
			],
			CertHelper::MACRO_IGNORE_VALIDATION => [
				'value' => $p['ignore_validation'] ? '1' : '0',
				'description' => _('1 - certificate validation errors are ignored (the validation trigger is disabled). 0 - validation errors raise a problem.')
			],
			CertHelper::MACRO_SEC_ISSUER_CHANGED => [
				'value' => $p['sec_issuer_changed'] ? '1' : '0',
				'description' => _('1 - raise a problem when the certificate issuer changes. 0 - the trigger exists but is disabled.')
			],
			CertHelper::MACRO_SEC_FINGERPRINT_CHANGED => [
				'value' => $p['sec_fingerprint_changed'] ? '1' : '0',
				'description' => _('1 - raise a problem when the SHA-256 fingerprint changes, i.e. when the certificate was replaced. A normal renewal triggers this as well. 0 - the trigger exists but is disabled.')
			],
			CertHelper::MACRO_SEC_WEAK_KEY => [
				'value' => $p['sec_weak_key'] ? '1' : '0',
				'description' => _('1 - raise a problem when the public key algorithm matches {$CERT.KEY.ALGO.WEAK}. 0 - the trigger exists but is disabled.')
			],
			CertHelper::MACRO_SEC_WEAK_SIGNATURE => [
				'value' => $p['sec_weak_signature'] ? '1' : '0',
				'description' => _('1 - raise a problem when the signature algorithm matches {$CERT.SIG.ALGO.WEAK}. 0 - the trigger exists but is disabled.')
			],
			CertHelper::MACRO_WEAK_KEY_ALGORITHMS => [
				'value' => (string) $p['weak_key_algorithms'],
				'description' => _('Case-insensitive regular expression of public key algorithms considered weak. The agent reports RSA, DSA, ECDSA, Ed25519 or Unknown - it does NOT report a key length.')
			],
			CertHelper::MACRO_WEAK_SIGNATURE_ALGORITHMS => [
				'value' => (string) $p['weak_signature_algorithms'],
				'description' => _('Case-insensitive regular expression of signature algorithms considered weak, matched against values such as "SHA1-RSA" or "SHA256-RSA".')
			]
		];
	}

	/**
	 * Return the ID of the agent interface of a freshly created host.
	 *
	 * host.create returns an object with the "hostids" property only - no interface IDs - therefore the
	 * interface that was created together with the host has to be read back with hostinterface.get.
	 * See https://www.zabbix.com/documentation/7.4/en/manual/api/reference/host/create
	 * and https://www.zabbix.com/documentation/7.4/en/manual/api/reference/hostinterface/get
	 *
	 * @return string  Interface ID.
	 *
	 * @throws \Exception
	 */
	private static function getAgentInterfaceId(string $hostid): string {
		$interfaces = API::HostInterface()->get([
			'output' => ['interfaceid'],
			'hostids' => [$hostid],
			'filter' => ['type' => INTERFACE_TYPE_AGENT, 'main' => INTERFACE_PRIMARY],
			'limit' => 1
		]);

		if (!$interfaces) {
			throw new \Exception(_('Cannot read the agent interface of the created host.'));
		}

		return (string) $interfaces[0]['interfaceid'];
	}

	/**
	 * Create the master item that collects the raw certificate JSON.
	 *
	 * The "interfaceid" property is mandatory for items of type "Zabbix agent" that belong to a host:
	 * "required if item belongs to host and type is set to "Zabbix agent", "IPMI agent", "JMX agent",
	 * "SNMP trap", or "SNMP agent"".
	 * See https://www.zabbix.com/documentation/7.4/en/manual/api/reference/item/object
	 * Without it, item.create fails with: Invalid parameter "/1": the parameter "interfaceid" is missing.
	 *
	 * @return string  Item ID.
	 *
	 * @throws \Exception
	 */
	private static function createMasterItem(string $hostid, string $interfaceid, string $hostname, int $port,
			string $delay): string {
		$result = API::Item()->create([[
			'hostid' => $hostid,
			'interfaceid' => $interfaceid,
			'name' => _('Certificate: Get'),
			'type' => ITEM_TYPE_ZABBIX,
			'key_' => CertHelper::KEY_MASTER,
			'value_type' => ITEM_VALUE_TYPE_TEXT,
			'delay' => $delay !== '' ? $delay : CertHelper::MASTER_DELAY,
			// The raw JSON has to be kept, otherwise the "Certificate" section of the detail page has no
			// value to read - see CertHelper::MASTER_HISTORY.
			'history' => CertHelper::MASTER_HISTORY,
			'status' => ITEM_STATUS_ACTIVE,
			'description' => self::makeMasterItemDescription($hostname, $port),
			'preprocessing' => [[
				// Discard unchanged with heartbeat, so that dependent items keep updating once per 6 hours.
				'type' => ZBX_PREPROC_THROTTLE_TIMED_VALUE,
				'params' => '6h',
				'error_handler' => ZBX_PREPROC_FAIL_DEFAULT,
				'error_handler_params' => ''
			]],
			'tags' => [['tag' => 'component', 'value' => 'raw']]
		]]);

		if (!$result || !array_key_exists('itemids', $result)) {
			throw new \Exception(_('Cannot create the master item.'));
		}

		return (string) $result['itemids'][0];
	}

	/**
	 * Description of the master item. Shared with CertUpdate, which refreshes it on every save.
	 *
	 * @return string
	 */
	public static function makeMasterItemDescription(string $hostname, int $port): string {
		return _s('Returns a JSON object with the attributes of the certificate of %1$s:%2$s.', $hostname,
			(string) $port
		);
	}

	/**
	 * Build one item.create object from a dependent item definition.
	 *
	 * Shared with CertUpdate, which uses it to backfill items that an older version did not create yet.
	 *
	 * Dependent items deliberately get no "interfaceid" and no "delay": "interfaceid" is neither required
	 * nor supported for the "Dependent item" type, "master_itemid" is required for it, and "units"/"trends"
	 * are only supported for numeric value types - which is why they are set for the timestamp items only.
	 * See https://www.zabbix.com/documentation/7.4/en/manual/api/reference/item/object
	 *
	 * @return array
	 */
	public static function buildDependentItem(array $definition, string $hostid, string $master_itemid): array {
		$item = [
			'hostid' => $hostid,
			'name' => $definition['name'],
			'type' => ITEM_TYPE_DEPENDENT,
			'key_' => $definition['key'],
			'value_type' => $definition['value_type'],
			'master_itemid' => $master_itemid,
			'status' => ITEM_STATUS_ACTIVE,
			'description' => $definition['description'],
			'preprocessing' => [[
				'type' => ZBX_PREPROC_JSONPATH,
				'params' => $definition['jsonpath'],
				'error_handler' => ZBX_PREPROC_FAIL_DEFAULT,
				'error_handler_params' => ''
			]],
			'tags' => [['tag' => 'component', 'value' => 'cert']]
		];

		foreach (['units', 'history', 'trends'] as $optional) {
			if (array_key_exists($optional, $definition)) {
				$item[$optional] = $definition[$optional];
			}
		}

		return $item;
	}

	/**
	 * Create all dependent items that extract single fields from the master item JSON.
	 *
	 * @throws \Exception
	 */
	private static function createDependentItems(string $hostid, string $master_itemid): void {
		$items = [];

		foreach (CertHelper::getDependentItemDefinitions() as $definition) {
			$items[] = self::buildDependentItem($definition, $hostid, $master_itemid);
		}

		$result = API::Item()->create($items);

		if (!$result || !array_key_exists('itemids', $result)) {
			throw new \Exception(_('Cannot create dependent items.'));
		}
	}

	/**
	 * Create the expiry, validation and security triggers.
	 *
	 * Trigger dependencies are used on the expiry chain so that only the most severe problem is shown at
	 * any time. The security triggers are independent of that chain.
	 *
	 * "description" and "expression" are the only required properties; "priority", "status" and
	 * "event_name" are optional and are set explicitly here.
	 * See https://www.zabbix.com/documentation/7.4/en/manual/api/reference/trigger/object
	 *
	 * A trigger that the user switched off is created DISABLED instead of being left out, so that it
	 * stays visible on the host and can be switched back on by hand at any time.
	 *
	 * @throws \Exception
	 */
	private static function createTriggers(string $host_name, string $hostname, int $port, array $p): void {
		$definitions = self::getTriggerDefinitions($host_name, $hostname, $port, $p);

		$order = [
			self::TRIGGER_VALIDATION,
			self::TRIGGER_EXPIRED,
			self::TRIGGER_EXPIRY_CRIT,
			self::TRIGGER_EXPIRY_AVG,
			self::TRIGGER_EXPIRY_WARN
		];

		$triggers = [];

		foreach ($order as $id) {
			$triggers[] = $definitions[$id];
		}

		foreach (self::SECURITY_TRIGGERS as $id) {
			$triggers[] = $definitions[$id];
		}

		$result = API::Trigger()->create($triggers);

		if (!$result || !array_key_exists('triggerids', $result)) {
			throw new \Exception(_('Cannot create triggers.'));
		}

		$triggerids = $result['triggerids'];

		// Chain: warning -> average -> high -> expired -> validation failed. Only the first five entries
		// of $triggerids belong to that chain; the security triggers that follow stay independent.
		$dependencies = [];

		for ($i = 1; $i < count($order); $i++) {
			$dependencies[] = [
				'triggerid' => $triggerids[$i],
				'dependencies' => [['triggerid' => $triggerids[$i - 1]]]
			];
		}

		if (!API::Trigger()->update($dependencies)) {
			throw new \Exception(_('Cannot create trigger dependencies.'));
		}
	}

	/**
	 * All trigger objects this module creates, keyed by their TRIGGER_TAG_ID value.
	 *
	 * Kept in one place so that "Add website", the bulk import and the "backfill missing triggers" step
	 * of "Update website" stay in sync.
	 *
	 * @return array
	 */
	public static function getTriggerDefinitions(string $host_name, string $hostname, int $port,
			array $params): array {
		$p = self::withDefaults($params);

		$target = CertHelper::makeTarget($hostname, $port);

		$not_after = '/'.$host_name.'/'.CertHelper::KEY_NOT_AFTER;
		$validation = '/'.$host_name.'/'.CertHelper::KEY_VALIDATION;
		$issuer = '/'.$host_name.'/'.CertHelper::KEY_ISSUER;
		$fingerprint = '/'.$host_name.'/'.CertHelper::KEY_FINGERPRINT;
		$key_algorithm = '/'.$host_name.'/'.CertHelper::KEY_PUBLIC_KEY_ALGORITHM;
		$signature_algorithm = '/'.$host_name.'/'.CertHelper::KEY_SIGNATURE_ALGORITHM;

		$notice_tags = [
			['tag' => 'scope', 'value' => 'notice'],
			['tag' => CertHelper::HOST_TAG, 'value' => $hostname]
		];
		$security_tags = [
			['tag' => 'scope', 'value' => 'security'],
			['tag' => CertHelper::HOST_TAG, 'value' => $hostname]
		];

		$issuer_severity = (int) $p['issuer_severity'];
		$fingerprint_severity = (int) $p['fingerprint_severity'];

		if (!CertHelper::isValidSeverity($issuer_severity)) {
			$issuer_severity = CertHelper::DEFAULT_ISSUER_SEVERITY;
		}

		if (!CertHelper::isValidSeverity($fingerprint_severity)) {
			$fingerprint_severity = CertHelper::DEFAULT_FINGERPRINT_SEVERITY;
		}

		$definitions = [
			self::TRIGGER_VALIDATION => [
				'description' => _s('Certificate [%1$s]: validation failed', $target),
				'expression' => 'find('.$validation.',,"like","invalid")=1',
				'priority' => TRIGGER_SEVERITY_AVERAGE,
				'status' => $p['ignore_validation'] ? TRIGGER_STATUS_DISABLED : TRIGGER_STATUS_ENABLED,
				'comments' => _('The certificate is invalid: expired, issued for another domain or signed by an unknown authority.'),
				'tags' => $security_tags
			],
			self::TRIGGER_EXPIRED => [
				'description' => _s('Certificate [%1$s]: expired', $target),
				'expression' => '(last('.$not_after.') - now()) < 0',
				'priority' => TRIGGER_SEVERITY_DISASTER,
				'status' => TRIGGER_STATUS_ENABLED,
				'comments' => _('The certificate validity period has ended. Renew the certificate immediately.'),
				'tags' => $notice_tags
			],
			self::TRIGGER_EXPIRY_CRIT => [
				'description' => _s('Certificate [%1$s]: expires in less than {$CERT.EXPIRY.CRIT} days', $target),
				'expression' => '(last('.$not_after.') - now()) / 86400 < {$CERT.EXPIRY.CRIT}',
				'priority' => TRIGGER_SEVERITY_HIGH,
				'status' => TRIGGER_STATUS_ENABLED,
				'comments' => _('The certificate expires very soon and must be renewed.'),
				'tags' => $notice_tags
			],
			self::TRIGGER_EXPIRY_AVG => [
				'description' => _s('Certificate [%1$s]: expires in less than {$CERT.EXPIRY.AVG} days', $target),
				'expression' => '(last('.$not_after.') - now()) / 86400 < {$CERT.EXPIRY.AVG}',
				'priority' => TRIGGER_SEVERITY_AVERAGE,
				'status' => TRIGGER_STATUS_ENABLED,
				'comments' => _('The certificate expires soon and should be renewed.'),
				'tags' => $notice_tags
			],
			self::TRIGGER_EXPIRY_WARN => [
				'description' => _s('Certificate [%1$s]: expires in less than {$CERT.EXPIRY.WARN} days', $target),
				'expression' => '(last('.$not_after.') - now()) / 86400 < {$CERT.EXPIRY.WARN}',
				'priority' => TRIGGER_SEVERITY_WARNING,
				'status' => TRIGGER_STATUS_ENABLED,
				'comments' => _('The certificate should be scheduled for renewal.'),
				'tags' => $notice_tags
			],

			// ------------------------------------------------------------ optional security triggers --

			// change() returns 1 when the last two values differ. It is documented as supported for the
			// value types Float, Integer, String, Text and Log, and "for strings returns: 0 - values are
			// equal; 1 - values differ".
			// See https://www.zabbix.com/documentation/7.4/en/manual/appendix/functions/history
			// change() is used instead of last(...,#1)<>last(...,#2), because in a comparison Zabbix casts
			// a string operand to a number as soon as that is possible, which would make two different but
			// numerically equal values (for example a serial "1e" and "1E") compare as equal.
			self::TRIGGER_ISSUER_CHANGED => [
				'description' => _s('Certificate [%1$s]: issuer changed', $target),
				'expression' => 'change('.$issuer.')=1',
				'priority' => $issuer_severity,
				'status' => $p['sec_issuer_changed'] ? TRIGGER_STATUS_ENABLED : TRIGGER_STATUS_DISABLED,
				'comments' => _('The certificate is now issued by a different authority than before. This is expected when the certificate authority was changed on purpose, and suspicious otherwise.'),
				'tags' => $security_tags
			],
			self::TRIGGER_FINGERPRINT_CHANGED => [
				'description' => _s('Certificate [%1$s]: certificate replaced (SHA-256 fingerprint changed)',
					$target
				),
				'expression' => 'change('.$fingerprint.')=1',
				'priority' => $fingerprint_severity,
				'status' => $p['sec_fingerprint_changed'] ? TRIGGER_STATUS_ENABLED : TRIGGER_STATUS_DISABLED,
				'comments' => _('The certificate presented by this website is not the same one as before. NOTE: every legitimate renewal changes the fingerprint as well, so this trigger fires on each renewal by design. Treat it as an audit signal, not as an incident.'),
				'tags' => $security_tags
			],
			// The agent does NOT report a key length, only the algorithm name ("RSA", "DSA", "ECDSA",
			// "Ed25519", "Unknown"), therefore a "< 2048 bit" check is impossible from this item and the
			// trigger matches weak ALGORITHMS instead. See the README for details.
			self::TRIGGER_WEAK_KEY => [
				'description' => _s('Certificate [%1$s]: weak public key algorithm', $target),
				'expression' => 'find('.$key_algorithm.',,"iregexp","{$CERT.KEY.ALGO.WEAK}")=1',
				'priority' => TRIGGER_SEVERITY_AVERAGE,
				'status' => $p['sec_weak_key'] ? TRIGGER_STATUS_ENABLED : TRIGGER_STATUS_DISABLED,
				'comments' => _('The public key algorithm of the certificate matches {$CERT.KEY.ALGO.WEAK}. The Zabbix agent does not report the key length, so this check cannot detect a short RSA key - only a weak algorithm.'),
				'tags' => $security_tags
			],
			self::TRIGGER_WEAK_SIGNATURE => [
				'description' => _s('Certificate [%1$s]: weak signature algorithm', $target),
				'expression' => 'find('.$signature_algorithm.',,"iregexp","{$CERT.SIG.ALGO.WEAK}")=1',
				'priority' => TRIGGER_SEVERITY_HIGH,
				'status' => $p['sec_weak_signature'] ? TRIGGER_STATUS_ENABLED : TRIGGER_STATUS_DISABLED,
				'comments' => _('The certificate is signed with an algorithm matching {$CERT.SIG.ALGO.WEAK}, for example SHA1-RSA or MD5-RSA. Such signatures are no longer considered collision resistant.'),
				'tags' => $security_tags
			]
		];

		foreach ($definitions as $id => &$trigger) {
			$trigger['manual_close'] = ZBX_TRIGGER_MANUAL_CLOSE_ALLOWED;
			$trigger['event_name'] = $trigger['description'];
			$trigger['tags'][] = ['tag' => self::TRIGGER_TAG_ID, 'value' => $id];
		}
		unset($trigger);

		return $definitions;
	}
}
