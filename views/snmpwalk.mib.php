<?php declare(strict_types = 1);

/**
 * MIB library.
 *
 * @var CView $this
 * @var array $data
 */

$status = $data['indexed']
	? _s('%1$s objects indexed, last built %2$s', $data['objects'],
		zbx_date2str(DATE_TIME_FORMAT_SECONDS, $data['built']))
	: _('Not indexed yet. Nothing will be translated until you build the index.');

$summary = (new CFormGrid())
	->addItem([
		new CLabel(_('Index')),
		new CFormField((new CDiv($status))->addClass($data['indexed'] ? '' : ZBX_STYLE_RED))
	])
	->addItem([
		new CLabel(_('Search path')),
		new CFormField(
			(new CDiv(implode(', ', array_merge($data['system_dirs'], [$data['upload_dir']]))))
				->addClass(ZBX_STYLE_GREY)
		)
	])
	->addItem([
		new CLabel(_('Translator')),
		new CFormField((new CDiv($data['binary']))->addClass(ZBX_STYLE_GREY))
	]);

$upload = (new CDiv([
	(new CFile('mib[]'))->setId('snmpwalk-mib-file')->setAttribute('multiple', 'multiple'),
	(new CSimpleButton(_('Upload MIBs')))->setId('snmpwalk-mib-upload')->addClass(ZBX_STYLE_BTN_ALT),
	(new CSimpleButton(_('Rebuild index')))->setId('snmpwalk-mib-reindex')->addClass(ZBX_STYLE_BTN_ALT)
]))->addClass('snmpwalk-actions');

$html_page = (new CHtmlPage())
	->setTitle(_('SNMP walk: MIBs'))
	->addItem((new CDiv([
		_('Vendor MIBs uploaded here are used to translate walk results in the console. They are read by the frontend only; the Zabbix server keeps its own MIB directory for item collection.')
	]))->addClass(ZBX_STYLE_GREY))
	->addItem($summary);

if (!$data['writable']) {
	$html_page->addItem(
		(new CDiv(_s('The upload directory %1$s is not writable by the web server user, so uploads will fail.',
			$data['upload_dir'])))->addClass(ZBX_STYLE_MSG_WARNING)
	);
}

$html_page
	->addItem($upload)
	->addItem((new CDiv())->setId('snmpwalk-mib-status')->addClass('snmpwalk-status'))
	->addItem((new CDiv())->setId('snmpwalk-mib-list'))
	->show();

(new CScriptTag($this->readJsFile('snmpwalk.mib.js.php')))->show();

(new CScriptTag('snmpwalk_mibs.init('.json_encode([
	'uploaded' => $data['uploaded'],
	'writable' => $data['writable']
], JSON_THROW_ON_ERROR).');'))
	->setOnDocumentReady()
	->show();
