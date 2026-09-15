<?php declare(strict_types = 1);
/**
 * @var CView $this
 */
?>
window.snmpwalk_console = new class {

	init(options) {
		console.info('SNMP walk module build 1.5.2');
		this.options = options;
		this.rows = [];
		this.token = null;
		this.running = false;
		this.aborted = false;
		this.coverage = null;
		this.analysis = null;
		this.meta = {};
		this.tab = 'values';
		this.snapshots = [];
		this.selected = new Set();
		this.targets = null;

		this.form = document.getElementById('snmpwalk-form');
		this.panel = document.getElementById('snmpwalk-panel');
		this.status = document.getElementById('snmpwalk-status');
		this.search = document.getElementById('snmpwalk-search');

		this.#bind();

		if (options.hostid) {
			this.#loadContext(options.hostid);
		}
	}

	#bind() {
		const host_field = document.getElementById('hostid');

		if (host_field !== null) {
			jQuery('#hostid').on('change', () => {
				const selected = jQuery('#hostid').multiSelect('getData');
				this.#loadContext(selected.length > 0 ? selected[0].id : null);
			});
		}

		document.getElementById('snmpwalk-run').addEventListener('click', () => this.#run());
		document.getElementById('snmpwalk-stop').addEventListener('click', () => { this.aborted = true; });
		document.getElementById('snmpwalk-save').addEventListener('click', () => this.#saveSnapshot());
		document.getElementById('snmpwalk-download').addEventListener('click', (e) => this.#download(e));

		for (const tab of ['values', 'tables', 'coverage', 'snapshots', 'diff']) {
			document.getElementById('snmpwalk-tab-' + tab)
				.addEventListener('click', () => this.#showTab(tab));
		}

		this.search.addEventListener('input', () => {
			if (this.tab === 'values') {
				this.#renderValues();
			}
		});

		document.getElementById('snmpwalk-naming').addEventListener('change', () => this.#renderValues());
		document.getElementById('snmpwalk-create-selected')
			.addEventListener('click', () => this.#previewSelection());
		document.getElementById('snmpwalk-uncovered-only').addEventListener('change', () => this.#renderValues());

		document.getElementById('snmpwalk-oid').addEventListener('keydown', (e) => {
			if (e.key === 'Enter') {
				e.preventDefault();
				this.#run();
			}
		});

		this.#showTab('values');
	}

	// ---------------------------------------------------------------- transport

	async #post(action, params, form_data = null) {
		const url = new Curl('zabbix.php');
		url.setArgument('action', action);

		let body;

		if (form_data !== null) {
			body = form_data;
		}
		else {
			body = new URLSearchParams();

			for (const [key, value] of Object.entries(params)) {
				if (value === null || value === undefined) {
					continue;
				}

				if (Array.isArray(value)) {
					value.forEach((v) => body.append(key + '[]', v));
				}
				else {
					body.append(key, value);
				}
			}
		}

		const response = await fetch(url.getUrl(), {method: 'POST', body});
		const text = await response.text();

		let data;

		try {
			data = JSON.parse(text);
		}
		catch (e) {
			// A PHP fatal or a login redirect comes back as HTML, not JSON. Showing the
			// first part of it beats "Unexpected token < in JSON".
			console.error('SNMP walk: non-JSON response from ' + action, text);

			throw new Error(response.ok
				? <?= json_encode(_('The server returned something that is not JSON: ')) ?> + text.slice(0, 300)
				: response.status + ' ' + response.statusText + ' — ' + text.slice(0, 300));
		}

		if (data.error) {
			console.error('SNMP walk: error from ' + action, data.error);

			throw new Error(this.#errorText(data.error));
		}

		if (!response.ok) {
			throw new Error(response.status + ' ' + response.statusText);
		}

		return data;
	}

	/**
	 * The selected interface, or undefined when nothing is selected yet. Posting an
	 * empty string fails validation; omitting the field lets the controller fall back
	 * to the host's main SNMP interface, which is what an empty selection means.
	 */
	#interfaceId() {
		const value = document.getElementById('snmpwalk-interface').value;

		return /^[0-9]+$/.test(value) ? value : undefined;
	}

	/**
	 * Errors arrive in two shapes: a plain string from this module's own actions, and
	 * Zabbix's {title, messages} object when the request is rejected before the action
	 * runs, by input validation or a permission check. Rendering the second one with
	 * string concatenation is where "[object Object]" comes from.
	 */
	#errorText(error) {
		if (typeof error === 'string') {
			return error;
		}

		if (error && typeof error === 'object') {
			const parts = [];

			if (error.title) {
				parts.push(error.title);
			}

			for (const message of error.messages || []) {
				parts.push(typeof message === 'string' ? message : JSON.stringify(message));
			}

			if (parts.length > 0) {
				return parts.join(' ');
			}
		}

		return JSON.stringify(error);
	}

	// ---------------------------------------------------------------- context

	async #loadContext(hostid) {
		this.hostid = hostid;

		const interfaces = document.getElementById('snmpwalk-interface');
		const credentials = document.getElementById('snmpwalk-credentials');
		const note = document.getElementById('snmpwalk-engine-note');

		if (!hostid) {
			interfaces.disabled = true;
			credentials.textContent = '';
			note.textContent = '';
			return;
		}

		try {
			const data = await this.#post('snmpwalk.context', {hostid});

			interfaces.disabled = false;
			interfaces.innerHTML = '';

			for (const iface of data.interfaces) {
				const option = document.createElement('option');
				option.value = iface.interfaceid;
				option.textContent = iface.label;
				option.selected = iface.interfaceid === data.interfaceid;
				interfaces.appendChild(option);
			}

			const host = data.host;
			const bits = [host.address + ':' + host.port, 'SNMPv' + host.version];

			if (host.version === '3') {
				bits.push(<?= json_encode(_('user')) ?> + ' ' + host.security_name, host.security_level);
			}
			else {
				bits.push(<?= json_encode(_('community')) ?> + ' ' + host.community);
			}

			if (host.proxy) {
				bits.push(<?= json_encode(_('via proxy')) ?> + ' ' + host.proxy);
			}

			// A v3 host shows no credential field above, so without this the only
			// symptom of an unreadable macro would be the engine note.
			if (host.unresolved_macros && host.unresolved_macros.length > 0) {
				bits.push(<?= json_encode(_('unresolved')) ?> + ' ' + host.unresolved_macros.join(', '));
			}

			credentials.textContent = bits.join(' · ');

			this.engines = data.engines;
			this.writable = host.writable;
			this.snapshots = data.snapshots;
			this.#describeEngine();

			if (this.tab === 'snapshots') {
				this.#renderSnapshots();
			}
		}
		catch (error) {
			this.#message('error', error.message);
		}
	}

	#describeEngine() {
		const select = document.getElementById('snmpwalk-engine');
		const note = document.getElementById('snmpwalk-engine-note');
		const chosen = select.value;

		if (!this.engines) {
			note.textContent = '';
			return;
		}

		if (chosen === 'auto') {
			const unusable = Object.entries(this.engines)
				.filter(([, engine]) => !engine.usable)
				.map(([, engine]) => engine.label + ': ' + engine.reason);

			note.textContent = unusable.length > 0
				? <?= json_encode(_('Unavailable here')) ?> + ' — ' + unusable.join('; ')
				: '';
			return;
		}

		const engine = this.engines[chosen];

		if (!engine.usable) {
			note.textContent = engine.reason;

			return;
		}

		const parts = [engine.resumable
			? <?= json_encode(_('Resumable, so large walks stream in with progress.')) ?>
			: <?= json_encode(_('Runs in one request, so there is no progress until it finishes.')) ?>];

		if (engine.caveat) {
			parts.push(engine.caveat);
		}

		note.textContent = parts.join(' ');
	}

	// ---------------------------------------------------------------- walking

	async #run() {
		if (this.running) {
			return;
		}

		const selected = jQuery('#hostid').multiSelect('getData');

		if (selected.length === 0) {
			this.#message('error', <?= json_encode(_('Select a host first.')) ?>);
			return;
		}

		this.hostid = selected[0].id;
		this.rows = [];
		this.token = null;
		this.aborted = false;
		this.running = true;
		this.selected.clear();
		this.coverage = null;
		this.analysis = null;

		document.getElementById('snmpwalk-run').disabled = true;
		document.getElementById('snmpwalk-stop').disabled = false;
		document.getElementById('snmpwalk-uncovered-only').disabled = true;
		document.getElementById('snmpwalk-uncovered-only').checked = false;

		this.#showTab('values');

		const started = Date.now();
		let cursor = null;
		let notices = [];

		try {
			do {
				const data = await this.#post('snmpwalk.run', {
					hostid: this.hostid,
					interfaceid: this.#interfaceId(),
					oid: document.getElementById('snmpwalk-oid').value,
					engine: document.getElementById('snmpwalk-engine').value,
					cursor,
					token: this.token
				});

				this.token = data.token;
				this.rows = this.rows.concat(data.rows);
				this.meta = {
					engine: data.engine,
					origin: data.origin,
					root: data.root,
					resumable: data.resumable
				};

				notices = notices.concat(data.notices || []);
				cursor = data.done ? null : data.cursor;

				this.#renderValues();
				this.#progress(started, data.done);
			}
			while (cursor !== null && !this.aborted);

			if (this.aborted) {
				notices.push(<?= json_encode(_('Stopped at your request; the values collected so far are kept.')) ?>);
			}

			this.#finish(started, notices, null);
		}
		catch (error) {
			// #finish() reports the outcome, so handing it the failure is the only way
			// the failure survives. Calling #message() here as well simply overwrote
			// the real error with the generic empty-walk line.
			this.#finish(started, notices, error);
		}
	}

	#progress(started, done) {
		const seconds = ((Date.now() - started) / 1000).toFixed(1);

		this.status.className = 'snmpwalk-status snmpwalk-status-running';
		this.status.textContent = done
			? ''
			: this.rows.length + <?= json_encode(_(' values in ')) ?> + seconds + 's…';
	}

	#finish(started, notices, failure) {
		this.running = false;
		document.getElementById('snmpwalk-run').disabled = false;
		document.getElementById('snmpwalk-stop').disabled = true;
		document.getElementById('snmpwalk-save').disabled = this.rows.length === 0;
		document.getElementById('snmpwalk-download').disabled = this.rows.length === 0;

		const seconds = ((Date.now() - started) / 1000).toFixed(1);

		if (failure) {
			console.error('SNMP walk failed', failure);
			this.#message('error', failure.message, notices);
			return;
		}

		if (this.rows.length === 0) {
			this.#message('warning',
				<?= json_encode(_('No values came back from ')) ?>
					+ (this.meta.origin || <?= json_encode(_('the engine')) ?>)
					+ <?= json_encode(_(' for .')) ?> + (this.meta.root || '')
					+ <?= json_encode(_('. The device answered without an error but returned nothing in that subtree.')) ?>,
				notices
			);
			return;
		}

		const parts = [
			this.rows.length + <?= json_encode(_(' values from ')) ?> + this.meta.origin,
			seconds + 's'
		];

		this.#message('ok', parts.join(' · '), notices);
	}

	#message(kind, text, notices = []) {
		const classes = {
			ok: 'msg-good',
			warning: 'msg-warning',
			error: 'msg-bad'
		};

		this.status.className = 'snmpwalk-status ' + (classes[kind] || '');
		this.status.textContent = text;

		if (notices.length > 0) {
			const list = document.createElement('ul');

			for (const notice of notices) {
				const item = document.createElement('li');
				item.textContent = notice;
				list.appendChild(item);
			}

			this.status.appendChild(list);
		}
	}

	// ---------------------------------------------------------------- tabs

	#showTab(tab) {
		this.tab = tab;

		for (const name of ['values', 'tables', 'coverage', 'snapshots', 'diff']) {
			document.getElementById('snmpwalk-tab-' + name)
				.classList.toggle('selected', name === tab);
		}

		document.querySelector('.snmpwalk-toolbar').style.display = tab === 'values' ? '' : 'none';

		switch (tab) {
			case 'values': this.#renderValues(); break;
			case 'tables': this.#renderTables(); break;
			case 'coverage': this.#renderCoverage(); break;
			case 'snapshots': this.#renderSnapshots(); break;
			case 'diff': this.#renderDiff(); break;
		}
	}

	// ---------------------------------------------------------------- values

	#visibleRows() {
		const needle = this.search.value.trim().toLowerCase();
		const uncovered_only = document.getElementById('snmpwalk-uncovered-only').checked;

		return this.rows.filter((row) => {
			if (uncovered_only && this.coverage && !this.coverage.uncovered_set.has(row.oid)) {
				return false;
			}

			if (needle === '') {
				return true;
			}

			return row.oid.includes(needle)
				|| (row.name || '').toLowerCase().includes(needle)
				|| (row.value || '').toLowerCase().includes(needle);
		});
	}

	#renderValues() {
		const rows = this.#visibleRows();
		const naming = document.getElementById('snmpwalk-naming').value;

		if (this.rows.length === 0) {
			this.panel.innerHTML = '';
			this.panel.appendChild(this.#empty(
				<?= json_encode(_('Pick a host, choose where to start, and run a walk.')) ?>
			));
			return;
		}

		const limit = 5000;
		const table = document.createElement('table');
		table.className = 'list-table snmpwalk-values';
		const head = document.createElement('thead');
		const head_row = document.createElement('tr');

		const select_all_cell = document.createElement('th');
		select_all_cell.style.width = '2%';

		if (this.writable) {
			const select_all = document.createElement('input');
			select_all.type = 'checkbox';
			select_all.title = <?= json_encode(_('Select everything currently listed')) ?>;
			select_all.addEventListener('change', () => {
				for (const row of this.#visibleRows().slice(0, limit)) {
					if (select_all.checked) {
						this.selected.add(row.oid);
					}
					else {
						this.selected.delete(row.oid);
					}
				}

				this.#renderValues();
			});
			select_all_cell.appendChild(select_all);
		}

		head_row.appendChild(select_all_cell);

		for (const [label, width] of [
			[<?= json_encode(_('Object')) ?>, '32%'],
			[<?= json_encode(_('Type')) ?>, '10%'],
			[<?= json_encode(_('Value')) ?>, ''],
			['', '8%']
		]) {
			const cell = document.createElement('th');
			cell.textContent = label;

			if (width !== '') {
				cell.style.width = width;
			}

			head_row.appendChild(cell);
		}

		head.appendChild(head_row);
		table.appendChild(head);

		const body = document.createElement('tbody');

		rows.slice(0, limit).forEach((row) => {
			const tr = document.createElement('tr');

			if (this.coverage) {
				tr.classList.add(this.coverage.uncovered_set.has(row.oid)
					? 'snmpwalk-uncovered'
					: 'snmpwalk-covered');
			}

			const pick = document.createElement('td');

			if (this.writable) {
				const box = document.createElement('input');
				box.type = 'checkbox';
				box.checked = this.selected.has(row.oid);
				box.addEventListener('change', () => {
					if (box.checked) {
						this.selected.add(row.oid);
					}
					else {
						this.selected.delete(row.oid);
					}

					this.#updateSelectionCount();
				});
				pick.appendChild(box);
			}

			const object = document.createElement('td');
			object.className = 'snmpwalk-object';

			if (naming !== 'numeric' && row.name) {
				const name = document.createElement('div');
				name.textContent = (row.mib ? row.mib + '::' : '') + row.name;
				object.appendChild(name);
			}

			if (naming !== 'name' || !row.name) {
				const oid = document.createElement('div');
				oid.className = 'snmpwalk-oid';
				oid.textContent = '.' + row.oid;
				object.appendChild(oid);
			}

			const type = document.createElement('td');
			type.textContent = row.type;

			const value = document.createElement('td');
			value.className = 'snmpwalk-value';
			value.textContent = row.value;

			const action = document.createElement('td');

			if (this.writable) {
				const button = document.createElement('button');
				button.type = 'button';
				button.className = 'btn-link';
				button.textContent = <?= json_encode(_('Create item')) ?>;
				button.addEventListener('click', () => this.#previewItem(row));
				action.appendChild(button);
			}

			tr.append(pick, object, type, value, action);
			body.appendChild(tr);
		});

		table.appendChild(body);

		this.panel.innerHTML = '';
		this.panel.appendChild(table);
		this.#updateSelectionCount();

		if (rows.length > limit) {
			this.panel.appendChild(this.#note(
				<?= json_encode(_('Showing the first 5000 matches. Narrow the filter, or download the full walk.')) ?>
			));
		}
	}

	// ---------------------------------------------------------------- tables

	async #renderTables() {
		if (this.rows.length === 0) {
			this.panel.innerHTML = '';
			this.panel.appendChild(this.#empty(<?= json_encode(_('Run a walk first.')) ?>));
			return;
		}

		if (this.analysis === null) {
			this.panel.innerHTML = '';
			this.panel.appendChild(this.#note(<?= json_encode(_('Working out the shape of this walk…')) ?>));

			try {
				this.analysis = await this.#post('snmpwalk.analyze', {token: this.token});
			}
			catch (error) {
				this.#message('error', error.message);
				return;
			}
		}

		this.panel.innerHTML = '';

		if (!this.analysis.mib_loaded) {
			this.panel.appendChild(this.#note(
				<?= json_encode(_('No MIBs are indexed, so tables were detected from OID structure alone. Multi-component indexes will be wrong.')) ?>
			));
		}

		if (this.analysis.tables.length === 0) {
			this.panel.appendChild(this.#empty(<?= json_encode(_('No tables in this walk, only scalars.')) ?>));
			return;
		}

		for (const table of this.analysis.tables) {
			this.panel.appendChild(this.#tableCard(table));
		}
	}

	#tableCard(table) {
		const card = document.createElement('div');
		card.className = 'snmpwalk-card';

		const title = document.createElement('h4');
		title.textContent = table.name || ('.' + table.entry_oid);
		card.appendChild(title);

		const meta = document.createElement('div');
		meta.className = 'snmpwalk-card-meta';
		meta.textContent = [
			table.row_count + <?= json_encode(_(' rows')) ?>,
			table.columns.length + <?= json_encode(_(' columns')) ?>,
			table.index_objects.length > 0
				? <?= json_encode(_('indexed by ')) ?> + table.index_objects.join(', ')
				: <?= json_encode(_('index inferred from OID structure')) ?>
		].join(' · ');
		card.appendChild(meta);

		if (table.confidence === 'low') {
			card.appendChild(this.#note(
				<?= json_encode(_('Detected without MIB support, so treat the index with suspicion.')) ?>
			));
		}

		const grid = document.createElement('table');
		grid.className = 'list-table';

		const header = ['<th>' + <?= json_encode(_('Index')) ?> + '</th>'];
		const columns = table.columns.slice(0, 12);

		for (const column of columns) {
			header.push('<th title="' + this.#escape(column.description || '')
				+ '">' + this.#escape(column.name || ('.' + column.oid)) + '</th>');
		}

		grid.innerHTML = '<thead><tr>' + header.join('') + '</tr></thead>';

		const body = document.createElement('tbody');

		for (const index of table.indexes.slice(0, 200)) {
			const tr = document.createElement('tr');
			const first = document.createElement('td');
			first.textContent = index;
			tr.appendChild(first);

			for (const column of columns) {
				const td = document.createElement('td');
				td.textContent = column.values[index] ?? '';
				tr.appendChild(td);
			}

			body.appendChild(tr);
		}

		grid.appendChild(body);

		const scroll = document.createElement('div');
		scroll.className = 'snmpwalk-scroll';
		scroll.appendChild(grid);
		card.appendChild(scroll);

		if (table.columns.length > columns.length) {
			card.appendChild(this.#note(
				<?= json_encode(_('Showing the first 12 columns.')) ?>
			));
		}

		if (this.writable && table.lld.worth_lld) {
			const button = document.createElement('button');
			button.type = 'button';
			button.className = 'btn-alt';
			button.textContent = <?= json_encode(_('Create discovery rule')) ?>;
			button.addEventListener('click', () => this.#previewDiscovery(table));
			card.appendChild(button);
		}

		return card;
	}

	// ---------------------------------------------------------------- coverage

	async #renderCoverage() {
		if (this.rows.length === 0) {
			this.panel.innerHTML = '';
			this.panel.appendChild(this.#empty(<?= json_encode(_('Run a walk first.')) ?>));
			return;
		}

		if (this.coverage === null) {
			this.panel.innerHTML = '';
			this.panel.appendChild(this.#note(<?= json_encode(_('Comparing against what this host polls…')) ?>));

			try {
				const data = await this.#post('snmpwalk.coverage', {
					hostid: this.hostid,
					token: this.token
				});

				data.uncovered_set = new Set(data.uncovered.map((row) => row.oid));
				this.coverage = data;
				document.getElementById('snmpwalk-uncovered-only').disabled = false;
			}
			catch (error) {
				this.#message('error', error.message);
				return;
			}
		}

		const data = this.coverage;
		this.panel.innerHTML = '';

		const summary = document.createElement('div');
		summary.className = 'snmpwalk-summary';
		summary.textContent = data.percent + <?= json_encode(_('% of what this device exposes is already monitored (')) ?>
			+ data.covered_count + '/' + data.total + ').';
		this.panel.appendChild(summary);

		if (data.unanswered.length > 0) {
			const card = document.createElement('div');
			card.className = 'snmpwalk-card';
			card.innerHTML = '<h4>' + <?= json_encode(_('Polled but not returned')) ?> + '</h4>';
			card.appendChild(this.#note(
				<?= json_encode(_('These are polled by an item or discovery rule, but the device did not answer for them inside the walked subtree. Either the walk did not cover them, or the template does not fit this device.')) ?>
			));

			const list = document.createElement('table');
			list.className = 'list-table';
			list.innerHTML = '<thead><tr><th>' + <?= json_encode(_('OID')) ?> + '</th><th>'
				+ <?= json_encode(_('Rule')) ?> + '</th><th>' + <?= json_encode(_('State')) ?> + '</th></tr></thead>';

			const body = document.createElement('tbody');

			for (const rule of data.unanswered.slice(0, 200)) {
				const tr = document.createElement('tr');
				tr.innerHTML = '<td class="snmpwalk-oid">.' + this.#escape(rule.oid) + '</td>'
					+ '<td>' + this.#escape(rule.name) + '</td>'
					+ '<td>' + this.#escape(rule.state === 'unsupported' ? rule.error || rule.state : rule.status) + '</td>';
				body.appendChild(tr);
			}

			list.appendChild(body);
			card.appendChild(list);
			this.panel.appendChild(card);
		}

		const card = document.createElement('div');
		card.className = 'snmpwalk-card';
		card.innerHTML = '<h4>' + <?= json_encode(_('Exposed but not monitored')) ?> + '</h4>';

		const list = document.createElement('table');
		list.className = 'list-table';
		list.innerHTML = '<thead><tr><th>' + <?= json_encode(_('Object')) ?> + '</th><th>'
			+ <?= json_encode(_('Values')) ?> + '</th><th>' + <?= json_encode(_('Sample')) ?> + '</th></tr></thead>';

		const body = document.createElement('tbody');

		for (const branch of data.uncovered_branches.slice(0, 300)) {
			const tr = document.createElement('tr');
			tr.innerHTML = '<td>' + this.#escape(branch.name || '')
				+ '<div class="snmpwalk-oid">.' + this.#escape(branch.oid) + '</div></td>'
				+ '<td>' + branch.count + '</td>'
				+ '<td class="snmpwalk-value">' + this.#escape(branch.sample) + '</td>';
			body.appendChild(tr);
		}

		list.appendChild(body);
		card.appendChild(list);
		this.panel.appendChild(card);
	}

	// ---------------------------------------------------------------- snapshots

	async #renderSnapshots() {
		this.panel.innerHTML = '';

		if (!this.hostid) {
			this.panel.appendChild(this.#empty(<?= json_encode(_('Select a host to see its saved walks.')) ?>));
			return;
		}

		if (this.snapshots.length === 0) {
			this.panel.appendChild(this.#empty(
				<?= json_encode(_('Nothing saved for this host yet. Run a walk and save it as a baseline.')) ?>
			));
			return;
		}

		const table = document.createElement('table');
		table.className = 'list-table';
		table.innerHTML = '<thead><tr>'
			+ '<th>' + <?= json_encode(_('Taken')) ?> + '</th>'
			+ '<th>' + <?= json_encode(_('Label')) ?> + '</th>'
			+ '<th>' + <?= json_encode(_('Root')) ?> + '</th>'
			+ '<th>' + <?= json_encode(_('Values')) ?> + '</th>'
			+ '<th>' + <?= json_encode(_('By')) ?> + '</th>'
			+ '<th></th></tr></thead>';

		const body = document.createElement('tbody');

		for (const snapshot of this.snapshots) {
			const tr = document.createElement('tr');
			tr.innerHTML = '<td>' + new Date(snapshot.created * 1000).toLocaleString() + '</td>'
				+ '<td>' + this.#escape(snapshot.label || '') + '</td>'
				+ '<td class="snmpwalk-oid">.' + this.#escape(snapshot.root) + '</td>'
				+ '<td>' + snapshot.count + '</td>'
				+ '<td>' + this.#escape(snapshot.user || '') + '</td>';

			const actions = document.createElement('td');

			const load = document.createElement('button');
			load.type = 'button';
			load.className = 'btn-link';
			load.textContent = <?= json_encode(_('Open')) ?>;
			load.addEventListener('click', () => this.#loadSnapshot(snapshot.id));
			actions.appendChild(load);

			if (this.writable) {
				const remove = document.createElement('button');
				remove.type = 'button';
				remove.className = 'btn-link';
				remove.textContent = <?= json_encode(_('Delete')) ?>;
				remove.addEventListener('click', () => this.#deleteSnapshot(snapshot.id));
				actions.appendChild(remove);
			}

			tr.appendChild(actions);
			body.appendChild(tr);
		}

		table.appendChild(body);
		this.panel.appendChild(table);
	}

	async #saveSnapshot() {
		const label = prompt(<?= json_encode(_('Label this walk (optional), for example "before 17.09.01 upgrade"')) ?>, '');

		if (label === null) {
			return;
		}

		try {
			const data = await this.#post('snmpwalk.snapshot.save', {
				hostid: this.hostid,
				token: this.token,
				label
			});

			this.snapshots = data.snapshots;
			this.#message('ok', <?= json_encode(_('Saved.')) ?>);
		}
		catch (error) {
			this.#message('error', error.message);
		}
	}

	async #loadSnapshot(id) {
		try {
			const data = await this.#post('snmpwalk.snapshot.get', {hostid: this.hostid, id});

			this.rows = data.rows;
			this.token = null;
			this.analysis = null;
			this.coverage = null;
			this.meta = data.meta;

			document.getElementById('snmpwalk-download').disabled = false;
			document.getElementById('snmpwalk-save').disabled = true;

			this.#showTab('values');
			this.#message('ok', data.total + <?= json_encode(_(' values from a walk taken ')) ?>
				+ new Date((data.meta.saved || data.meta.started) * 1000).toLocaleString());
		}
		catch (error) {
			this.#message('error', error.message);
		}
	}

	async #deleteSnapshot(id) {
		if (!confirm(<?= json_encode(_('Delete this saved walk?')) ?>)) {
			return;
		}

		try {
			const data = await this.#post('snmpwalk.snapshot.delete', {hostid: this.hostid, id});
			this.snapshots = data.snapshots;
			this.#renderSnapshots();
		}
		catch (error) {
			this.#message('error', error.message);
		}
	}

	// ---------------------------------------------------------------- diff

	async #renderDiff() {
		this.panel.innerHTML = '';

		if (!this.hostid) {
			this.panel.appendChild(this.#empty(<?= json_encode(_('Select a host first.')) ?>));
			return;
		}

		let all;

		try {
			all = (await this.#post('snmpwalk.snapshot.list', {})).snapshots;
		}
		catch (error) {
			this.#message('error', error.message);
			return;
		}

		if (all.length === 0) {
			this.panel.appendChild(this.#empty(<?= json_encode(_('Save at least one walk before comparing.')) ?>));
			return;
		}

		const controls = document.createElement('div');
		controls.className = 'snmpwalk-diff-controls';

		const before = this.#snapshotSelect(all, <?= json_encode(_('Before')) ?>);
		const after = this.#snapshotSelect(all, <?= json_encode(_('After')) ?>, this.rows.length > 0);

		const volatile_toggle = document.createElement('label');
		const volatile_input = document.createElement('input');
		volatile_input.type = 'checkbox';
		volatile_input.checked = true;
		volatile_toggle.append(volatile_input,
			document.createTextNode(' ' + <?= json_encode(_('Ignore counters and uptime')) ?>));

		const run = document.createElement('button');
		run.type = 'button';
		run.className = 'btn-alt';
		run.textContent = <?= json_encode(_('Compare')) ?>;

		controls.append(before.wrapper, after.wrapper, volatile_toggle, run);
		this.panel.appendChild(controls);

		const output = document.createElement('div');
		this.panel.appendChild(output);

		run.addEventListener('click', async () => {
			const before_value = before.select.value.split('|');
			const after_value = after.select.value.split('|');

			try {
				const params = {
					before_hostid: before_value[0],
					before_id: before_value[1],
					ignore_volatile: volatile_input.checked ? 1 : 0
				};

				if (after_value[0] === 'current') {
					params.after_token = this.token;
					params.after_hostid = this.hostid;
				}
				else {
					params.after_hostid = after_value[0];
					params.after_id = after_value[1];
				}

				const data = await this.#post('snmpwalk.diff', params);
				this.#renderDiffResult(output, data);
			}
			catch (error) {
				this.#message('error', error.message);
			}
		});
	}

	#snapshotSelect(snapshots, label, allow_current = false) {
		const wrapper = document.createElement('label');
		wrapper.textContent = label + ' ';

		const select = document.createElement('z-select');

		if (allow_current) {
			const option = document.createElement('option');
			option.value = 'current|';
			option.textContent = <?= json_encode(_('The walk on screen')) ?>;
			select.appendChild(option);
		}

		for (const snapshot of snapshots) {
			const option = document.createElement('option');
			option.value = snapshot.hostid + '|' + snapshot.id;
			option.textContent = snapshot.host_name + ' — '
				+ new Date(snapshot.created * 1000).toLocaleString()
				+ (snapshot.label ? ' — ' + snapshot.label : '');
			select.appendChild(option);
		}

		wrapper.appendChild(select);

		return {wrapper, select};
	}

	#renderDiffResult(container, data) {
		container.innerHTML = '';

		const summary = document.createElement('div');
		summary.className = 'snmpwalk-summary';
		summary.textContent = [
			data.summary.added + <?= json_encode(_(' added')) ?>,
			data.summary.removed + <?= json_encode(_(' removed')) ?>,
			data.summary.changed + <?= json_encode(_(' changed')) ?>,
			data.summary.retyped + <?= json_encode(_(' changed type')) ?>,
			data.summary.unchanged + <?= json_encode(_(' unchanged')) ?>
		].join(' · ');
		container.appendChild(summary);

		const sections = [
			['removed', <?= json_encode(_('Gone')) ?>, data.removed],
			['added', <?= json_encode(_('New')) ?>, data.added],
			['changed', <?= json_encode(_('Changed')) ?>, data.changed],
			['retyped', <?= json_encode(_('Changed type')) ?>, data.retyped]
		];

		for (const [kind, title, rows] of sections) {
			if (rows.length === 0) {
				continue;
			}

			const card = document.createElement('div');
			card.className = 'snmpwalk-card snmpwalk-diff-' + kind;
			card.innerHTML = '<h4>' + title + ' (' + rows.length + ')</h4>';

			const table = document.createElement('table');
			table.className = 'list-table';
			const body = document.createElement('tbody');

			for (const row of rows.slice(0, 500)) {
				const tr = document.createElement('tr');
				const object = '<td>' + this.#escape(row.name || '')
					+ '<div class="snmpwalk-oid">.' + this.#escape(row.oid) + '</div></td>';

				if (kind === 'changed') {
					tr.innerHTML = object
						+ '<td class="snmpwalk-value snmpwalk-before">' + this.#escape(row.before) + '</td>'
						+ '<td class="snmpwalk-value snmpwalk-after">' + this.#escape(row.after) + '</td>';
				}
				else if (kind === 'retyped') {
					tr.innerHTML = object
						+ '<td>' + this.#escape(row.before_type) + ' → ' + this.#escape(row.after_type) + '</td>'
						+ '<td class="snmpwalk-value">' + this.#escape(row.after) + '</td>';
				}
				else {
					tr.innerHTML = object + '<td class="snmpwalk-value">' + this.#escape(row.value) + '</td>';
				}

				body.appendChild(tr);
			}

			table.appendChild(body);
			card.appendChild(table);
			container.appendChild(card);
		}
	}

	// ---------------------------------------------------------------- selection

	#updateSelectionCount() {
		const count = this.selected.size;
		const button = document.getElementById('snmpwalk-create-selected');
		const label = document.getElementById('snmpwalk-selection-count');

		button.disabled = count === 0 || !this.writable;
		label.textContent = count === 0
			? ''
			: count + <?= json_encode(_(' selected')) ?>;
	}

	#selectedRows() {
		return this.rows.filter((row) => this.selected.has(row.oid));
	}

	/**
	 * Target picker: the walked host, an existing template, or a template created on
	 * the spot. Built here rather than server-side because it lives inside the same
	 * overlay as the preview, and the preview has to refresh when the target changes.
	 */
	/**
	 * A titled block, so the dialog reads as sections rather than a wall of controls.
	 */
	#section(title, ...nodes) {
		const section = document.createElement('div');
		section.className = 'snmpwalk-section';

		const heading = document.createElement('div');
		heading.className = 'snmpwalk-section-title';
		heading.textContent = title;
		section.appendChild(heading);

		for (const node of nodes) {
			section.appendChild(node);
		}

		return section;
	}

	#field(label, ...controls) {
		const row = document.createElement('div');
		row.className = 'snmpwalk-field';

		const name = document.createElement('div');
		name.className = 'snmpwalk-field-label';
		name.textContent = label;
		row.appendChild(name);

		const value = document.createElement('div');
		value.className = 'snmpwalk-field-value';

		for (const control of controls) {
			value.appendChild(control);
		}

		row.appendChild(value);

		return row;
	}

	#hint(text) {
		const hint = document.createElement('div');
		hint.className = 'snmpwalk-hint';
		hint.textContent = text;

		return hint;
	}

	/**
	 * Target picker: an existing template, a new one, or the walked host.
	 *
	 * Three explicit choices rather than a template dropdown with a "create new" entry
	 * hidden in it. The old shape defaulted to whichever template sorted first, which
	 * meant the dialog opened aimed at an unrelated stock template and said so only in
	 * small print. A new template named after the walked host is the safe default: it
	 * cannot collide with anything and it is obvious what it is.
	 */
	async #targetPicker() {
		if (this.targets === null) {
			this.targets = await this.#post('snmpwalk.targets', {});
		}

		const wrapper = document.createElement('div');
		const default_name = (this.meta.host_name || 'Walked device') + ' by SNMP';

		const modes = {};
		const choice = document.createElement('div');
		choice.className = 'snmpwalk-choice';

		for (const [value, text] of [
			['new', <?= json_encode(_('New template')) ?>],
			['existing', <?= json_encode(_('Existing template')) ?>],
			['host', <?= json_encode(_('This host only')) ?>]
		]) {
			const label = document.createElement('label');
			const radio = document.createElement('input');
			radio.type = 'radio';
			radio.name = 'snmpwalk-target-' + Math.random().toString(36).slice(2);
			radio.value = value;
			radio.checked = value === 'new';
			label.append(radio, document.createTextNode(' ' + text));
			choice.appendChild(label);
			modes[value] = radio;
		}

		// Radios need a shared name to be exclusive; give them all the first one's.
		const group_name = modes.new.name;

		for (const radio of Object.values(modes)) {
			radio.name = group_name;
		}

		const new_name = document.createElement('input');
		new_name.type = 'text';
		new_name.className = 'focusable';
		new_name.value = default_name;

		const group = document.createElement('select');
		group.className = 'focusable';

		for (const entry of this.targets.groups || []) {
			const option = document.createElement('option');
			option.value = entry.id;
			option.textContent = entry.name;
			option.selected = /network/i.test(entry.name);
			group.appendChild(option);
		}

		const filter = document.createElement('input');
		filter.type = 'text';
		filter.className = 'focusable';
		filter.placeholder = <?= json_encode(_('Type to find a template')) ?>;

		const select = document.createElement('select');
		select.className = 'focusable';

		const found = this.#hint('');

		const fill = (templates, truncated, limit) => {
			const previous = select.value;
			select.innerHTML = '';

			for (const template of templates) {
				const option = document.createElement('option');
				option.value = template.id;
				option.textContent = template.name;
				select.appendChild(option);
			}

			if (previous !== '' && templates.some((t) => t.id === previous)) {
				select.value = previous;
			}

			found.textContent = templates.length === 0
				? <?= json_encode(_('Nothing matches.')) ?>
				: (truncated
					? <?= json_encode(_('Showing the first ')) ?> + limit
						+ <?= json_encode(_('. Keep typing to narrow it.')) ?>
					: templates.length + <?= json_encode(_(' matching')) ?>);

			select.dispatchEvent(new Event('change', {bubbles: true}));
		};

		fill(this.targets.templates, this.targets.truncated, this.targets.limit);

		let search_timer = null;

		filter.addEventListener('input', () => {
			clearTimeout(search_timer);
			search_timer = setTimeout(async () => {
				found.textContent = <?= json_encode(_('Searching…')) ?>;

				try {
					const data = await this.#post('snmpwalk.targets', {
						search: filter.value.trim(),
						groups: 0
					});

					fill(data.templates, data.truncated, data.limit);
				}
				catch (error) {
					found.textContent = error.message;
				}
			}, 300);
		});

		const new_row = this.#field(<?= json_encode(_('Name')) ?>, new_name);
		const group_row = this.#field(<?= json_encode(_('Template group')) ?>, group);
		const find_row = this.#field(<?= json_encode(_('Find')) ?>, filter);
		const pick_row = this.#field(<?= json_encode(_('Template')) ?>, select, found);
		const note = this.#hint('');

		wrapper.append(choice, new_row, group_row, find_row, pick_row, note);

		const mode = () => Object.keys(modes).find((key) => modes[key].checked) || 'new';

		const sync = () => {
			const current = mode();

			new_row.style.display = current === 'new' ? '' : 'none';
			group_row.style.display = current === 'new' ? '' : 'none';
			find_row.style.display = current === 'existing' ? '' : 'none';
			pick_row.style.display = current === 'existing' ? '' : 'none';

			note.textContent = current === 'host'
				? <?= json_encode(_('Objects go straight onto this host. They will not inherit anywhere and will drift from your templates.')) ?>
				: <?= json_encode(_('Objects go on the template. It is not linked to this host, so nothing polls until you link it yourself.')) ?>;
		};

		sync();

		return {
			element: wrapper,

			ready: () => {
				const current = mode();

				if (current === 'host') {
					return {ok: true, reason: ''};
				}

				if (current === 'existing') {
					return select.value !== ''
						? {ok: true, reason: ''}
						: {ok: false, reason: <?= json_encode(_('Choose a template.')) ?>};
				}

				if (new_name.value.trim() === '') {
					return {ok: false, reason: <?= json_encode(_('Name the new template.')) ?>};
				}

				if (group.value === '') {
					return {
						ok: false,
						reason: <?= json_encode(_('A new template needs a group, and you do not appear to have one you can write to.')) ?>
					};
				}

				return {ok: true, reason: ''};
			},

			params: () => {
				const current = mode();

				if (current === 'host') {
					return {target: 'host'};
				}

				return current === 'existing'
					? {target: 'template', templateid: select.value}
					: {
						target: 'template',
						template_name: new_name.value.trim(),
						groupid: group.value
					};
			},

			/**
			 * The name to put on an exported document, which is the same name the user
			 * already chose here rather than a second box asking again.
			 */
			exportName: () => {
				const current = mode();

				if (current === 'existing') {
					return select.selectedOptions.length > 0
						? select.selectedOptions[0].textContent
						: default_name;
				}

				return current === 'new' && new_name.value.trim() !== ''
					? new_name.value.trim()
					: default_name;
			},

			onChange: (handler) => {
				let timer = null;

				for (const radio of Object.values(modes)) {
					radio.addEventListener('change', () => { sync(); handler(); });
				}

				for (const element of [select, group]) {
					element.addEventListener('change', handler);
				}

				new_name.addEventListener('input', () => {
					clearTimeout(timer);
					timer = setTimeout(handler, 400);
				});
			}
		};
	}

	/**
	 * "Create enabled" toggle, with a default that follows the target.
	 *
	 * On a template that is not linked to anything, nothing polls until you link it, so
	 * the link is already the review gate and creating disabled just means editing
	 * every prototype by hand later. On a host, creation is deployment: a discovery
	 * rule with twenty prototypes against a 400-port chassis starts polling thousands
	 * of items the moment it exists, so that one defaults off.
	 *
	 * Once the user touches the checkbox it stops following the target.
	 */
	#enabledToggle(kind, picker) {
		const wrapper = document.createElement('div');

		const label = document.createElement('label');
		label.className = 'snmpwalk-checkbox';
		const box = document.createElement('input');
		box.type = 'checkbox';
		label.append(box, document.createTextNode(' ' + <?= json_encode(_('Create enabled')) ?>));

		const note = this.#hint('');

		wrapper.append(label, note);

		let touched = false;
		box.addEventListener('change', () => { touched = true; });

		const sync = () => {
			const to_template = picker.params().target === 'template';

			if (!touched) {
				box.checked = to_template || kind === 'item';
			}

			if (to_template) {
				note.textContent = <?= json_encode(_('Nothing polls until you link the template to a host.')) ?>;
			}
			else if (kind === 'discovery') {
				note.textContent = <?= json_encode(_('This starts polling immediately, once per discovered row per prototype.')) ?>;
			}
			else {
				note.textContent = <?= json_encode(_('This starts polling immediately.')) ?>;
			}
		};

		sync();
		picker.onChange(sync);

		return {
			element: wrapper,
			value: () => (box.checked ? 1 : 0)
		};
	}

	async #previewSelection() {
		const rows = this.#selectedRows();

		if (rows.length === 0) {
			return;
		}

		let picker;

		try {
			picker = await this.#targetPicker();
		}
		catch (error) {
			this.#message('error', error.message);

			return;
		}

		const enabled = this.#enabledToggle('item', picker);

		const summary = document.createElement('div');
		summary.className = 'snmpwalk-summary';

		const list = document.createElement('div');
		list.className = 'snmpwalk-scroll';

		const body = document.createElement('div');
		body.className = 'snmpwalk-preview';
		body.append(
			this.#section(<?= json_encode(_('Where')) ?>, picker.element),
			this.#section(<?= json_encode(_('Options')) ?>, enabled.element),
			this.#section(<?= json_encode(_('Items')) ?>, summary, list)
		);

		let editor = null;

		const request = (mode) => {
			const params = {
				hostid: this.hostid,
				interfaceid: this.#interfaceId(),
				mode,
				kind: 'item',
				enabled: enabled.value(),
				oids: rows.map((r) => r.oid),
				types: rows.map((r) => r.type),
				values: rows.map((r) => r.value),
				...picker.params()
			};

			// Edits are only sent once there is something to send; the preview that
			// produced them has to come back first.
			if (editor !== null) {
				params.overrides = JSON.stringify(editor.overrides());
			}

			if (mode === 'export') {
				params.export_name = picker.exportName();
			}

			return params;
		};

		const refresh = async () => {
			list.innerHTML = '';
			editor = null;

			const state = picker.ready();

			if (!state.ok) {
				summary.textContent = state.reason;

				return;
			}

			summary.textContent = <?= json_encode(_('Working out what would be created…')) ?>;

			try {
				const data = await this.#post('snmpwalk.create', request('preview'));

				summary.textContent = data.items.length
					+ <?= json_encode(_(' items on ')) ?> + data.target.name
					+ (data.target.will_create ? <?= json_encode(_(' (will be created)')) ?> : '')
					+ (data.rejected.length
						? ' · ' + data.rejected.length + <?= json_encode(_(' skipped')) ?>
						: '');

				editor = this.#editableTable(data.items, data.rejected, (item) => {
					const match = /\[?([0-9.]+)\]?$/.exec(item.snmp_oid);

					return match === null ? item.snmp_oid : match[1];
				});

				list.appendChild(editor.element);
			}
			catch (error) {
				summary.textContent = error.message;
			}
		};

		picker.onChange(() => { refresh(); });
		refresh();

		overlayDialogue({
			title: <?= json_encode(_('Create items from selection')) ?>,
			class: 'modal-popup modal-popup-large',
			content: body,
			buttons: [
				{
					title: <?= json_encode(_('Create')) ?>,
					action: async () => {
						const state = picker.ready();

						if (!state.ok) {
							this.#message('warning', state.reason);

							return;
						}

						try {
							const result = await this.#post('snmpwalk.create', request('create'));

							this.selected.clear();
							this.#renderValues();
							this.#message(result.rejected.length ? 'warning' : 'ok',
								result.created + <?= json_encode(_(' items created on ')) ?>
									+ result.target.name
									+ (result.target.created
										? <?= json_encode(_(' (new template)')) ?>
										: ''),
								result.rejected.map((r) => r.reason)
							);
						}
						catch (error) {
							this.#message('error', error.message);
						}
					}
				},
				{
					title: <?= json_encode(_('Export YAML')) ?>,
					class: 'btn-alt',
					enabled: true,
					keepOpen: true,
					action: async () => {
						try {
							this.#saveExport(await this.#post('snmpwalk.create', request('export')));
						}
						catch (error) {
							this.#message('error', error.message);
						}
					}
				},
				{
					title: <?= json_encode(_('Cancel')) ?>,
					class: 'btn-alt',
					cancel: true,
					action: () => {}
				}
			]
		});
	}

	/**
	 * Editable preview.
	 *
	 * Name, interval and units can be fixed after creation in about ten seconds. The
	 * key and the value type cannot: a changed key orphans history, and a changed value
	 * type after history exists splits the data across tables. Those two were the ones
	 * the module guessed with no way to intervene, so they are editable here, before
	 * anything is written.
	 *
	 * Returns the table plus a reader for whatever was edited, keyed by OID so the
	 * server can match rows without relying on ordering.
	 */
	#editableTable(rows, rejected, oid_of) {
		const table = document.createElement('table');
		table.className = 'list-table snmpwalk-editable';
		table.innerHTML = '<thead><tr>'
			+ '<th style="width: 26%">' + <?= json_encode(_('Name')) ?> + '</th>'
			+ '<th style="width: 24%">' + <?= json_encode(_('Key')) ?> + '</th>'
			+ '<th style="width: 18%">' + <?= json_encode(_('Type of information')) ?> + '</th>'
			+ '<th style="width: 12%">' + <?= json_encode(_('Interval')) ?> + '</th>'
			+ '<th style="width: 12%">' + <?= json_encode(_('Units')) ?> + '</th>'
			+ '</tr></thead>';

		const types = [
			[0, <?= json_encode(_('Numeric (float)')) ?>],
			[1, <?= json_encode(_('Character')) ?>],
			[2, <?= json_encode(_('Log')) ?>],
			[3, <?= json_encode(_('Numeric (unsigned)')) ?>],
			[4, <?= json_encode(_('Text')) ?>]
		];

		const body = document.createElement('tbody');
		const fields = [];

		for (const item of rows) {
			const tr = document.createElement('tr');
			const oid = oid_of(item);
			const row = {oid};

			for (const [field, value] of [['name', item.name], ['key_', item.key_]]) {
				const cell = document.createElement('td');
				const input = document.createElement('input');
				input.type = 'text';
				input.className = 'focusable';
				input.value = value;
				input.style.width = '100%';
				cell.appendChild(input);
				tr.appendChild(cell);
				row[field] = input;
			}

			const type_cell = document.createElement('td');
			const type_select = document.createElement('select');
			type_select.className = 'focusable';

			for (const [value, label] of types) {
				const option = document.createElement('option');
				option.value = String(value);
				option.textContent = label;
				option.selected = value === item.value_type;
				type_select.appendChild(option);
			}

			type_cell.appendChild(type_select);
			tr.appendChild(type_cell);
			row.value_type = type_select;

			for (const [field, value] of [['delay', item.delay], ['units', item.units || '']]) {
				const cell = document.createElement('td');
				const input = document.createElement('input');
				input.type = 'text';
				input.className = 'focusable';
				input.value = value;
				input.style.width = '100%';
				cell.appendChild(input);
				tr.appendChild(cell);
				row[field] = input;
			}

			body.appendChild(tr);
			fields.push(row);
		}

		for (const skipped of rejected || []) {
			const tr = document.createElement('tr');
			tr.className = 'snmpwalk-skipped';
			tr.innerHTML = '<td class="snmpwalk-oid">' + this.#escape(skipped.oid) + '</td>'
				+ '<td colspan="4">' + this.#escape(skipped.reason) + '</td>';
			body.appendChild(tr);
		}

		table.appendChild(body);

		return {
			element: table,
			overrides: () => {
				const out = {};

				for (const row of fields) {
					out[row.oid] = {
						name: row.name.value,
						key_: row.key_.value,
						value_type: Number(row.value_type.value),
						delay: row.delay.value,
						units: row.units.value
					};
				}

				return out;
			}
		};
	}

	/**
	 * Turn a returned document into a download. The file is the deliverable, so it goes
	 * straight to disk rather than into a box to copy out of.
	 */
	#saveExport(data) {
		this.#save(data.filename, data.yaml);
		this.#message('ok',
			data.counts.items + <?= json_encode(_(' items and ')) ?>
				+ data.counts.prototypes + <?= json_encode(_(' prototypes exported to ')) ?>
				+ data.filename
		);
	}

	// ---------------------------------------------------------------- creating

	/**
	 * The single-row button is the same operation as the bulk one with a selection of
	 * one, so it goes through the same code and gets the same target picker.
	 */
	async #previewItem(row) {
		this.selected.clear();
		this.selected.add(row.oid);
		this.#renderValues();
		await this.#previewSelection();
	}

	/**
	 * Discovery rule dialog.
	 *
	 * Same shape as the bulk item dialog: the target picker is part of the dialog and
	 * the preview re-runs when it changes. An earlier version built the picker but
	 * never displayed it, so rules went to whichever template sorted first without
	 * anyone being asked. Nothing should be created against a target the user did not
	 * see.
	 */
	async #previewDiscovery(table) {
		const columns = table.lld.metric_columns.map((column) => column.oid);

		let picker;

		try {
			picker = await this.#targetPicker();
		}
		catch (error) {
			this.#message('error', error.message);

			return;
		}

		const enabled = this.#enabledToggle('discovery', picker);

		const summary = document.createElement('div');
		summary.className = 'snmpwalk-summary';

		const detail = document.createElement('div');

		const body = document.createElement('div');
		body.className = 'snmpwalk-preview';
		body.append(
			this.#section(<?= json_encode(_('Where')) ?>, picker.element),
			this.#section(<?= json_encode(_('Options')) ?>, enabled.element),
			this.#section(<?= json_encode(_('Rule and prototypes')) ?>, summary, detail)
		);

		let editor = null;
		let rule_fields = null;

		const request = (mode) => {
			const params = {
				hostid: this.hostid,
				interfaceid: this.#interfaceId(),
				mode,
				kind: 'discovery',
				enabled: enabled.value(),
				oid: table.entry_oid,
				label_oid: table.lld.label_oid,
				label_macro: table.lld.label_macro.replace(/[{}#]/g, ''),
				columns,
				...picker.params()
			};

			if (editor !== null) {
				params.overrides = JSON.stringify(editor.overrides());
			}

			if (rule_fields !== null) {
				params.rule = JSON.stringify({
					name: rule_fields.name.value,
					key_: rule_fields.key.value,
					delay: rule_fields.delay.value
				});
			}

			if (mode === 'export') {
				params.export_name = picker.exportName();
			}

			return params;
		};

		const refresh = async () => {
			detail.innerHTML = '';

			const state = picker.ready();

			if (!state.ok) {
				summary.textContent = state.reason;

				return;
			}

			summary.textContent = <?= json_encode(_('Working out what would be created…')) ?>;

			try {
				const data = await this.#post('snmpwalk.create', request('preview'));

				summary.textContent = <?= json_encode(_('A discovery rule and ')) ?>
					+ data.prototypes.length
					+ <?= json_encode(_(' item prototypes on ')) ?> + data.target.name
					+ (data.target.will_create
						? <?= json_encode(_(' (will be created)')) ?>
						: '');

				// The rule's own key is as unchangeable after creation as an item's, so
				// it is editable here too.
				rule_fields = {};

				for (const [field, label, value, wide] of [
					['name', <?= json_encode(_('Rule name')) ?>, data.rule.name, true],
					['key', <?= json_encode(_('Rule key')) ?>, data.rule.key_, true],
					['delay', <?= json_encode(_('Interval')) ?>, data.rule.delay, false]
				]) {
					const input = document.createElement('input');
					input.type = 'text';
					input.className = 'focusable';
					input.value = value;

					if (wide) {
						input.style.width = '100%';
					}

					detail.appendChild(this.#field(label, input));
					rule_fields[field] = input;
				}

				const oid_value = document.createElement('div');
				oid_value.className = 'snmpwalk-oid';
				oid_value.textContent = data.rule.snmp_oid;
				detail.appendChild(this.#field(<?= json_encode(_('SNMP OID')) ?>, oid_value));

				editor = this.#editableTable(data.prototypes, [], (prototype) => {
					const match = /\[?([0-9.]+)\.\{#SNMPINDEX\}\]?$/.exec(prototype.snmp_oid);

					return match === null ? prototype.snmp_oid : match[1];
				});

				const scroll = document.createElement('div');
				scroll.className = 'snmpwalk-scroll';
				scroll.appendChild(editor.element);
				detail.appendChild(scroll);

				if (enabled.value() === 0) {
					detail.appendChild(this.#note(
						<?= json_encode(_('Prototypes will be created disabled. Enable them once you have seen how many rows this discovers.')) ?>
					));
				}
			}
			catch (error) {
				summary.textContent = error.message;
			}
		};

		picker.onChange(() => { refresh(); });
		enabled.element.addEventListener('change', () => { refresh(); });
		refresh();

		overlayDialogue({
			title: <?= json_encode(_('Create discovery rule')) ?>,
			class: 'modal-popup modal-popup-large',
			content: body,
			buttons: [
				{
					title: <?= json_encode(_('Create')) ?>,
					action: async () => {
						const state = picker.ready();

						if (!state.ok) {
							this.#message('warning', state.reason);

							return;
						}

						try {
							const result = await this.#post('snmpwalk.create', request('create'));

							this.#message('ok',
								<?= json_encode(_('Discovery rule created on ')) ?>
									+ result.target.name
									+ (result.target.created
										? <?= json_encode(_(' (new template)')) ?>
										: '')
									+ <?= json_encode(_(', with ')) ?> + result.prototypes
									+ <?= json_encode(_(' prototypes.')) ?>
							);
						}
						catch (error) {
							this.#message('error', error.message);
						}
					}
				},
				{
					title: <?= json_encode(_('Export YAML')) ?>,
					class: 'btn-alt',
					enabled: true,
					keepOpen: true,
					action: async () => {
						try {
							this.#saveExport(await this.#post('snmpwalk.create', request('export')));
						}
						catch (error) {
							this.#message('error', error.message);
						}
					}
				},
				{
					title: <?= json_encode(_('Cancel')) ?>,
					class: 'btn-alt',
					cancel: true,
					action: () => {}
				}
			]
		});
	}

	// ---------------------------------------------------------------- download

	#download(event) {
		const menu = [
			{
				label: <?= json_encode(_('Numeric text (snmpwalk -On)')) ?>,
				clickCallback: () => this.#save('walk.txt', this.rows
					.map((r) => '.' + r.oid + ' = ' + (r.type ? r.type + ': ' : '') + r.value)
					.join('\n') + '\n')
			},
			{
				label: <?= json_encode(_('Translated text')) ?>,
				clickCallback: () => this.#save('walk-translated.txt', this.rows
					.map((r) => (r.name ? (r.mib ? r.mib + '::' : '') + r.name : '.' + r.oid)
						+ ' = ' + (r.type ? r.type + ': ' : '') + r.value)
					.join('\n') + '\n')
			},
			{
				label: <?= json_encode(_('JSON with types')) ?>,
				clickCallback: () => this.#save('walk.json', JSON.stringify({
					meta: this.meta,
					varbinds: this.rows
				}, null, 2))
			}
		];

		jQuery(event.target).menuPopup([{items: menu}], new jQuery.Event(event));
	}

	#save(name, contents) {
		const blob = new Blob([contents], {type: 'text/plain;charset=utf-8'});
		const link = document.createElement('a');
		link.href = URL.createObjectURL(blob);
		link.download = (this.meta.host_name || 'snmp') + '-' + name;
		link.click();
		URL.revokeObjectURL(link.href);
	}

	// ---------------------------------------------------------------- helpers

	#definitionList(pairs) {
		const list = document.createElement('dl');
		list.className = 'snmpwalk-definitions';

		for (const [key, value] of Object.entries(pairs)) {
			const dt = document.createElement('dt');
			dt.textContent = key;
			const dd = document.createElement('dd');
			dd.textContent = value;
			list.append(dt, dd);
		}

		return list;
	}

	#empty(text) {
		const div = document.createElement('div');
		div.className = 'snmpwalk-empty';
		div.textContent = text;

		return div;
	}

	#note(text) {
		const div = document.createElement('div');
		div.className = 'snmpwalk-note';
		div.textContent = text;

		return div;
	}

	#escape(text) {
		const div = document.createElement('div');
		div.textContent = text === null || text === undefined ? '' : String(text);

		return div.innerHTML;
	}
};
