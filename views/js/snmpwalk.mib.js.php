<?php declare(strict_types = 1);
/**
 * @var CView $this
 */
?>
window.snmpwalk_mibs = new class {

	init(options) {
		this.uploaded = options.uploaded;
		this.writable = options.writable;

		document.getElementById('snmpwalk-mib-upload')
			.addEventListener('click', () => this.#upload());
		document.getElementById('snmpwalk-mib-reindex')
			.addEventListener('click', () => this.#reindex());

		this.#render();
	}

	async #post(action, body) {
		const url = new Curl('zabbix.php');
		url.setArgument('action', action);

		const response = await fetch(url.getUrl(), {method: 'POST', body});
		const text = await response.text();

		let data;

		try {
			data = JSON.parse(text);
		}
		catch (e) {
			console.error('SNMP walk MIBs: non-JSON response from ' + action, text);

			throw new Error(response.ok
				? <?= json_encode(_('The server returned something that is not JSON: ')) ?> + text.slice(0, 300)
				: response.status + ' ' + response.statusText + ' — ' + text.slice(0, 300));
		}

		if (data.error) {
			console.error('SNMP walk MIBs: error from ' + action, data.error);

			const error = data.error;

			throw new Error(typeof error === 'string'
				? error
				: [error.title, ...(error.messages || [])].filter(Boolean).join(' ')
					|| JSON.stringify(error));
		}

		return data;
	}

	async #upload() {
		const input = document.getElementById('snmpwalk-mib-file');

		if (input.files.length === 0) {
			this.#message('warning', <?= json_encode(_('Choose one or more MIB files first.')) ?>);
			return;
		}

		const form = new FormData();

		for (const file of input.files) {
			form.append('mib[]', file);
		}

		this.#message('', <?= json_encode(_('Uploading and reindexing…')) ?>);

		try {
			const data = await this.#post('snmpwalk.mib.upload', form);

			this.uploaded = data.uploaded;
			this.#render();

			const parts = [];

			if (data.stored.length > 0) {
				parts.push(data.stored.length + <?= json_encode(_(' stored')) ?>);
			}

			if (data.reindexed && data.index) {
				parts.push(data.index.objects + <?= json_encode(_(' objects indexed')) ?>);
			}

			for (const rejected of data.rejected) {
				parts.push(rejected.name + ': ' + rejected.reason);
			}

			this.#message(data.rejected.length > 0 ? 'warning' : 'ok', parts.join(' · '));

			if (data.index && data.index.errors) {
				this.#detail(data.index.errors);
			}
		}
		catch (error) {
			this.#message('error', error.message);
		}
	}

	async #reindex() {
		this.#message('', <?= json_encode(_('Rebuilding…')) ?>);

		try {
			const data = await this.#post('snmpwalk.mib.reindex', new FormData());

			this.#message('ok', data.objects + <?= json_encode(_(' objects indexed.')) ?>);

			if (data.warnings) {
				this.#detail(data.warnings);
			}
		}
		catch (error) {
			this.#message('error', error.message);
		}
	}

	async #delete(name) {
		if (!confirm(<?= json_encode(_('Remove this MIB and rebuild the index?')) ?>)) {
			return;
		}

		const form = new FormData();
		form.append('name', name);

		try {
			const data = await this.#post('snmpwalk.mib.delete', form);
			this.uploaded = data.uploaded;
			this.#render();
			this.#message('ok', <?= json_encode(_('Removed.')) ?>);
		}
		catch (error) {
			this.#message('error', error.message);
		}
	}

	#render() {
		const container = document.getElementById('snmpwalk-mib-list');
		container.innerHTML = '';

		if (this.uploaded.length === 0) {
			const empty = document.createElement('div');
			empty.className = 'snmpwalk-empty';
			empty.textContent = <?= json_encode(_('No MIBs uploaded. The distribution MIBs on the frontend host are still used.')) ?>;
			container.appendChild(empty);
			return;
		}

		const table = document.createElement('table');
		table.className = 'list-table';
		table.innerHTML = '<thead><tr><th>' + <?= json_encode(_('Module')) ?> + '</th><th>'
			+ <?= json_encode(_('Size')) ?> + '</th><th>' + <?= json_encode(_('Uploaded')) ?>
			+ '</th><th></th></tr></thead>';

		const body = document.createElement('tbody');

		for (const file of this.uploaded) {
			const tr = document.createElement('tr');
			const name = document.createElement('td');
			name.textContent = file.name;

			const size = document.createElement('td');
			size.textContent = Math.round(file.size / 1024) + ' KB';

			const when = document.createElement('td');
			when.textContent = new Date(file.mtime * 1000).toLocaleString();

			const actions = document.createElement('td');

			if (this.writable) {
				const remove = document.createElement('button');
				remove.type = 'button';
				remove.className = 'btn-link';
				remove.textContent = <?= json_encode(_('Remove')) ?>;
				remove.addEventListener('click', () => this.#delete(file.name));
				actions.appendChild(remove);
			}

			tr.append(name, size, when, actions);
			body.appendChild(tr);
		}

		table.appendChild(body);
		container.appendChild(table);
	}

	#message(kind, text) {
		const status = document.getElementById('snmpwalk-mib-status');
		const classes = {ok: 'msg-good', warning: 'msg-warning', error: 'msg-bad'};

		status.className = 'snmpwalk-status ' + (classes[kind] || '');
		status.textContent = text;
	}

	#detail(text) {
		const status = document.getElementById('snmpwalk-mib-status');
		const pre = document.createElement('pre');
		pre.className = 'snmpwalk-warnings';
		pre.textContent = text;
		status.appendChild(pre);
	}
};
