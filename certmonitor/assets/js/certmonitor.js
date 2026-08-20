/**
 * Certificate Monitor - browser side helpers.
 *
 * This file is registered through the "assets.js" section of manifest.json and is therefore a plain,
 * static JavaScript file: it is never processed by PHP. Everything that needs translation, a URL or a
 * CSRF token is handed over through data attributes rendered by the views.
 */

(function () {
	'use strict';

	/**
	 * Build a hidden form and submit it, so that a per-row button can trigger a normal POST action.
	 *
	 * A full page POST is used on purpose: the module's list actions answer with a redirect and a message,
	 * which is exactly the behaviour of the "Delete" button next to them.
	 *
	 * @param {HTMLElement} button  The clicked button; carries the action, host and token as data
	 *                              attributes rendered by the view.
	 */
	function postAction(button) {
		const form = document.createElement('form');

		form.method = 'post';
		form.action = 'zabbix.php';
		form.style.display = 'none';

		const fields = {
			action: button.dataset.certmonitorAction,
			'hostids[]': button.dataset.hostid
		};

		const token = button.dataset.csrfToken;

		if (token !== undefined && token !== '') {
			fields[button.dataset.csrfName || '_csrf_token'] = token;
		}

		Object.keys(fields).forEach(function (name) {
			const input = document.createElement('input');

			input.type = 'hidden';
			input.name = name;
			input.value = fields[name];
			form.appendChild(input);
		});

		document.body.appendChild(form);
		form.submit();
	}

	/**
	 * Render one line of the "Test connection" result.
	 *
	 * @param {string} label
	 * @param {string} value
	 *
	 * @return {HTMLElement}
	 */
	function makeResultRow(label, value) {
		const row = document.createElement('div');

		row.className = 'certmonitor-check-row';

		const term = document.createElement('span');

		term.className = 'certmonitor-check-label';
		term.textContent = label;

		const definition = document.createElement('span');

		definition.className = 'certmonitor-check-value';
		definition.textContent = value;

		row.appendChild(term);
		row.appendChild(definition);

		return row;
	}

	/**
	 * Print the result of the pre-add check into the output container of the form.
	 *
	 * @param {HTMLElement} output
	 * @param {string}      state    "ok", "warning" or "error".
	 * @param {string}      summary
	 * @param {Array}       rows     Array of [label, value] pairs.
	 */
	function renderResult(output, state, summary, rows) {
		output.innerHTML = '';
		output.className = 'certmonitor-check-result certmonitor-check-' + state;
		output.setAttribute('role', 'status');

		const heading = document.createElement('div');

		heading.className = 'certmonitor-check-summary';
		heading.textContent = summary;
		output.appendChild(heading);

		(rows || []).forEach(function (row) {
			output.appendChild(makeResultRow(row[0], row[1]));
		});

		if (state !== 'ok') {
			const caveat = document.createElement('div');

			caveat.className = 'certmonitor-check-caveat';
			caveat.textContent = output.dataset.caveat || '';
			output.appendChild(caveat);
		}
	}

	/**
	 * Run the "Test connection" probe for the values currently typed into the form.
	 *
	 * @param {HTMLElement} button
	 */
	function testConnection(button) {
		const form = button.closest('form');

		if (form === null) {
			return;
		}

		const output = document.getElementById('certmonitor-check-output');

		if (output === null) {
			return;
		}

		const hostname = (form.elements.hostname ? form.elements.hostname.value : '').trim();
		const port = (form.elements.port ? form.elements.port.value : '').trim();
		const address = (form.elements.address ? form.elements.address.value : '').trim();

		if (hostname === '') {
			renderResult(output, 'error', button.dataset.emptyMessage || '', []);

			return;
		}

		renderResult(output, 'pending', button.dataset.pendingMessage || '', []);

		button.classList.add('is-loading');
		button.disabled = true;

		const url = button.dataset.checkUrl;

		// The endpoint consumes a JSON request body, therefore the CSRF token has to be part of that
		// body: a token appended to the query string is not read by the controller and Zabbix answers
		// with "Access denied".
		const payload = {hostname: hostname, port: port, address: address};
		const csrf_token = button.dataset.csrfToken;

		if (csrf_token !== undefined && csrf_token !== '') {
			payload[button.dataset.csrfName || '_csrf_token'] = csrf_token;
		}

		fetch(url, {
			method: 'POST',
			headers: {'Content-Type': 'application/json'},
			body: JSON.stringify(payload)
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (response) {
				if (response.error !== undefined) {
					const messages = (response.error.messages || []).map(function (message) {
						return ['', message];
					});

					renderResult(output, 'error', response.error.title || '', messages);

					return;
				}

				const result = response.result || {};

				if (!result.ok) {
					renderResult(output, 'error', result.summary || '',
						(result.rows || []).concat(result.error ? [['', result.error]] : [])
					);

					return;
				}

				renderResult(output, result.warning ? 'warning' : 'ok', result.summary || '', result.rows);
			})
			.catch(function () {
				renderResult(output, 'error', button.dataset.failedMessage || '', []);
			})
			.finally(function () {
				button.classList.remove('is-loading');
				button.disabled = false;
			});
	}

	document.addEventListener('click', function (event) {
		const post_button = event.target.closest('[data-certmonitor-action]');

		if (post_button !== null) {
			event.preventDefault();

			const confirmation = post_button.dataset.confirm;

			if (confirmation !== undefined && confirmation !== '' && !window.confirm(confirmation)) {
				return;
			}

			postAction(post_button);

			return;
		}

		const test_button = event.target.closest('#certmonitor-test-connection');

		if (test_button !== null) {
			event.preventDefault();
			// Keep the frontend's own click handlers away from this button.
			event.stopPropagation();
			testConnection(test_button);
		}
	}, true);
})();
