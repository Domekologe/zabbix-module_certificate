<?php declare(strict_types = 1);
/**
 * Certificate Monitor - enables monitoring of the selected websites.
 *
 * Author: Domekologe <support@domekologe.eu>
 */

namespace Modules\CertMonitor\Actions;

class CertEnable extends CertStatusUpdate {

	protected function getTargetStatus(): int {
		return HOST_STATUS_MONITORED;
	}
}
