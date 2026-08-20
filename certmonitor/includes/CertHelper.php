<?php declare(strict_types = 1);
/**
 * Certificate Monitor - shared helper.
 *
 * Holds all constants and pure helper functions that are used by more than one action controller.
 *
 * Author: Domekologe <support@domekologe.eu>
 */

namespace Modules\CertMonitor\Includes;

class CertHelper {

	/**
	 * Host tag that marks a host as being managed by this module.
	 * Hosts without this tag are never listed and never deleted by the module.
	 */
	public const HOST_TAG = 'certmonitor';
	public const HOST_TAG_VALUE = 'website';

	/**
	 * Technical host name prefix for hosts created by this module.
	 */
	public const HOST_PREFIX = 'cert_';

	/**
	 * User macros written to every host created by this module.
	 */
	public const MACRO_HOSTNAME = '{$CERT.WEBSITE.HOSTNAME}';
	public const MACRO_PORT = '{$CERT.WEBSITE.PORT}';
	public const MACRO_IP = '{$CERT.WEBSITE.IP}';
	public const MACRO_EXPIRY_WARN = '{$CERT.EXPIRY.WARN}';
	public const MACRO_EXPIRY_AVG = '{$CERT.EXPIRY.AVG}';
	public const MACRO_EXPIRY_CRIT = '{$CERT.EXPIRY.CRIT}';
	public const MACRO_IGNORE_VALIDATION = '{$CERT.IGNORE.VALIDATION}';

	/**
	 * Macros that switch the optional security triggers on ("1") or off ("0").
	 *
	 * As with {$CERT.IGNORE.VALIDATION}, the macro only records the intent: the trigger itself is always
	 * created and is enabled or disabled accordingly, so it stays visible on the host and can be switched
	 * back on by hand at any time.
	 */
	public const MACRO_SEC_ISSUER_CHANGED = '{$CERT.SEC.ISSUER.CHANGED}';
	public const MACRO_SEC_FINGERPRINT_CHANGED = '{$CERT.SEC.FINGERPRINT.CHANGED}';
	public const MACRO_SEC_WEAK_KEY = '{$CERT.SEC.WEAK.KEY}';
	public const MACRO_SEC_WEAK_SIGNATURE = '{$CERT.SEC.WEAK.SIGNATURE}';

	/**
	 * Patterns used by the two "weak algorithm" triggers.
	 *
	 * Both are PCRE patterns evaluated by find(...,"iregexp",...) against the value of the corresponding
	 * dependent item, therefore they can be adjusted per host by editing the macro.
	 */
	public const MACRO_WEAK_KEY_ALGORITHMS = '{$CERT.KEY.ALGO.WEAK}';
	public const MACRO_WEAK_SIGNATURE_ALGORITHMS = '{$CERT.SIG.ALGO.WEAK}';

	/**
	 * Common prefix of every user macro written by this module.
	 */
	public const MACRO_PREFIX = '{$CERT.';

	/**
	 * Item keys created on the monitored host.
	 *
	 * The master item key is built from user macros so that the target of the check can be changed later
	 * by editing the host macros only, without touching the item itself.
	 */
	public const KEY_MASTER = 'web.certificate.get['
		.self::MACRO_HOSTNAME.','.self::MACRO_PORT.','.self::MACRO_IP.']';

	public const KEY_NOT_AFTER = 'cert.not_after';
	public const KEY_NOT_AFTER_STR = 'cert.not_after.value';
	public const KEY_NOT_BEFORE = 'cert.not_before';
	public const KEY_ISSUER = 'cert.issuer';
	public const KEY_SUBJECT = 'cert.subject';
	public const KEY_ALT_NAMES = 'cert.alternative_names';
	public const KEY_VALIDATION = 'cert.validation';
	public const KEY_MESSAGE = 'cert.message';
	public const KEY_FINGERPRINT = 'cert.sha256_fingerprint';
	public const KEY_FINGERPRINT_SHA1 = 'cert.sha1_fingerprint';
	public const KEY_VERSION = 'cert.version';
	public const KEY_SERIAL = 'cert.serial_number';
	public const KEY_SIGNATURE_ALGORITHM = 'cert.signature_algorithm';
	public const KEY_PUBLIC_KEY_ALGORITHM = 'cert.public_key_algorithm';

	/**
	 * Host tags that this module manages itself. They are always written on create/update and are hidden
	 * from the "Tags" field of the form, so that a user cannot break the module by removing them.
	 */
	public const RESERVED_TAGS = [self::HOST_TAG, 'website'];

