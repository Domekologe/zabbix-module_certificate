<?php declare(strict_types = 1);
/**
 * Certificate Monitor - parser and validator of the bulk import list.
 *
 * Accepted format, one website per line:
 *
 *     host[:port][,hostgroup][,description]
 *
 *   - host       DNS name or IP address of the website. Required.
 *   - port       TCP port, appended after a colon. Optional, defaults to the configured default port.
 *   - hostgroup  Name of an existing Zabbix host group. Optional, defaults to the configured default
 *                host group. The group is never created; an unknown name makes the line invalid.
 *   - description  Free text stored in the host description. Optional. Everything after the second
 *                comma belongs to it, so it may contain further commas.
 *
 * Every line is run through str_getcsv(), therefore a pasted list and an uploaded CSV file are parsed
 * by exactly the same code, and a field that contains a comma can be quoted:
 *
 *     www.example.com:443,Web servers,"Public site, main domain"
 *
 * Empty lines and lines starting with "#" are ignored. A leading header line whose first field is
 * "host" or "hostname" is ignored as well, so a CSV exported with a header can be fed in unchanged.
 *
 * The parser never touches the configuration; it only reads host groups and existing hosts in order to
 * classify each line. Creating the selected entries is the job of CertProvision::create().
 *
 * Author: Domekologe <support@domekologe.eu>
 */

namespace Modules\CertMonitor\Includes;

use API;

class CertImportParser {

	/**
	 * Per-line results.
	 */
	public const STATUS_OK = 'ok';
	public const STATUS_DUPLICATE = 'duplicate';
	public const STATUS_INVALID = 'invalid';

	/**
	 * Hard limit on the number of lines accepted in one import.
	 *
	 * Each created website means one host, fifteen items and nine triggers, so a very large import would
	 * run into the PHP execution time limit. The limit is deliberately low enough to stay interactive.
	 */
	public const MAX_LINES = 500;

	/**
	 * Maximum accepted size of an uploaded CSV file, in bytes.
	 */
	public const MAX_FILE_SIZE = 1048576;

	/**
	 * Parse and classify the whole import list.
	 *
	 * @param string $text      The raw pasted / uploaded text.
	 * @param array  $settings  Module settings, as returned by CertConfig::get().
	 *
	 * @return array  A list of rows; see makeRow() for the keys of one row.
	 */
	public static function parse(string $text, array $settings): array {
		$rows = [];
		$line_number = 0;
		$first_data_line = true;

		$default_port = (int) $settings[CertConfig::DEFAULT_PORT];
		$default_groupid = (string) $settings[CertConfig::DEFAULT_GROUPID];
		$host_prefix = (string) $settings[CertConfig::HOST_PREFIX];

		$groups = self::getWritableGroups();
		$default_group_name = array_key_exists($default_groupid, $groups) ? $groups[$default_groupid] : '';

		// Case-insensitive lookup by group name.
		$groups_by_name = [];

		foreach ($groups as $groupid => $name) {
			$groups_by_name[mb_strtolower($name)] = (string) $groupid;
		}

		foreach (preg_split('/\R/', $text) as $raw_line) {
			$line_number++;

			$line = trim($raw_line);

			if ($line === '' || strncmp($line, '#', 1) === 0) {
				continue;
			}

			$fields = str_getcsv($line, ',', '"', '\\');
			$fields = array_map(static fn($field) => trim((string) $field), $fields);

			if ($first_data_line) {
				$first_data_line = false;

				if (in_array(mb_strtolower($fields[0]), ['host', 'hostname'], true)) {
					// A CSV header line.
					continue;
				}
			}

			if (count($rows) >= self::MAX_LINES) {
				$rows[] = self::makeRow($line_number, $line, self::STATUS_INVALID,
					_s('Only %1$s entries can be imported at once. Split the list.', (string) self::MAX_LINES)
				);
				break;
			}

			$rows[] = self::parseLine($line_number, $line, $fields, $default_port, $default_groupid,
				$default_group_name, $groups, $groups_by_name
			);
		}

		return self::markDuplicates($rows, $host_prefix);
	}

