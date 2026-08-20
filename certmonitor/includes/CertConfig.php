<?php declare(strict_types = 1);
/**
 * Certificate Monitor - persistent module settings.
 *
 * The settings are stored in the "config" section of the module, which Zabbix keeps in the "module"
 * database table as a JSON document.
 *
 * Verified against the Zabbix 7.4 sources (branch release/7.4):
 *
 * - ui/include/classes/core/CModule.php provides getConfig(), getOption() and setConfig(). setConfig()
 *   persists the configuration by calling
 *       API::Module()->update([['moduleid' => ..., 'config' => ...]])
 *   so module configuration really can be written back at runtime.
 * - ui/include/classes/api/services/CModule.php declares "config" as an API_OBJECT with
 *   API_ALLOW_UNEXPECTED in the rules of both module.create and module.update, i.e. any JSON-encodable
 *   structure is accepted.
 * - The same file restricts module.get, module.update and module.delete to USER_TYPE_SUPER_ADMIN. Writing
 *   the settings is therefore possible for Super admins only. The settings page enforces this in
 *   checkPermissions().
 * - ui/include/classes/core/ZBase.php reads the stored configuration straight from the database while
 *   initialising the module manager and hands it to CModuleManager::addModule() as an override, so
 *   READING the settings through APP::ModuleManager() works for every user type and does not touch the
 *   Super-admin-only module.get method.
 *
 * @see https://www.zabbix.com/documentation/7.4/en/manual/api/reference/module/update
 * @see https://www.zabbix.com/documentation/7.4/en/manual/modules
 *
 * Author: Domekologe <support@domekologe.eu>
 */

namespace Modules\CertMonitor\Includes;

use APP;
use Zabbix\Core\CModule;

class CertConfig {

	/**
	 * The "id" property of manifest.json. Used to look the loaded module instance up.
	 */
	public const MODULE_ID = 'dks_certmonitor';

	/**
	 * Setting names. These are also the field names of the settings form.
	 */
	public const DEFAULT_PORT = 'default_port';
	public const DEFAULT_AGENT_ADDRESS = 'default_agent_address';
	public const DEFAULT_AGENT_PORT = 'default_agent_port';
	public const DEFAULT_GROUPID = 'default_groupid';
	public const DEFAULT_WARN_DAYS = 'default_warn_days';
	public const DEFAULT_AVG_DAYS = 'default_avg_days';
	public const DEFAULT_CRIT_DAYS = 'default_crit_days';
	public const DEFAULT_IGNORE_VALIDATION = 'default_ignore_validation';
	public const DEFAULT_DELAY = 'default_delay';
	public const HOST_PREFIX = 'host_prefix';

	/**
	 * Org-wide defaults of the optional security triggers.
	 */
	public const DEFAULT_SEC_ISSUER_CHANGED = 'default_sec_issuer_changed';
	public const DEFAULT_SEC_FINGERPRINT_CHANGED = 'default_sec_fingerprint_changed';
	public const DEFAULT_SEC_WEAK_KEY = 'default_sec_weak_key';
	public const DEFAULT_SEC_WEAK_SIGNATURE = 'default_sec_weak_signature';
	public const DEFAULT_WEAK_KEY_ALGORITHMS = 'default_weak_key_algorithms';
	public const DEFAULT_WEAK_SIGNATURE_ALGORITHMS = 'default_weak_signature_algorithms';
	public const DEFAULT_ISSUER_SEVERITY = 'default_issuer_severity';
	public const DEFAULT_FINGERPRINT_SEVERITY = 'default_fingerprint_severity';