	/**
	 * Default values offered by the "Add website" form.
	 *
	 * These are the built-in fallbacks. They are only used when nothing is stored in the module
	 * configuration; see CertConfig.
	 */
	public const DEFAULT_PORT = 443;
	public const DEFAULT_AGENT_PORT = 10050;
	public const DEFAULT_AGENT_ADDRESS = '127.0.0.1';
	public const DEFAULT_WARN_DAYS = 30;
	public const DEFAULT_AVG_DAYS = 14;
	public const DEFAULT_CRIT_DAYS = 7;

	/**
	 * Shipped defaults of the optional security triggers.
	 *
	 * They are all switched off by default, so that upgrading the module never silently adds problems to
	 * an existing installation.
	 */
	public const DEFAULT_SEC_ISSUER_CHANGED = false;
	public const DEFAULT_SEC_FINGERPRINT_CHANGED = false;
	public const DEFAULT_SEC_WEAK_KEY = false;
	public const DEFAULT_SEC_WEAK_SIGNATURE = false;

	/**
	 * Shipped default patterns of the two "weak algorithm" triggers.
	 *
	 * The Zabbix agent 2 plugin reports Go's own algorithm names, i.e. "SHA1-RSA", "MD5-RSA",
	 * "SHA256-RSA", "ECDSA-SHA384" for the signature algorithm and one of "RSA", "DSA", "ECDSA",
	 * "Ed25519" or "Unknown" for the public key algorithm. The patterns below are written for exactly
	 * those spellings and are matched case-insensitively.
	 */
	public const DEFAULT_WEAK_SIGNATURE_ALGORITHMS = 'SHA1|MD5|MD2';
	public const DEFAULT_WEAK_KEY_ALGORITHMS = 'DSA|Unknown';

	/**
	 * Shipped default severities of the two "changed" triggers.
	 *
	 * A change of the fingerprint is the normal consequence of a certificate renewal, therefore its
	 * default severity is only WARNING. A change of the issuer is far more unusual and defaults to
	 * AVERAGE.
	 */
	public const DEFAULT_FINGERPRINT_SEVERITY = TRIGGER_SEVERITY_WARNING;
	public const DEFAULT_ISSUER_SEVERITY = TRIGGER_SEVERITY_AVERAGE;

	/**
	 * Update interval of the master item.
	 */
	public const MASTER_DELAY = '1h';

	/**
	 * History retention of the master item.
	 *
	 * The raw certificate JSON has to be kept for a while, otherwise the "Certificate" section of the
	 * detail page has nothing to read: Manager::History()->getLastValues() reads the history tables, and
	 * an item with history "0" never writes one. A week is enough for a master item polled once per hour
	 * and is negligible in size (one JSON document per changed certificate).
	 */
	public const MASTER_HISTORY = '7d';

	/**
	 * Maximum length accepted for a host name / address input field.
	 */
	public const MAX_HOSTNAME_LENGTH = 255;

	/**
	 * Validation results reported in $.result.value by the agent item web.certificate.get.
	 */
	public const VALIDATION_VALID = 'valid';
	public const VALIDATION_SELF_SIGNED = 'valid-but-self-signed';
	public const VALIDATION_INVALID = 'invalid';

	/**
	 * Validate a DNS name or IP address.
	 *
	 * Only characters that are safe inside a Zabbix item key parameter and inside a host technical name
	 * are accepted. This deliberately rejects URL schemes, paths, spaces, quotes, commas and brackets.
	 *
	 * @param string $value
	 *
	 * @return bool
	 */
	public static function isValidHostname(string $value): bool {
		if ($value === '' || strlen($value) > self::MAX_HOSTNAME_LENGTH) {
			return false;
		}

		if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
			return true;
		}

