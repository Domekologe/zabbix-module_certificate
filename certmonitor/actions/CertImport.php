<?php declare(strict_types = 1);
/**
 * Certificate Monitor - the bulk import form and its preview.
 *
 * The page has two states, selected by the request:
 *
 *   - no input           -> the empty form: a text area and a CSV file upload;
 *   - preview=1          -> the same form plus a preview table that classifies every submitted line as
 *                           OK, "already monitored" or invalid, with one checkbox per line.
 *
 * Nothing is created here. Pressing "Import" in the preview submits to "certmonitor.import.create",
 * which parses the very same text again and creates only the selected lines.
 *
 * Accepted format, one website per line:
 *
 *     host[:port][,hostgroup][,description]
 *
 * See CertImportParser for the full description.
 *
 * Author: Domekologe <support@domekologe.eu>
 */

namespace Modules\CertMonitor\Actions;

use API;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CRoleHelper;
use Modules\CertMonitor\Includes\CertConfig;
use Modules\CertMonitor\Includes\CertImportParser;

class CertImport extends CController {

	protected function init(): void {
		// The form is opened with GET and the preview creates nothing at all; the CSRF token is validated
		// by "certmonitor.import.create", which is the only action of this pair that writes.
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'import_text' =>	'string',
			'preview' =>		'in 1'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN
			&& $this->checkAccess(CRoleHelper::UI_CONFIGURATION_HOSTS);
	}

	protected function doAction(): void {
		$settings = CertConfig::get();

		$import_text = (string) $this->getInput('import_text', '');
		$upload_ok = false;
		$upload_failed = false;

		try {
			$uploaded = CertImportParser::readUploadedFile('import_file');

			if ($uploaded !== '') {
				// An uploaded file always wins over the text area, and its contents are written back into
				// the text area so that the user can still correct single lines before importing.
				$import_text = $uploaded;
				$upload_ok = true;
			}
		}
		catch (\Exception $e) {
			$upload_failed = true;
			error($e->getMessage());
		}

		$rows = [];
		$has_preview = false;

		// A successful upload is previewed straight away; a pasted list is previewed on request.
		if (!$upload_failed && trim($import_text) !== '' && ($upload_ok || $this->hasInput('preview'))) {
			$rows = CertImportParser::parse($import_text, $settings);
			$has_preview = true;
		}

		$summary = [
			CertImportParser::STATUS_OK => 0,
			CertImportParser::STATUS_DUPLICATE => 0,
			CertImportParser::STATUS_INVALID => 0
		];

		foreach ($rows as $row) {
			$summary[$row['status']]++;
		}

		$data = [
			'import_text' => $import_text,
			'rows' => $rows,
			'has_preview' => $has_preview,
			'summary' => $summary,
			'default_port' => $settings[CertConfig::DEFAULT_PORT],
			'default_group_name' => $this->getDefaultGroupName($settings[CertConfig::DEFAULT_GROUPID]),
			'host_prefix' => $settings[CertConfig::HOST_PREFIX],
			'max_lines' => CertImportParser::MAX_LINES
		];

		$response = new CControllerResponseData($data);
		$response->setTitle(_('Import websites'));

		$this->setResponse($response);
	}

	/**
	 * Name of the configured default host group, for the hint shown above the text area.
	 *
	 * @param string $groupid
	 *
	 * @return string  An empty string when no default group is configured or it is not readable.
	 */
	private function getDefaultGroupName(string $groupid): string {
		if ($groupid === '' || !ctype_digit($groupid)) {
			return '';
		}

		$groups = API::HostGroup()->get([
			'output' => ['name'],
			'groupids' => [$groupid],
			'editable' => true,
			'limit' => 1
		]);

		return $groups ? (string) $groups[0]['name'] : '';
	}
}
