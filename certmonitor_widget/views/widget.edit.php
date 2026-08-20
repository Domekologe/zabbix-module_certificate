<?php
/*
 * NOTE: no declare(strict_types = 1) here on purpose. Zabbix core view files and the CView/CTag
 * helpers they call run without strict types, and passing a request string into a core function
 * that declares int would raise a TypeError - a fatal HTTP 500 - only in a strict file. The
 * controllers and helper classes of this module do use strict types.
 *
 * Author: Domekologe <support@domekologe.eu>
 */
/**
 * Certificate Monitor - configuration form view of the dashboard widget.
 *
 * Every CWidgetField* class has a matching CWidgetField*View class that renders it; the field objects
 * themselves arrive in $data['fields'], keyed by the field name used in WidgetForm::addFields().
 *
 * @var CView $this
 * @var array $data
 */

$form = new CWidgetFormView($data);

$form
	->addField(array_key_exists('groupids', $data['fields'])
		? new CWidgetFieldMultiSelectGroupView($data['fields']['groupids'])
		: null
	)
	->addField(
		new CWidgetFieldIntegerBoxView($data['fields']['show_lines'])
	)
	->show();