		// Labels of 1-63 characters, separated by dots; no leading/trailing dot or dash.
		return (bool) preg_match('/^(?!-)[A-Za-z0-9-]{1,63}(?<!-)(\.(?!-)[A-Za-z0-9-]{1,63}(?<!-))*$/', $value);
	}

	/**
	 * Build the technical host name for a monitored website.
	 *
	 * Only characters allowed in a Zabbix host technical name are produced
	 * (alphanumerics, dot, dash, underscore).
	 *
	 * @param string $hostname
	 * @param int    $port
	 * @param string $prefix    Configurable host name prefix; falls back to HOST_PREFIX when unusable.
	 *
	 * @return string
	 */
	public static function makeHostName(string $hostname, int $port, string $prefix = self::HOST_PREFIX): string {
		return self::sanitizeHostPrefix($prefix).$hostname.'_'.$port;
	}

	/**
	 * Reduce a configured host name prefix to characters that are legal in a host technical name.
	 *
	 * Zabbix accepts alphanumerics, spaces, dots, dashes and underscores in a technical host name; spaces
	 * are dropped here as well because they make the name awkward to use in trigger expressions.
	 *
	 * @param string $prefix
	 *
	 * @return string  The cleaned prefix, or the built-in default when nothing usable is left.
	 */
	public static function sanitizeHostPrefix(string $prefix): string {
		$clean = preg_replace('/[^A-Za-z0-9._-]/', '', $prefix);

		if ($clean === null || $clean === '') {
			return self::HOST_PREFIX;
		}

		return mb_substr($clean, 0, 32);
	}

	/**
	 * Build the visible host name for a monitored website.
	 *
	 * @param string $hostname
	 * @param int    $port
	 *
	 * @return string
	 */
	public static function makeVisibleName(string $hostname, int $port): string {
		return _s('Certificate: %1$s:%2$s', $hostname, (string) $port);
	}

	/**
	 * Extract the value of a user macro from a host macro list returned by host.get(selectMacros).
	 *
	 * @param array  $macros
	 * @param string $macro
	 * @param string $default
	 *
	 * @return string
	 */
	public static function getMacroValue(array $macros, string $macro, string $default = ''): string {
		foreach ($macros as $host_macro) {
			if (array_key_exists('macro', $host_macro) && $host_macro['macro'] === $macro) {
				return (string) $host_macro['value'];
			}
		}

		return $default;
	}

	/**
	 * Check whether a user macro belongs to this module ("{$CERT.*}").
	 *
	 * @param string $macro
	 *
	 * @return bool
	 */
	public static function isCertMacro(string $macro): bool {
		return strncmp($macro, self::MACRO_PREFIX, strlen(self::MACRO_PREFIX)) === 0;
	}

	/**
	 * Interpret the stored value of {$CERT.IGNORE.VALIDATION} as a boolean.
	 *
	 * Everything except "1" counts as "not ignored", so a missing or hand-edited macro is safe.
	 *
	 * @param array $macros  Macro list as returned by host.get(selectMacros).
	 *
	 * @return bool
	 */
	public static function isValidationIgnored(array $macros): bool {
		return self::getMacroValue($macros, self::MACRO_IGNORE_VALIDATION, '0') === '1';
	}

	/**
	 * Interpret the stored value of one of the {$CERT.SEC.*} macros as a boolean.
	 *
	 * Everything except "1" counts as "off", so a missing or hand-edited macro is safe.
	 *
	 * @param array  $macros   Macro list as returned by host.get(selectMacros).
	 * @param string $macro    One of the MACRO_SEC_* constants.
	 * @param bool   $default  Returned when the macro is not present on the host at all.
	 *
	 * @return bool
	 */
	public static function isSecurityTriggerEnabled(array $macros, string $macro, bool $default = false): bool {
		$value = self::getMacroValue($macros, $macro, '');

		return $value === '' ? $default : $value === '1';
	}

	/**
	 * Is this a valid Zabbix trigger severity?
	 *
	 * @param int $severity
	 *
	 * @return bool
	 */
	public static function isValidSeverity(int $severity): bool {
		return $severity >= TRIGGER_SEVERITY_NOT_CLASSIFIED && $severity <= TRIGGER_SEVERITY_DISASTER;
	}

	/**
	 * Validate a pattern that is going to be embedded in a trigger expression as a quoted string.
	 *
	 * Two independent things are checked:
	 *   - the pattern must not contain a double quote or a backslash, because both would break out of the
	 *     quoted trigger-function argument it is written into;
	 *   - the pattern must compile as a PCRE, because the Zabbix server evaluates it with "iregexp" and a
	 *     broken pattern would silently make the trigger unsupported.
	 *
	 * @param string $pattern
	 *
	 * @return bool
	 */
	public static function isValidAlgorithmPattern(string $pattern): bool {
		if ($pattern === '' || strlen($pattern) > 255) {
			return false;
		}

		if (strpbrk($pattern, "\"\\\r\n") !== false) {
			return false;
		}

		// A pattern that PCRE cannot compile emits a warning; it is suppressed and turned into "invalid".
		return @preg_match('/'.str_replace('/', '\/', $pattern).'/i', '') !== false;
	}

	/**
	 * Build the "<hostname>:<port>" string that is embedded in trigger names and event names.
	 *
	 * @param string $hostname
	 * @param int    $port
	 *
	 * @return string
	 */
	public static function makeTarget(string $hostname, int $port): string {
		return $hostname.':'.$port;
	}

	/**
	 * Definitions of all dependent items created below the master item.
	 *
	 * Kept in one place so that "Add website" and "Update website" stay in sync and so that missing items
	 * can be created afterwards on hosts that were added by an older version of this module.
	 *
	 * The JSON field names are documented at
	 * https://www.zabbix.com/documentation/7.4/en/manual/config/items/itemtypes/zabbix_agent/zabbix_agent2
	 *
	 * @return array
	 */
	public static function getDependentItemDefinitions(): array {
		return [
			[
				'key' => self::KEY_NOT_AFTER,
				'name' => _('Certificate: Expires on'),
				'jsonpath' => '$.x509.not_after.timestamp',
				'value_type' => ITEM_VALUE_TYPE_UINT64,
				'units' => 'unixtime',
				'history' => '90d',
				'trends' => '0',
				'description' => _('The date on which the certificate validity period ends (UNIX timestamp).')
			],
			[
				'key' => self::KEY_NOT_AFTER_STR,
				'name' => _('Certificate: Expires on (text)'),
				'jsonpath' => '$.x509.not_after.value',
				'value_type' => ITEM_VALUE_TYPE_STR,
				'description' => _('The date on which the certificate validity period ends, as reported by the agent.')
			],
			[
				'key' => self::KEY_NOT_BEFORE,
				'name' => _('Certificate: Valid from'),
				'jsonpath' => '$.x509.not_before.timestamp',
				'value_type' => ITEM_VALUE_TYPE_UINT64,
				'units' => 'unixtime',
				'history' => '90d',
				'trends' => '0',
				'description' => _('The date on which the certificate validity period begins (UNIX timestamp).')
			],
			[
				'key' => self::KEY_ISSUER,
				'name' => _('Certificate: Issuer'),
				'jsonpath' => '$.x509.issuer',
				'value_type' => ITEM_VALUE_TYPE_TEXT,
				'description' => _('The entity that signed and issued the certificate.')
			],
			[
				'key' => self::KEY_SUBJECT,
				'name' => _('Certificate: Subject'),
				'jsonpath' => '$.x509.subject',
				'value_type' => ITEM_VALUE_TYPE_TEXT,
				'description' => _('The entity associated with the public key stored in the certificate.')
			],
			[
				'key' => self::KEY_ALT_NAMES,
				'name' => _('Certificate: Subject alternative names'),
				'jsonpath' => '$.x509.alternative_names',
				'value_type' => ITEM_VALUE_TYPE_TEXT,
				'description' => _('Subject alternative names of the certificate, if present.')
			],
			[
				'key' => self::KEY_VALIDATION,
				'name' => _('Certificate: Validation result'),
				'jsonpath' => '$.result.value',
				'value_type' => ITEM_VALUE_TYPE_STR,
				'description' => _('Certificate validation result. Possible values: valid, valid-but-self-signed, invalid.')
			],
			[
				'key' => self::KEY_MESSAGE,
				'name' => _('Certificate: Last validation message'),
				'jsonpath' => '$.result.message',
				'value_type' => ITEM_VALUE_TYPE_TEXT,
				'description' => _('Detailed message of the latest certificate validation.')
			],
			[
				'key' => self::KEY_FINGERPRINT,
				'name' => _('Certificate: Fingerprint (SHA-256)'),
				'jsonpath' => '$.sha256_fingerprint',
				'value_type' => ITEM_VALUE_TYPE_STR,
				'description' => _('The SHA-256 fingerprint of the certificate.')
			],
			[
				'key' => self::KEY_FINGERPRINT_SHA1,
				'name' => _('Certificate: Fingerprint (SHA-1)'),
				'jsonpath' => '$.sha1_fingerprint',
				'value_type' => ITEM_VALUE_TYPE_STR,
				'description' => _('The SHA-1 fingerprint of the certificate.')
			],
			[
				'key' => self::KEY_VERSION,
				'name' => _('Certificate: Version'),
				'jsonpath' => '$.x509.version',
				'value_type' => ITEM_VALUE_TYPE_STR,
				'description' => _('The X.509 version of the certificate.')
			],
			[
				'key' => self::KEY_SERIAL,
				'name' => _('Certificate: Serial number'),
				'jsonpath' => '$.x509.serial_number',
				'value_type' => ITEM_VALUE_TYPE_STR,
				'description' => _('The serial number assigned by the issuing authority.')
			],
			[
				'key' => self::KEY_SIGNATURE_ALGORITHM,
				'name' => _('Certificate: Signature algorithm'),
				'jsonpath' => '$.x509.signature_algorithm',
				'value_type' => ITEM_VALUE_TYPE_STR,
				'description' => _('The algorithm used by the issuer to sign the certificate.')
			],
			[
				'key' => self::KEY_PUBLIC_KEY_ALGORITHM,
				'name' => _('Certificate: Public key algorithm'),
				'jsonpath' => '$.x509.public_key_algorithm',
				'value_type' => ITEM_VALUE_TYPE_STR,
				'description' => _('The algorithm of the public key stored in the certificate.')
			]
		];
	}

	/**
	 * Number of whole days between now and the given expiry timestamp. Negative when already expired.
	 *
	 * @param int      $not_after  UNIX timestamp.
	 * @param int|null $now        Reference time; the current time is used when omitted.
	 *
	 * @return int
	 */
	public static function daysLeft(int $not_after, ?int $now = null): int {
		return (int) floor((($not_after - ($now ?? time())) / SEC_PER_DAY));
	}

	/**
	 * Pick the colour class for a "days left" value, using the thresholds of that very host.
	 *
	 * @param int $days_left
	 * @param int $warn_days
	 * @param int $avg_days
	 * @param int $crit_days
	 *
	 * @return string  A ZBX_STYLE_* class name.
	 */
	public static function daysLeftStyle(int $days_left, int $warn_days, int $avg_days, int $crit_days): string {
		if ($days_left < 0 || $days_left < $crit_days) {
			return ZBX_STYLE_RED;
		}

		if ($days_left < $avg_days) {
			return ZBX_STYLE_ORANGE;
		}

		if ($days_left < $warn_days) {
			return ZBX_STYLE_YELLOW;
		}

		return ZBX_STYLE_GREEN;
	}

	/**
	 * Colour class for a validation result string.
	 *
	 * @param string|null $validation
	 *
	 * @return string  A ZBX_STYLE_* class name.
	 */
	public static function validationStyle(?string $validation): string {
		switch ($validation) {
			case self::VALIDATION_VALID:
				return ZBX_STYLE_GREEN;

			case self::VALIDATION_SELF_SIGNED:
				return ZBX_STYLE_ORANGE;

			case self::VALIDATION_INVALID:
				return ZBX_STYLE_RED;

			default:
				return ZBX_STYLE_GREY;
		}
	}

	/**
	 * Sanity check of the {$CERT.EXPIRY.*} macros of one host.
	 *
	 * The three expiry triggers only make sense when warning > average > high. A host whose macros were
	 * hand-edited into another order silently loses severity escalation, therefore this is reported.
	 *
	 * @param string $warn_days  Raw macro value.
	 * @param string $avg_days   Raw macro value.
	 * @param string $crit_days  Raw macro value.
	 *
	 * @return string  An empty string when the values are fine, otherwise a ready-to-display message.
	 */
	public static function checkThresholdSanity(string $warn_days, string $avg_days, string $crit_days): string {
		foreach ([$warn_days, $avg_days, $crit_days] as $value) {
			if ($value === '' || !ctype_digit($value) || (int) $value < 1) {
				return _('The {$CERT.EXPIRY.*} macros of this host are empty or not positive whole numbers. The expiry triggers cannot work as intended.');
			}
		}

		if (!((int) $warn_days > (int) $avg_days && (int) $avg_days > (int) $crit_days)) {
			return _('The {$CERT.EXPIRY.*} macros of this host are out of order. Expected: {$CERT.EXPIRY.WARN} > {$CERT.EXPIRY.AVG} > {$CERT.EXPIRY.CRIT}.');
		}

		return '';
	}

	/**
	 * Is this host tag managed by the module itself?
	 *
	 * @param string $tag
	 *
	 * @return bool
	 */
	public static function isReservedTag(string $tag): bool {
		return in_array($tag, self::RESERVED_TAGS, true);
	}

	/**
	 * Convert the free-text "Tags" field of the form into a host.tags array.
	 *
	 * One tag per line, written as "name" or "name=value". The first "=" separates name from value, so a
	 * value may contain further "=" characters. Empty lines are skipped, reserved tags are dropped because
	 * the module writes them itself.
	 *
	 * @param string $text
	 *
	 * @return array  Array of ['tag' => ..., 'value' => ...].
	 */
	public static function parseTagsText(string $text): array {
		$tags = [];
		$seen = [];

		foreach (preg_split('/\R/', $text) as $line) {
			$line = trim($line);

			if ($line === '') {
				continue;
			}

			$pos = strpos($line, '=');

			if ($pos === false) {
				$tag = trim($line);
				$value = '';
			}
			else {
				$tag = trim(substr($line, 0, $pos));
				$value = trim(substr($line, $pos + 1));
			}

			if ($tag === '' || self::isReservedTag($tag)) {
				continue;
			}

			$signature = $tag."\0".$value;

			if (array_key_exists($signature, $seen)) {
				continue;
			}

			$seen[$signature] = true;
			$tags[] = ['tag' => $tag, 'value' => $value];
		}

		return $tags;
	}

	/**
	 * Convert a host.tags array back into the free-text representation used by the form.
	 *
	 * @param array $tags  Array of ['tag' => ..., 'value' => ...].
	 *
	 * @return string
	 */
	public static function tagsToText(array $tags): string {
		$lines = [];

		foreach ($tags as $tag) {
			$name = (string) ($tag['tag'] ?? '');

			if ($name === '' || self::isReservedTag($name)) {
				continue;
			}

			$value = (string) ($tag['value'] ?? '');
			$lines[] = $value === '' ? $name : $name.'='.$value;
		}

		return implode("\n", $lines);
	}

	/**
	 * Split a subject alternative name list into DNS names and IP addresses.
	 *
	 * web.certificate.get returns the alternative names as one flat list. Depending on the certificate
	 * and the agent build, an IP entry can appear bare ("192.0.2.10") or prefixed ("IP Address:192.0.2.10",
	 * "IP:192.0.2.10"), so both forms are recognised. Everything that is not a valid IP address after
	 * stripping a known prefix is treated as a DNS name.
	 *
	 * @param string|null $value  Comma or newline separated list, as produced by certValue().
	 *
	 * @return array  ['dns' => string[], 'ips' => string[]]
	 */
	public static function splitAlternativeNames(?string $value): array {
		$result = ['dns' => [], 'ips' => []];

		if ($value === null || trim($value) === '') {
			return $result;
		}

		foreach (preg_split('/[,\r\n]+/', $value) as $entry) {
			$entry = trim($entry);

			if ($entry === '') {
				continue;
			}

			// Strip a "DNS:" / "IP:" / "IP Address:" prefix if the agent included one.
			$bare = preg_replace('/^(DNS|IP Address|IP)\s*:\s*/i', '', $entry);
			$bare = trim((string) $bare, " \t\"[]");

			if ($bare !== '' && filter_var($bare, FILTER_VALIDATE_IP) !== false) {
				$result['ips'][] = $bare;
			}
			elseif ($bare !== '') {
				$result['dns'][] = $bare;
			}
		}

		$result['dns'] = array_values(array_unique($result['dns']));
		$result['ips'] = array_values(array_unique($result['ips']));

		return $result;
	}

	/**
	 * Decode the raw JSON document returned by web.certificate.get.
	 *
	 * @param string|null $raw
	 *
	 * @return array|null  The decoded object, or null when the value is missing or not a JSON object.
	 */
	public static function decodeCertificateJson(?string $raw): ?array {
		if ($raw === null || trim($raw) === '') {
			return null;
		}

		$decoded = json_decode($raw, true);

		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * Read a value out of the decoded certificate JSON by a dotted path, e.g. "x509.not_after.value".
	 *
	 * Arrays are flattened into a comma separated string so that the value can be printed directly.
	 *
	 * @param array|null $cert
	 * @param string     $path
	 *
	 * @return string|null
	 */
	public static function certValue(?array $cert, string $path): ?string {
		if ($cert === null) {
			return null;
		}

		$node = $cert;

		foreach (explode('.', $path) as $part) {
			if (!is_array($node) || !array_key_exists($part, $node)) {
				return null;
			}

			$node = $node[$part];
		}

		if (is_array($node)) {
			$flat = [];

			foreach ($node as $item) {
				if (is_scalar($item)) {
					$flat[] = (string) $item;
				}
			}

			return $flat ? implode(', ', $flat) : null;
		}

		if (is_bool($node)) {
			return $node ? '1' : '0';
		}

		return is_scalar($node) ? (string) $node : null;
	}
}
