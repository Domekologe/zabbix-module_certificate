<?php declare(strict_types = 1);
/**
 * Certificate Monitor - disables monitoring of the selected websites.
 *
 * Author: Domekologe <support@domekologe.eu>
 */

namespace Modules\CertMonitor\Actions;

class CertDisable extends CertStatusUpdate {

	protected function getTargetStatus(): int {
		return HOST_STATUS_NOT_MONITORED;
	}
}