	/**
	 * Built-in fallbacks, used whenever nothing is stored yet or a stored value is unusable.
	 *
	 * The values are taken from the CertHelper constants, so the constants stay the single source of
	 * truth for the shipped defaults.
	 *
	 * @return array
	 */
	public static function getDefaults(): array {
		return [
			self::DEFAULT_PORT => (string) CertHelper::DEFAULT_PORT,
			self::DEFAULT_AGENT_ADDRESS => CertHelper::DEFAULT_AGENT_ADDRESS,
			self::DEFAULT_AGENT_PORT => (string) CertHelper::DEFAULT_AGENT_PORT,
			self::DEFAULT_GROUPID => '',
			self::DEFAULT_WARN_DAYS => (string) CertHelper::DEFAULT_WARN_DAYS,
			self::DEFAULT_AVG_DAYS => (string) CertHelper::DEFAULT_AVG_DAYS,
			self::DEFAULT_CRIT_DAYS => (string) CertHelper::DEFAULT_CRIT_DAYS,
			self::DEFAULT_IGNORE_VALIDATION => '0',
			self::DEFAULT_DELAY => CertHelper::MASTER_DELAY,
			self::HOST_PREFIX => CertHelper::HOST_PREFIX,
			self::DEFAULT_SEC_ISSUER_CHANGED => CertHelper::DEFAULT_SEC_ISSUER_CHANGED ? '1' : '0',
			self::DEFAULT_SEC_FINGERPRINT_CHANGED => CertHelper::DEFAULT_SEC_FINGERPRINT_CHANGED ? '1' : '0',
			self::DEFAULT_SEC_WEAK_KEY => CertHelper::DEFAULT_SEC_WEAK_KEY ? '1' : '0',
			self::DEFAULT_SEC_WEAK_SIGNATURE => CertHelper::DEFAULT_SEC_WEAK_SIGNATURE ? '1' : '0',
			self::DEFAULT_WEAK_KEY_ALGORITHMS => CertHelper::DEFAULT_WEAK_KEY_ALGORITHMS,
			self::DEFAULT_WEAK_SIGNATURE_ALGORITHMS => CertHelper::DEFAULT_WEAK_SIGNATURE_ALGORITHMS,
			self::DEFAULT_ISSUER_SEVERITY => (string) CertHelper::DEFAULT_ISSUER_SEVERITY,
			self::DEFAULT_FINGERPRINT_SEVERITY => (string) CertHelper::DEFAULT_FINGERPRINT_SEVERITY
		];
	}

	/**
	 * Return the module instance that Zabbix loaded for this module, or null when it is not available.
	 *
	 * It can legitimately be null: the module manager only instantiates modules that are enabled and that
	 * the current user role is allowed to use.
	 *
	 * @return CModule|null
	 */
	private static function getModule(): ?CModule {
		return APP::ModuleManager()->getModule(self::MODULE_ID);
	}

	/**
	 * Return all settings, with every missing or unusable value replaced by its built-in fallback.
	 *
	 * Never throws: a broken configuration must not make the "Add website" form unusable.
	 *
	 * @return array  Setting name => string value.
	 */
	public static function get(): array {
		$defaults = self::getDefaults();
		$module = self::getModule();
		$stored = [];

		if ($module !== null) {
			$config = $module->getConfig();

			if (is_array($config)) {
				$stored = $config;
			}
		}

		$result = [];

		foreach ($defaults as $name => $default) {
			if (!array_key_exists($name, $stored) || !is_scalar($stored[$name])) {
				$result[$name] = $default;

				continue;
			}

			$value = trim((string) $stored[$name]);
			$result[$name] = $value === '' && $name !== self::DEFAULT_GROUPID ? $default : $value;
		}

		return $result;
	}

	/**
	 * Return a single setting.
	 *
	 * @param string $name
	 *
	 * @return string
	 */
	public static function getValue(string $name): string {
		$config = self::get();

		return array_key_exists($name, $config) ? $config[$name] : '';
	}

	/**
	 * Persist the settings.
	 *
	 * Only the known setting names are written, so that the stored document cannot be polluted with
	 * arbitrary request fields.
	 *
	 * @param array $config  Setting name => value.
	 *
	 * @throws \Exception  When the module instance is not available or the API rejects the update.
	 */
	public static function save(array $config): void {
		$module = self::getModule();

		if ($module === null) {
			throw new \Exception(
				_('The Certificate Monitor module is not loaded, therefore its settings cannot be saved.')
			);
		}

		$clean = [];

		foreach (array_keys(self::getDefaults()) as $name) {
			$clean[$name] = array_key_exists($name, $config) ? (string) $config[$name] : '';
		}

		// setConfig() calls API::Module()->update(), which reports its own errors and throws on failure.
		$module->setConfig($clean);
	}

	/**
	 * Are the stored settings still the built-in defaults?
	 *
	 * @return bool
	 */
	public static function isStored(): bool {
		$module = self::getModule();

		if ($module === null) {
			return false;
		}

		$config = $module->getConfig();

		return is_array($config) && $config !== [];
	}
}