	/**
	 * Parse and validate a single already-split line.
	 *
	 * @return array  One row.
	 */
	private static function parseLine(int $line_number, string $line, array $fields, int $default_port,
			string $default_groupid, string $default_group_name, array $groups, array $groups_by_name): array {
		$target = $fields[0];
		$group_name = array_key_exists(1, $fields) ? $fields[1] : '';
		$description = count($fields) > 2 ? implode(',', array_slice($fields, 2)) : '';

		if ($target === '') {
			return self::makeRow($line_number, $line, self::STATUS_INVALID, _('No host name given.'));
		}

		// Split an optional ":port" suffix off the host. IPv6 literals are not supported here, and they
		// are rejected by CertHelper::isValidHostname() anyway, because a colon cannot appear unquoted
		// inside a Zabbix item key parameter.
		$hostname = $target;
		$port = $default_port;
		$colon = strrpos($target, ':');

		if ($colon !== false) {
			$hostname = substr($target, 0, $colon);
			$port_text = substr($target, $colon + 1);

			if (!ctype_digit($port_text) || (int) $port_text < 1 || (int) $port_text > 65535) {
				return self::makeRow($line_number, $line, self::STATUS_INVALID,
					_s('"%1$s" is not a valid port number.', $port_text)
				);
			}

			$port = (int) $port_text;
		}

		if (!CertHelper::isValidHostname($hostname)) {
			return self::makeRow($line_number, $line, self::STATUS_INVALID,
				_s('"%1$s" is not a valid DNS name or IP address.', $hostname)
			);
		}

		if ($group_name === '') {
			if ($default_groupid === '') {
				return self::makeRow($line_number, $line, self::STATUS_INVALID,
					_('No host group given and no default host group is configured in the module settings.')
				);
			}

			$groupid = $default_groupid;
			$group_name = $default_group_name;
		}
		else {
			$key = mb_strtolower($group_name);

			if (!array_key_exists($key, $groups_by_name)) {
				return self::makeRow($line_number, $line, self::STATUS_INVALID,
					_s('Host group "%1$s" does not exist or you have no permission to write to it. Host groups are never created by the import.',
						$group_name
					)
				);
			}

			$groupid = $groups_by_name[$key];
			$group_name = $groups[$groupid];
		}

		if (mb_strlen($description) > 65535) {
			return self::makeRow($line_number, $line, self::STATUS_INVALID, _('The description is too long.'));
		}

		$row = self::makeRow($line_number, $line, self::STATUS_OK, '');

		$row['hostname'] = $hostname;
		$row['port'] = $port;
		$row['groupid'] = $groupid;
		$row['group_name'] = $group_name;
		$row['description'] = $description;

		return $row;
	}

	/**
	 * Flag every OK row whose technical host name already exists, or that repeats an earlier row.
	 *
	 * @param array  $rows
	 * @param string $host_prefix
	 *
	 * @return array
	 */
	private static function markDuplicates(array $rows, string $host_prefix): array {
		$host_names = [];

		foreach ($rows as $index => $row) {
			if ($row['status'] !== self::STATUS_OK) {
				continue;
			}

			$rows[$index]['host_name'] = CertHelper::makeHostName($row['hostname'], $row['port'], $host_prefix);
			$host_names[] = $rows[$index]['host_name'];
		}

		$existing = [];

		if ($host_names) {
			$db_hosts = API::Host()->get([
				'output' => ['host'],
				'filter' => ['host' => array_values(array_unique($host_names))]
			]);

			foreach ($db_hosts as $db_host) {
				$existing[(string) $db_host['host']] = true;
			}
		}

		$seen = [];

		foreach ($rows as $index => $row) {
			if ($row['status'] !== self::STATUS_OK) {
				continue;
			}

			$host_name = $rows[$index]['host_name'];

			if (array_key_exists($host_name, $existing)) {
				$rows[$index]['status'] = self::STATUS_DUPLICATE;
				$rows[$index]['message'] = _s('Host "%1$s" already exists - this website is monitored already.',
					$host_name
				);

				continue;
			}

			if (array_key_exists($host_name, $seen)) {
				$rows[$index]['status'] = self::STATUS_DUPLICATE;
				$rows[$index]['message'] = _s('Line %1$s of this list already defines "%2$s".',
					(string) $seen[$host_name], $host_name
				);

				continue;
			}

			$seen[$host_name] = $row['line'];
		}

		return $rows;
	}

