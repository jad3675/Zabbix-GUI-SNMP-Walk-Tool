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

$auth_protocol = (new CTag('select', true))
	->setId('snmpwalk-cred-authprotocol')
	->addClass('focusable');

foreach (\Modules\SnmpWalk\Includes\CHostContext::AUTH_PROTOCOLS as $index => $label) {
	$auth_protocol->addItem(new CTag('option', true, $label, ['value' => (string) $index]));
}

$priv_protocol = (new CTag('select', true))
	->setId('snmpwalk-cred-privprotocol')
	->addClass('focusable');

foreach (\Modules\SnmpWalk\Includes\CHostContext::PRIV_PROTOCOLS as $index => $label) {
	$priv_protocol->addItem(new CTag('option', true, $label, ['value' => (string) $index]));
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
		new CFormField([
			(new CDiv())->setId('snmpwalk-credentials')->addClass(ZBX_STYLE_GREY),
			(new CCheckBox('cred_override'))
				->setId('snmpwalk-cred-override')
				->setLabel(_('Supply credentials for this walk')),
			(new CDiv(_('Used for this walk only. Not saved to the host, not written to the snapshot, not kept after you leave the page.')))
				->addClass(ZBX_STYLE_GREY)
		])
	])
	->addItem(
		(new CFormField(
			(new CDiv([
				(new CDiv([
					new CLabel(_('Version'), 'snmpwalk-cred-version'),
					(new CTag('select', true))
						->setId('snmpwalk-cred-version')
						->addClass('focusable')
						->addItem(new CTag('option', true, 'SNMPv2c', ['value' => (string) SNMP_V2C]))
						->addItem(new CTag('option', true, 'SNMPv1', ['value' => (string) SNMP_V1]))
						->addItem(new CTag('option', true, 'SNMPv3', ['value' => (string) SNMP_V3]))
				]))->addClass('snmpwalk-cred-row'),

				(new CDiv([
					new CLabel(_('Community'), 'snmpwalk-cred-community'),
					(new CPassBox('cred_community'))
						->setId('snmpwalk-cred-community')
						->addStyle('width: '.ZBX_TEXTAREA_SMALL_WIDTH.'px;')
						->setAttribute('autocomplete', 'new-password')
				]))->setId('snmpwalk-cred-v2')->addClass('snmpwalk-cred-row'),

				(new CDiv([
					(new CDiv([
						new CLabel(_('Security name'), 'snmpwalk-cred-securityname'),
						(new CTextBox('cred_securityname'))
							->setId('snmpwalk-cred-securityname')
							->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)
							->setAttribute('autocomplete', 'off')
					]))->addClass('snmpwalk-cred-row'),
					(new CDiv([
						new CLabel(_('Security level'), 'snmpwalk-cred-securitylevel'),
						(new CTag('select', true))
							->setId('snmpwalk-cred-securitylevel')
							->addClass('focusable')
							->addItem(new CTag('option', true, 'authPriv',
								['value' => (string) ITEM_SNMPV3_SECURITYLEVEL_AUTHPRIV]))
							->addItem(new CTag('option', true, 'authNoPriv',
								['value' => (string) ITEM_SNMPV3_SECURITYLEVEL_AUTHNOPRIV]))
							->addItem(new CTag('option', true, 'noAuthNoPriv',
								['value' => (string) ITEM_SNMPV3_SECURITYLEVEL_NOAUTHNOPRIV]))
					]))->addClass('snmpwalk-cred-row'),
					(new CDiv([
						new CLabel(_('Authentication'), 'snmpwalk-cred-authprotocol'),
						$auth_protocol,
						(new CPassBox('cred_authpassphrase'))
							->setId('snmpwalk-cred-authpassphrase')
							->addStyle('width: '.ZBX_TEXTAREA_SMALL_WIDTH.'px;')
							->setAttribute('placeholder', _('passphrase'))
							->setAttribute('autocomplete', 'new-password')
					]))->setId('snmpwalk-cred-auth')->addClass('snmpwalk-cred-row'),
					(new CDiv([
						new CLabel(_('Privacy'), 'snmpwalk-cred-privprotocol'),
						$priv_protocol,
						(new CPassBox('cred_privpassphrase'))
							->setId('snmpwalk-cred-privpassphrase')
							->addStyle('width: '.ZBX_TEXTAREA_SMALL_WIDTH.'px;')
							->setAttribute('placeholder', _('passphrase'))
							->setAttribute('autocomplete', 'new-password')
					]))->setId('snmpwalk-cred-priv')->addClass('snmpwalk-cred-row'),
					(new CDiv([
						new CLabel(_('Context name'), 'snmpwalk-cred-contextname'),
						(new CTextBox('cred_contextname'))
							->setId('snmpwalk-cred-contextname')
							->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)
							->setAttribute('autocomplete', 'off')
					]))->addClass('snmpwalk-cred-row')
				]))->setId('snmpwalk-cred-v3')
			]))->setId('snmpwalk-cred-fields')->addStyle('display: none;')
		))->addClass('snmpwalk-cred-fields-field')
	);

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
