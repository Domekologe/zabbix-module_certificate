<?php
/*
 * Certificate Monitor - list of monitored websites.
 *
 * This file is only an error boundary. The actual page is built in certmonitor.list.body.php.
 *
 * Why: a fatal error while building a Zabbix view produces a bare HTTP 500 with no hint of what
 * went wrong, which makes the page unusable and undiagnosable at the same time. PHP 8 lets us
 * catch Error as well as Exception, so the page can degrade into a readable message that names the
 * cause, the file and the line instead.
 *
 * No declare(strict_types = 1) on purpose: Zabbix core view helpers run without strict types.
 *
 * @var CView $this
 * @var array $data
 *
 * Author: Domekologe <support@domekologe.eu>
 */

$failure = null;

if (array_key_exists('fatal', $data)) {
	// The controller already failed, so there is no data set to render at all.
	$failure = [$data['fatal'], $data['fatal_origin']];
}
else {
	try {
		require __DIR__.'/certmonitor.list.body.php';
	}
	catch (Throwable $e) {
		$failure = [get_class($e).': '.$e->getMessage(), $e->getFile().':'.$e->getLine()];
	}
}

if ($failure !== null) {
	$details = (new CDiv([
		(new CDiv(_('The certificate list could not be rendered.')))->addClass(ZBX_STYLE_RED),
		(new CDiv($failure[0]))->addClass('certmonitor-mono'),
		(new CDiv($failure[1]))->addClass('certmonitor-mono'),
		new CDiv(_('The monitored hosts, items and triggers are unaffected and keep collecting data.'))
	]))->addClass('certmonitor-error');

	(new CHtmlPage())
		->setTitle(_('Certificates'))
		->addItem($details)
		->addItem(
			(new CDiv(
				new CRedirectButton(_('Add website'),
					(new CUrl('zabbix.php'))->setArgument('action', 'certmonitor.edit')
				)
			))->addClass('certmonitor-actions')
		)
		->show();
}
