<?php declare(strict_types = 1);
/**
 * Certificate Monitor - creates the websites selected in the bulk import preview.
 *
 * The preview page submits the unchanged import text plus the line numbers the user left selected. The
 * text is parsed again here instead of trusting a serialised preview, so that nothing but the list the
 * user actually typed can reach the creation code, and so that a website that was created by somebody
 * else in the meantime is still recognised as a duplicate.
 *
 * Every line is created on its own: one failing line is reported and the remaining lines are still
 * created. CertProvision::create() rolls back the partially created host of a failing line by itself.
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
use Modules\CertMonitor\Includes\CertImportParser;
use Modules\CertMonitor\Includes\CertProvision;

class CertImportCreate extends CController {

	protected function checkInput(): bool {
		$fields = [
			'import_text' =>	'required|string|not_empty',
			// Line numbers of the rows the user left selected in the preview.
			'selected' =>		'array_id'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse($this->makeErrorResponse());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN
			&& $this->checkAccess(CRoleHelper::UI_CONFIGURATION_HOSTS);
	}

	protected function doAction(): void {
		$import_text = (string) $this->getInput('import_text');

		$selected = [];

		foreach ((array) $this->getInput('selected', []) as $line) {
			$selected[(int) $line] = true;
		}

		if (!$selected) {
			error(_('No line was selected for import.'));
			CMessageHelper::setErrorTitle(_('Cannot import websites'));

			$this->setResponse($this->makeErrorResponse());

			return;
		}

		$settings = CertConfig::get();
		$base_params = CertProvision::paramsFromSettings($settings);

		$rows = CertImportParser::parse($import_text, $settings);

		$created = 0;
		$skipped = 0;
		$failed = 0;

		foreach ($rows as $row) {
			if (!array_key_exists($row['line'], $selected)) {
				continue;
			}

			if ($row['status'] !== CertImportParser::STATUS_OK) {
				$skipped++;
				error(_s('Line %1$s (%2$s) was skipped: %3$s', (string) $row['line'], $row['raw'],
					$row['message']
				));

				continue;
			}

			$params = $base_params;

			$params['hostname'] = $row['hostname'];
			$params['port'] = $row['port'];
			$params['groupid'] = $row['groupid'];
			$params['description'] = $row['description'];

			try {
				CertProvision::create($params);
				$created++;
			}
			catch (\Exception $e) {
				// One failing line must never abort the rest of the import.
				$failed++;
				error(_s('Line %1$s (%2$s) failed: %3$s', (string) $row['line'], $row['raw'],
					$e->getMessage()
				));
			}
		}

		$summary = _s('Import finished: %1$s created, %2$s skipped, %3$s failed.', (string) $created,
			(string) $skipped, (string) $failed
		);

		if ($failed > 0 || $skipped > 0) {
			// Zabbix shows the collected error() messages under this title, so every reason stays visible.
			CMessageHelper::setErrorTitle($summary);

			$this->setResponse($this->makeErrorResponse());

			return;
		}

		CMessageHelper::setSuccessTitle($summary);

		$this->setResponse(new CControllerResponseRedirect(
			(new CUrl('zabbix.php'))->setArgument('action', 'certmonitor.list')
		));
	}

	/**
	 * Build a redirect back to the import form, keeping the submitted list so nothing has to be typed
	 * again.
	 *
	 * @return CControllerResponseRedirect
	 */
	private function makeErrorResponse(): CControllerResponseRedirect {
		$response = new CControllerResponseRedirect(
			(new CUrl('zabbix.php'))->setArgument('action', 'certmonitor.import')
		);

		$response->setFormData([
			'import_text' => (string) $this->getInput('import_text', ''),
			'preview' => 1
		]);

		if (CMessageHelper::getTitle() === null) {
			CMessageHelper::setErrorTitle(_('Cannot import websites'));
		}

		return $response;
	}
}
