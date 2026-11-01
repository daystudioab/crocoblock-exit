/* Crocoblock-avveckling – admin */
(function () {
	'use strict';

	if (typeof CBX === 'undefined') {
		return;
	}

	function post(action, data) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', CBX.nonce);
		Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
		return fetch(CBX.ajax, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) {
				return r.json().catch(function () {
					throw new Error('Servern svarade inte med JSON (HTTP ' + r.status + '). Kontrollera PHP-felloggen.');
				});
			})
			.then(function (json) {
				if (!json || !json.success) {
					throw new Error((json && json.data && json.data.message) || CBX.i18n.error);
				}
				return json.data;
			});
	}

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function progress(name) {
		var el = document.querySelector('[data-cbx-progress="' + name + '"]');
		return {
			show: function () { if (el) { el.hidden = false; } },
			set: function (done, total, text) {
				if (!el) { return; }
				var pct = total ? Math.min(100, Math.round(done / total * 100)) : 100;
				el.querySelector('.cbx-progress__bar').style.width = pct + '%';
				el.querySelector('.cbx-progress__text').textContent = text || (done + ' / ' + total);
			}
		};
	}

	function lockButtons(lock) {
		document.querySelectorAll('[data-cbx-scan],[data-cbx-batch]').forEach(function (b) {
			if (lock) {
				b.dataset.cbxWasDisabled = b.disabled ? '1' : '';
				b.disabled = true;
			} else {
				b.disabled = b.dataset.cbxWasDisabled === '1';
			}
		});
	}

	function fail(err, where) {
		lockButtons(false);
		var box = document.createElement('div');
		box.className = 'notice notice-error inline';
		box.innerHTML = '<p><strong>' + esc(CBX.i18n.error) + ':</strong> ' + esc(err.message || err) + '</p>';
		if (where) { where.prepend(box); }
		window.console && console.error(err);
	}

	/* ---------- Inventering ---------- */

	var scanBtn = document.querySelector('[data-cbx-scan]');
	if (scanBtn) {
		scanBtn.addEventListener('click', function () {
			var target = document.querySelector('[data-cbx-report]');
			var bar = progress('scan');
			lockButtons(true);
			bar.show();
			bar.set(0, 1, CBX.i18n.working);

			(function step(first) {
				post('cbx_scan', first ? { start: 1 } : {}).then(function (res) {
					bar.set(res.processed, res.total, res.processed + ' / ' + res.total + ' dokument');
					if (res.done) {
						target.innerHTML = res.html;
						bar.set(1, 1, CBX.i18n.done);
						lockButtons(false);
						return;
					}
					step(false);
				}).catch(function (e) { fail(e, target); });
			})(true);
		});
	}

	/* ---------- Provkörning / konvertering / återställning ---------- */

	var STATE = {
		would_change: ['Ändras', 'ok'],
		changed: ['Konverterad', 'ok'],
		manual_only: ['Bara manuellt', 'warn'],
		restored: ['Återställd', 'ok']
	};
	var STATUS = { converted: 'Ersatt', manual: 'Manuellt', cleaned: 'Rensat', dynamic: 'Dynamisk tagg' };

	function renderRow(row) {
		var st = STATE[row.state] || [row.state, ''];
		var s = row.summary || {};
		var head = '<a href="' + esc(row.view) + '" target="_blank" rel="noopener">' + esc(row.label) + '</a>' +
			(row.edit ? ' <a class="cbx-edit" href="' + esc(row.edit) + '" target="_blank" rel="noopener">redigera</a>' : '') +
			(row.preview && row.state !== 'manual_only' ? ' <a class="cbx-edit cbx-preview" href="' + esc(row.preview) + '" target="_blank" rel="noopener">förhandsgranska</a>' : '');
		var counts = [];
		if (s.converted) { counts.push(s.converted + ' ersatta'); }
		if (s.manual) { counts.push(s.manual + ' manuella'); }
		if (s.cleaned) { counts.push(s.cleaned + ' rensade'); }
		if (s.dynamic) { counts.push(s.dynamic + ' taggar'); }

		var items = (row.log || []).map(function (l) {
			var what = l.to ? '<code>' + esc(l.from) + '</code> → <code>' + esc(l.to) + '</code>' : '<code>' + esc(l.from) + '</code>';
			var notes = (l.notes || []).length ? '<ul><li>' + l.notes.map(esc).join('</li><li>') + '</li></ul>' : '';
			return '<li class="cbx-log--' + esc(l.status) + '"><span class="cbx-tag">' + esc(STATUS[l.status] || l.status) + '</span> ' + what + notes + '</li>';
		}).join('');

		return '<details class="cbx-row"><summary><span class="cbx-badge cbx-badge--' + st[1] + '">' + esc(st[0]) + '</span> ' + head +
			(counts.length ? ' <span class="cbx-meta">' + esc(counts.join(' · ')) + '</span>' : '') + '</summary>' +
			(items ? '<ul class="cbx-log">' + items + '</ul>' : '') + '</details>';
	}

	function renderSummary(mode, s) {
		if (mode === 'restore') {
			var re = typeof s.reactivated === 'number' && s.reactivated > 0 ? ' Crocoblock är aktiverat igen (' + s.reactivated + ' tillägg).' : '';
			return '<p><strong>' + s.restored + '</strong> sidor återställda.' + esc(re) + '</p>';
		}
		var verb = mode === 'dry' ? 'skulle ändras' : 'ändrades';
		return '<div class="cbx-stats">' +
			'<div><strong>' + s.posts + '</strong><span>dokument genomgångna</span></div>' +
			'<div class="ok"><strong>' + s.changed + '</strong><span>' + verb + '</span></div>' +
			'<div class="ok"><strong>' + s.converted + '</strong><span>widgets ersatta</span></div>' +
			'<div class="' + (s.manual ? 'warn' : 'ok') + '"><strong>' + s.manual + '</strong><span>kvar att göra manuellt</span></div>' +
			'<div><strong>' + s.cleaned + '</strong><span>element rensade</span></div>' +
			'</div>' + (mode === 'dry' ? '<p class="description">Provkörning – ingenting är ändrat. Klicka på en sida för detaljer.</p>' : '<p class="description">Klart. Elementors CSS-cache är tömd. Kontrollera sidorna och kör inventeringen igen.</p>');
	}

	document.querySelectorAll('[data-cbx-batch]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var mode = btn.getAttribute('data-cbx-batch');
			var confirmKey = btn.getAttribute('data-cbx-confirm');
			if (confirmKey && !window.confirm(CBX.i18n[confirmKey])) {
				return;
			}
			var group = mode === 'restore' ? 'restore' : 'pages';
			var results = document.querySelector('[data-cbx-results="' + group + '"]');
			var summary = document.querySelector('[data-cbx-summary="' + group + '"]');
			var bar = progress(group);
			results.innerHTML = '';
			if (summary) { summary.innerHTML = ''; }
			lockButtons(true);
			bar.show();
			bar.set(0, 1, CBX.i18n.working);

			post('cbx_batch_start', { mode: mode }).then(function (start) {
				(function step() {
					post('cbx_batch', { mode: mode }).then(function (res) {
						bar.set(res.processed, res.total, res.processed + ' / ' + res.total);
						results.insertAdjacentHTML('beforeend', res.rows.map(renderRow).join(''));
						if (summary) { summary.innerHTML = renderSummary(mode, res.summary); }
						if (res.done) {
							bar.set(1, 1, CBX.i18n.done);
							lockButtons(false);
							if (!res.rows.length && !results.innerHTML) {
								results.innerHTML = '<p class="cbx-good">Inga sidor berördes.</p>';
							}
							if (mode !== 'dry' && summary) {
								summary.insertAdjacentHTML('beforeend', '<p><a class="button" href="' + esc(window.location.href.split('&cbx_notice')[0]) + '">Ladda om sidan</a></p>');
							}
							return;
						}
						step();
					}).catch(function (e) { fail(e, results); });
				})();
			}).catch(function (e) { fail(e, results); });
		});
	});
})();