	/**
	 * Build one result row.
	 *
	 * Keys:
	 *   line         Line number in the submitted text, for the message shown to the user.
	 *   raw          The line as it was submitted.
	 *   status       One of the STATUS_* constants.
	 *   message      Reason, for the "duplicate" and "invalid" states.
	 *   hostname     Parsed values; only meaningful while the status is STATUS_OK or STATUS_DUPLICATE.
	 *   port
	 *   groupid
	 *   group_name
	 *   description
	 *   host_name    The technical host name that would be created.
	 *
	 * @return array
	 */
	private static function makeRow(int $line, string $raw, string $status, string $message): array {
		return [
			'line' => $line,
			'raw' => $raw,
			'status' => $status,
			'message' => $message,
			'hostname' => '',
			'port' => 0,
			'groupid' => '',
			'group_name' => '',
			'description' => '',
			'host_name' => ''
		];
	}

	/**
	 * All host groups the current user may write to, as groupid => name.
	 *
	 * @return array
	 */
	private static function getWritableGroups(): array {
		$groups = API::HostGroup()->get([
			'output' => ['groupid', 'name'],
			'editable' => true,
			'preservekeys' => true
		]);

		$result = [];

		foreach ($groups as $groupid => $group) {
			$result[(string) $groupid] = (string) $group['name'];
		}

		return $result;
	}

	/**
	 * Read the uploaded CSV file of the import form, if there is one.
	 *
	 * $_FILES is used directly, because the Zabbix input validator does not cover file uploads. The
	 * upload is only accepted when PHP itself confirms that it is a real upload, so no local file can be
	 * read through this path.
	 *
	 * @param string $field  Name of the file input.
	 *
	 * @return string  The file contents, or an empty string when no file was uploaded.
	 *
	 * @throws \Exception  When a file was submitted but cannot be used.
	 */
	public static function readUploadedFile(string $field): string {
		if (!array_key_exists($field, $_FILES) || !is_array($_FILES[$field])) {
			return '';
		}

		$file = $_FILES[$field];
		$error = array_key_exists('error', $file) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

		if ($error === UPLOAD_ERR_NO_FILE) {
			return '';
		}

		if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
			throw new \Exception(_('The uploaded file is too large.'));
		}

		if ($error !== UPLOAD_ERR_OK) {
			throw new \Exception(_('The file could not be uploaded.'));
		}

		$tmp_name = (string) ($file['tmp_name'] ?? '');

		if ($tmp_name === '' || !is_uploaded_file($tmp_name)) {
			throw new \Exception(_('The file could not be uploaded.'));
		}

		if (filesize($tmp_name) > self::MAX_FILE_SIZE) {
			throw new \Exception(_('The uploaded file is too large.'));
		}

		$contents = file_get_contents($tmp_name);

		if ($contents === false) {
			throw new \Exception(_('The uploaded file could not be read.'));
		}

		// Strip a UTF-8 byte order mark, which spreadsheet applications like to add to exported CSV files
		// and which would otherwise become part of the first host name.
		if (strncmp($contents, "\xEF\xBB\xBF", 3) === 0) {
			$contents = substr($contents, 3);
		}

		if (!mb_check_encoding($contents, 'UTF-8')) {
			throw new \Exception(_('The uploaded file is not valid UTF-8 text.'));
		}

		return $contents;
	}
}
