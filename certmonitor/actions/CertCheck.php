<?php declare(strict_types = 1);
/**
 * Certificate Monitor - AJAX endpoint behind the "Test connection" button of the add/edit form.
 *
 * Uses the "layout.json" layout with no view, exactly like the built-in AJAX controllers (for example
 * CControllerItemExecuteNow): the controller writes the JSON document into $data['main_block'] and
 * ui/app/views/layout.json.php echoes it with the correct Content-Type.
 *
 * The probe runs in the FRONTEND process. See the caveat in CertProbe: the Zabbix agent that will
 * actually poll the certificate may sit in a different network segment, so a failure here is a warning
 * and never a reason to refuse the entry.
 *
 * Author: Domekologe <support@domekologe.eu>
 */

namespace Modules\CertMonitor\Actions;

use CController;
use CControllerResponseData;
use CMessageHelper;
use CRoleHelper;
use Modules\CertMonitor\Includes\CertHelper;
use Modules\CertMonitor\Includes\CertProbe;

class CertCheck extends CController {

	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	protected function checkInput(): bool {
		$fields = [
			'hostname' =>	'required|string|not_empty',
			'port' =>		'required|int32|ge 1|le 65535',
			'address' =>	'string'
		];

		$ret = $this->validateInput($fields);

		if ($ret) {
			$hostname = trim((string) $this->getInput('hostname'));
			$address = trim((string) $this->getInput('address', ''));

			if (!CertHelper::isValidHostname($hostname)) {
				error(_s('Incorrect value for field "%1$s": %2$s.', _('Hostname/FQDN'),
					_('a valid DNS name or IP address is expected')
				));
				$ret = false;
			}

			if ($ret && $address !== '' && !CertHelper::isValidHostname($address)) {
				error(_s('Incorrect value for field "%1$s": %2$s.', _('IP/address override'),
					_('a valid DNS name or IP address is expected')
				));
				$ret = false;
			}
		}

		if (!$ret) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => [
					'title' => CMessageHelper::getTitle() ?? _('Cannot test the connection'),
					'messages' => array_column(get_and_clear_messages(), 'message')
				]
			])]));
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		// The same permission that is required to create or edit an entry.
		return $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN
			&& $this->checkAccess(CRoleHelper::UI_CONFIGURATION_HOSTS);
	}

	protected function doAction(): void {
		$hostname = trim((string) $this->getInput('hostname'));
		$port = (int) $this->getInput('port');
		$address = trim((string) $this->getInput('address', ''));

		$result = CertProbe::probe($hostname, $port, $address);

		$this->setResponse(new CControllerResponseData([
			'main_block' => json_encode(['result' => $this->present($result)])
		]));
	}

	/**
	 * Turn the raw probe result into a flat, already translated and already formatted structure that the
	 * JavaScript side only has to print.
	 *
	 * Keeping all translation and date formatting on the PHP side means the browser code needs no
	 * translation strings and no knowledge of the user's date format.
	 *
	 * @param array $result
	 *
	 * @return array
	 */
	private function present(array $result): array {
		$target = CertHelper::makeTarget($result['hostname'], $result['port']);

		if ($result['address'] !== '') {
			$target .= ' '._s('(connecting to %1$s)', $result['address']);
		}

		if (!$result['ok']) {
			return [
				'ok' => false,
				'target' => $target,
				'summary' => _s('No certificate could be read from %1$s.', $target),
				'error' => $result['error'],
				'rows' => $result['resolved']
					? [[_('Resolved addresses'), implode(', ', $result['resolved'])]]
					: []
			];
		}

		$rows = [];

		if ($result['resolved']) {
			$rows[] = [_('Resolved addresses'), implode(', ', $result['resolved'])];
		}

		$rows[] = [_('Subject'), $result['subject_cn'] !== '' ? $result['subject_cn'] : _('unknown')];
		$rows[] = [_('Issuer'), $result['issuer_cn'] !== '' ? $result['issuer_cn'] : _('unknown')];

		if ($result['not_before'] !== null) {
			$rows[] = [_('Valid from'), zbx_date2str(DATE_TIME_FORMAT, $result['not_before'])];
		}

		if ($result['not_after'] !== null) {
			$rows[] = [_('Valid until'), zbx_date2str(DATE_TIME_FORMAT, $result['not_after'])];
		}

		if ($result['days_left'] !== null) {
			$rows[] = [_('Days remaining'), $result['days_left'] < 0
				? _('expired')
				: (string) $result['days_left']
			];
		}

		if ($result['alternative_names']) {
			$rows[] = [_('Subject alternative names'), implode(', ', $result['alternative_names'])];
		}

		if ($result['self_signed']) {
			$rows[] = [_('Self-signed'), _('yes')];
		}

		$rows[] = [_('Chain verification'), $result['verified']
			? _('successful')
			: _s('failed: %1$s', $result['verify_error'] !== ''
				? $result['verify_error']
				: _('the certificate is not trusted by the frontend server')
			)
		];

		$expired = $result['days_left'] !== null && $result['days_left'] < 0;

		return [
			'ok' => true,
			'target' => $target,
			'warning' => !$result['verified'] || $expired,
			'summary' => $expired
				? _s('A certificate was read from %1$s, but it has already expired.', $target)
				: _s('A certificate was read from %1$s.', $target),
			'error' => '',
			'rows' => $rows
		];
	}
}
