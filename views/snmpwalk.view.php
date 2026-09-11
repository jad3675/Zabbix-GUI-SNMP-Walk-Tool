<?php declare(strict_types = 1);

/**
 * SNMP walk console.
 *
 * The page is a shell: the filter, an empty results area and the data the JavaScript
 * needs to start. Everything after first paint comes from the JSON actions, because a
 * walk arrives in chunks and the point of the whole exercise is watching it arrive.
 *
 * @var CView $this
 * @var array $data
 */

$form = (new CForm('get'))
	->setId('snmpwalk-form')
	->setName('snmpwalk_form')
	->addItem((new CVar('action', 'snmpwalk.view'))->removeId());

$engine_select = (new CSelect('engine'))
	->setId('snmpwalk-engine')
	->setValue('auto')
	->addOption(new CSelectOption('auto', _('Automatic')));

foreach ($data['engines'] as $key => $engine) {
	$option = new CSelectOption($key, $engine['label']);

	if (!$engine['usable']) {
		$option->setDisabled(true);
	}

	$engine_select->addOption($option);
}

$filter = (new CFormGrid())
	->addItem([
		(new CLabel(_('Host'), 'hostid_ms'))->setAsteriskMark(),
		new CFormField(
			(new CMultiSelect([
				'name' => 'hostid',
				'object_name' => 'hosts',
				'multiple' => false,
				'data' => $data['hostid'] !== ''
					? [['id' => $data['hostid'], 'name' => $data['host_name']]]
					: [],
				'popup' => [
					'parameters' => [
						'srctbl' => 'hosts',
						'srcfld1' => 'hostid',
						'dstfrm' => 'snmpwalk_form',
						'dstfld1' => 'hostid'
					]
				]
			]))->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH)
		)
	])
	->addItem([
		new CLabel(_('SNMP interface'), 'snmpwalk-interface'),
		new CFormField(
			// Deliberately a native select rather than CSelect: this one is populated
			// from JavaScript, and <z-select> owns its own option list.
			(new CTag('select', true))
				->setId('snmpwalk-interface')
				->setAttribute('name', 'interfaceid')
				->setAttribute('disabled', 'disabled')
				->addClass('focusable')
		)
	])
	->addItem([
		(new CLabel(_('Start OID'), 'snmpwalk-oid'))->setAsteriskMark(),
		new CFormField([
			(new CTextBox('oid', $data['oid']))
				->setId('snmpwalk-oid')
				->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH)
				->setAttribute('placeholder', '1.3.6.1.2.1  or  IF-MIB::ifTable'),
			(new CDiv(_('Walking a whole device can return tens of thousands of values. Start at a subtree when you know which one you want.')))
				->addClass(ZBX_STYLE_GREY)
		])
	])
	->addItem([
		new CLabel(_('Run from'), 'snmpwalk-engine'),
		new CFormField([
			$engine_select,
			(new CDiv())->setId('snmpwalk-engine-note')->addClass(ZBX_STYLE_GREY)
		])
	])
	->addItem([
		new CLabel(_('Credentials')),
		new CFormField((new CDiv())->setId('snmpwalk-credentials')->addClass(ZBX_STYLE_GREY))
	]);

$actions = (new CDiv([
	(new CSimpleButton(_('Run walk')))->setId('snmpwalk-run')->addClass(ZBX_STYLE_BTN_ALT),
	(new CSimpleButton(_('Stop')))->setId('snmpwalk-stop')->addClass(ZBX_STYLE_BTN_ALT)->setEnabled(false),
	(new CSimpleButton(_('Save snapshot')))->setId('snmpwalk-save')->addClass(ZBX_STYLE_BTN_ALT)->setEnabled(false),
	(new CSimpleButton(_('Download')))->setId('snmpwalk-download')->addClass(ZBX_STYLE_BTN_ALT)->setEnabled(false)
]))->addClass('snmpwalk-actions');

$form->addItem([$filter, $actions]);

$tabs = (new CList([
	(new CSimpleButton(_('Values')))->setId('snmpwalk-tab-values')->addClass('snmpwalk-tab'),
	(new CSimpleButton(_('Tables')))->setId('snmpwalk-tab-tables')->addClass('snmpwalk-tab'),
	(new CSimpleButton(_('Coverage')))->setId('snmpwalk-tab-coverage')->addClass('snmpwalk-tab'),
	(new CSimpleButton(_('Snapshots')))->setId('snmpwalk-tab-snapshots')->addClass('snmpwalk-tab'),
	(new CSimpleButton(_('Compare')))->setId('snmpwalk-tab-diff')->addClass('snmpwalk-tab')
]))->addClass('snmpwalk-tabs');

$toolbar = (new CDiv([
	(new CTextBox('search', ''))
		->setId('snmpwalk-search')
		->setAttribute('placeholder', _('Filter by OID, name or value'))
		->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH),
	(new CSelect('naming'))
		->setId('snmpwalk-naming')
		->setValue('both')
		->addOptions(CSelect::createOptionsFromArray([
			'both' => _('Name and numeric OID'),
			'name' => _('Translated name'),
			'numeric' => _('Numeric OID')
		])),
	(new CCheckBox('uncovered_only'))
		->setId('snmpwalk-uncovered-only')
		->setLabel(_('Not monitored only'))
		->setEnabled(false),
	(new CSimpleButton(_('Create items from selection')))
		->setId('snmpwalk-create-selected')
		->addClass(ZBX_STYLE_BTN_ALT)
		->setEnabled(false),
	(new CDiv())->setId('snmpwalk-selection-count')->addClass(ZBX_STYLE_GREY)
]))->addClass('snmpwalk-toolbar');

$html_page = (new CHtmlPage())
	->setTitle(_('SNMP walk'))
	->setDocUrl('https://www.zabbix.com/documentation/current/en/manual/config/items/itemtypes/snmp')
	->addItem((new CDiv($form))->addClass(ZBX_STYLE_FILTER_CONTAINER))
	->addItem((new CDiv())->setId('snmpwalk-status')->addClass('snmpwalk-status'))
	->addItem($tabs)
	->addItem($toolbar)
	->addItem((new CDiv())->setId('snmpwalk-panel')->addClass('snmpwalk-panel'));

if (!$data['mib']['indexed']) {
	$html_page->addItem(
		(new CDiv([
			_('No MIBs are indexed, so results will show numeric OIDs only.'), ' ',
			new CLink(_('Build the index'), (new CUrl('zabbix.php'))->setArgument('action', 'snmpwalk.mib.view'))
		]))->addClass(ZBX_STYLE_MSG_WARNING)
	);
}

$html_page->show();

(new CScriptTag($this->readJsFile('snmpwalk.view.js.php')))->show();

(new CScriptTag('snmpwalk_console.init('.json_encode([
	'hostid' => $data['hostid'],
	'oid' => $data['oid'],
	'snapshot' => $data['snapshot'],
	'chunk_size' => $data['chunk_size'],
	'max_varbinds' => $data['max_varbinds'],
	'engines' => $data['engines'],
	'mib' => $data['mib']
], JSON_THROW_ON_ERROR).');'))
	->setOnDocumentReady()
	->show();
