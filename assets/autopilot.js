/* Crocoblock-avveckling – autopilot
 *
 * Kör alla steg i följd och jämför original och konverterad version visuellt:
 * båda laddas i osynliga ramar i tre skärmbredder, och varje ändrat element
 * mäts – position och storlek, varje textrad (position, typsnitt, storlek,
 * vikt, färg) och varje bild. Skillnader klassas som avvikelse (gul) eller
 * fel (röd).
 */
(function (root) {
	'use strict';

	// Måste stämma med tilläggets version – kontrolleras när en körning startar,
	// så att en sida som laddades före en uppdatering inte kör gammal logik.
	var CBX_JS_VERSION = '1.10.1';

	var WIDTHS = [
		{ name: 'dator', w: 1440, h: 1000 },
		{ name: 'surfplatta', w: 768, h: 1024 },
		{ name: 'mobil', w: 375, h: 812 }
	];
	var POS_TOL = 3;   // px
	var MAX_TEXTS = 200;
	var MAX_IMGS = 40;

	/* ------------------------------------------------------------------ *
	 * Mätning (körs mot ett dokument – i en ram eller i ett test)
	 * ------------------------------------------------------------------ */

	function norm(t) {
		return String(t || '')
			.replace(/[   ]/g, ' ')
			.replace(/[‐-―−]/g, '-')
			.replace(/…/g, '...')
			.replace(/[‘’‚′]/g, "'")
			.replace(/[“”„″]/g, '"')
			.replace(/\s+/g, ' ')
			.trim();
	}

	function imgKey(src) {
		var s = String(src || '').split('#')[0].split('?')[0];
		s = s.substring(s.lastIndexOf('/') + 1).toLowerCase();
		s = s.replace(/\.(jpe?g|png|gif|webp|avif|svg)$/, '');
		return s.replace(/-\d+x\d+$/, '').replace(/-(scaled|rotated)$/, '');
	}

	function fam(ff) {
		return String(ff || '').split(',')[0].replace(/["']/g, '').trim().toLowerCase();
	}

	function round(n) {
		return Math.round(n * 10) / 10;
	}

	/** Dold för en besökare? (display, visibility, opacity, hopfälld behållare, aria-hidden) */
	function nodeName(el) {
		var cls = String(el.className && el.className.baseVal !== undefined ? el.className.baseVal : el.className || '').split(/\s+/).filter(Boolean).slice(0, 2).join('.');
		return el.tagName.toLowerCase() + (cls ? '.' + cls : '');
	}

	/**
	 * Är noden dold för en besökare? Returnerar en funktion som ger ORSAKEN som
	 * text ('display:none på …'), eller false när noden syns. (Sanningstestet
	 * är alltså oförändrat; orsaken används i rapporten.)
	 */
	function hiddenChecker(win, stop) {
		var cache = typeof Map !== 'undefined' ? new Map() : null;
		return function hidden(node) {
			var el = node;
			var chain = [];
			var result = false;
			while (el && el.nodeType === 1) {
				if (cache && cache.has(el)) {
					result = cache.get(el);
					break;
				}
				chain.push(el);
				var cs = win.getComputedStyle(el);
				if (cs.display === 'none') {
					result = 'display:none på ' + nodeName(el);
					break;
				}
				if (cs.visibility === 'hidden' || cs.visibility === 'collapse') {
					result = 'visibility:' + cs.visibility + ' på ' + nodeName(el);
					break;
				}
				if (parseFloat(cs.opacity) === 0) {
					result = 'opacity:0 på ' + nodeName(el);
					break;
				}
				if (el.getAttribute('aria-hidden') === 'true') {
					result = 'aria-hidden på ' + nodeName(el);
					break;
				}
				if (cs.overflow !== 'visible' || cs.overflowY !== 'visible' || cs.overflowX !== 'visible') {
					var r = el.getBoundingClientRect();
					if (r.width < 1 || r.height < 1) {
						result = 'hopfälld behållare (' + Math.round(r.width) + '×' + Math.round(r.height) + ', overflow) ' + nodeName(el);
						break;
					}
				}
				if (el === stop) {
					break;
				}
				el = el.parentElement;
			}
			if (cache) {
				chain.forEach(function (c) { cache.set(c, result); });
			}
			return result;
		};
	}

	function measure(doc, el, base) {
		var win = doc.defaultView;
		var r = el.getBoundingClientRect();
		var out = {
			x: round(r.left - base.left),
			y: round(r.top - base.top),
			w: round(r.width),
			h: round(r.height),
			visible: r.width > 0 && r.height > 0,
			texts: [],
			imgs: []
		};
		if (!out.visible) {
			return out;
		}
		var hidden = hiddenChecker(win, el);
		if (hidden(el)) {
			out.visible = false;
			return out;
		}
		var walker = doc.createTreeWalker(el, 4 /* SHOW_TEXT */, null);
		var node;
		var range = doc.createRange();
		while ((node = walker.nextNode()) && out.texts.length < MAX_TEXTS) {
			var t = norm(node.nodeValue);
			if (!t) {
				continue;
			}
			var parent = node.parentElement;
			if (!parent || parent.closest('script,style,noscript,template,svg,title')) {
				continue;
			}
			if (hidden(parent)) {
				continue;
			}
			range.selectNodeContents(node);
			var b = range.getBoundingClientRect();
			if (b.width < 0.5 || b.height < 0.5) {
				continue;
			}
			var cs = win.getComputedStyle(parent);
			out.texts.push({
				t: t,
				x: round(b.left - r.left),
				y: round(b.top - r.top),
				w: round(b.width),
				h: round(b.height),
				fs: cs.fontSize,
				ff: fam(cs.fontFamily),
				fw: String(cs.fontWeight),
				c: cs.color,
				tt: cs.textTransform,
				fst: cs.fontStyle,
				td: (cs.textDecorationLine || cs.textDecoration || '').split(' ')[0]
			});
		}
		var imgs = el.querySelectorAll('img');
		out.imgsTotal = imgs.length;
		out.imgsHidden = 0;
		out.imgsWhy = [];
		for (var i = 0; i < imgs.length && out.imgs.length < MAX_IMGS; i++) {
			var im = imgs[i];
			var ib = im.getBoundingClientRect();
			// Orsaken söks från föräldern (bildens egen overflow:clip ger annars bara "0×0").
			var why = (im.parentElement ? hidden(im.parentElement) : false) || hidden(im) || (ib.width < 1 || ib.height < 1 ? 'storlek ' + Math.round(ib.width) + '×' + Math.round(ib.height) : false);
			if (why) {
				out.imgsHidden++;
				if (out.imgsWhy.length < 5) {
					out.imgsWhy.push(imgKey(im.currentSrc || im.src) + ': ' + why);
				}
				continue;
			}
			out.imgs.push({ k: imgKey(im.currentSrc || im.src), x: round(ib.left - r.left), y: round(ib.top - r.top), w: round(ib.width), h: round(ib.height) });
		}
		return out;
	}

	/**
	 * Mäter elementen i en rot. ids tom → hela roten mäts som ett element.
	 */
	function collect(doc, rootSel, ids) {
		var rootEl = rootSel ? doc.querySelector(rootSel) : doc.body;
		if (!rootEl) {
			return { missingRoot: true };
		}
		var base = rootEl.getBoundingClientRect();
		var out = { widgets: {}, rootH: round(base.height) };
		var list = ids && ids.length ? ids : ['_root'];
		list.forEach(function (id) {
			var el = id === '_root' ? rootEl : rootEl.querySelector('.elementor-element-' + String(id).replace(/[^\w-]/g, ''));
			out.widgets[id] = el ? measure(doc, el, base) : { missing: true };
		});
		return out;
	}

	/* ------------------------------------------------------------------ *
	 * Jämförelse
	 * ------------------------------------------------------------------ */

	function styleDiff(a, b) {
		var d = [];
		if (a.fs !== b.fs) { d.push('storlek ' + a.fs + ' → ' + b.fs); }
		if (a.ff !== b.ff) { d.push('typsnitt ' + a.ff + ' → ' + b.ff); }
		if (a.fw !== b.fw) { d.push('vikt ' + a.fw + ' → ' + b.fw); }
		if (a.c !== b.c) { d.push('färg ' + a.c + ' → ' + b.c); }
		if (a.tt !== b.tt) { d.push('versaler ' + a.tt + ' → ' + b.tt); }
		if (a.fst !== b.fst) { d.push('stil ' + a.fst + ' → ' + b.fst); }
		if ((a.td || 'none') !== (b.td || 'none')) { d.push('understrykning ' + (a.td || 'none') + ' → ' + (b.td || 'none')); }
		return d;
	}

	function short(t) {
		return t.length > 40 ? t.slice(0, 38) + '…' : t;
	}

	function matchTexts(ot, pt) {
		var used = new Array(pt.length);
		var pairs = [];
		var missing = [];
		ot.forEach(function (o) {
			var j = -1;
			for (var k = 0; k < pt.length; k++) {
				if (!used[k] && pt[k].t === o.t) { j = k; break; }
			}
			if (j < 0) {
				// Avkortade texter (utdrag) kan sluta olika – jämför början.
				var head = o.t.slice(0, 25);
				for (k = 0; k < pt.length && head.length >= 12; k++) {
					if (!used[k] && pt[k].t.slice(0, 25) === head) { j = k; break; }
				}
			}
			if (j < 0) {
				missing.push(o);
			} else {
				used[j] = true;
				pairs.push([o, pt[j]]);
			}
		});
		var extra = pt.filter(function (p, k) { return !used[k]; });
		return { pairs: pairs, missing: missing, extra: extra };
	}

	function styleKey(s) {
		return [s.fs, s.ff, s.fw, s.c, s.tt].join('|');
	}

	/**
	 * Jämför två mätningar. job.context = mallens innehåll beror på vilket
	 * inlägg som visas: då jämförs stilarna, inte texterna och positionerna.
	 * @return {{status: string, issues: Array<{level: string, msg: string}>}}
	 */
	function diff(o, p, job) {
		job = job || {};
		var loose = (job.loose || []).map(String);
		var issues = [];
		function add(level, msg) { issues.push({ level: level, msg: msg }); }

		if (!o || o.missingRoot) {
			return { status: 'skip', issues: [{ level: 'info', msg: 'Originalet visas inte här – inget att jämföra med.' }] };
		}
		if (!p || p.missingRoot) {
			return { status: 'fail', issues: [{ level: 'fail', msg: 'Den konverterade versionen visas inte alls.' }] };
		}
		Object.keys(o.widgets).forEach(function (id) {
			var ow = o.widgets[id];
			var pw = p.widgets[id];
			var name = id === '_root' ? 'Mallen' : 'Element ' + id;
			if (ow.missing || !ow.visible) {
				return;
			}
			if (!pw || pw.missing) {
				add('fail', name + ' saknas.');
				return;
			}
			if (!pw.visible) {
				add('fail', name + ' syns inte.');
				return;
			}
			if (!pw.texts.length && ow.texts.length) {
				add('fail', name + ' är tomt (inga texter).');
				return;
			}
			// Avsiktligt ändrad layout (t.ex. karusell → rutnät): jämför bara stilen.
			if (!job.context && loose.indexOf(String(id)) < 0) {
				var dh = pw.h - ow.h;
				if (Math.abs(dh) > Math.max(4, ow.h * 0.01)) {
					add('warn', name + ': höjd ' + Math.round(ow.h) + ' → ' + Math.round(pw.h) + ' px.');
				}
				if (Math.abs(pw.w - ow.w) > POS_TOL) {
					add('warn', name + ': bredd ' + Math.round(ow.w) + ' → ' + Math.round(pw.w) + ' px.');
				}
				if (Math.abs(pw.y - ow.y) > POS_TOL || Math.abs(pw.x - ow.x) > POS_TOL) {
					add('warn', name + ': flyttat ' + Math.round(pw.x - ow.x) + ', ' + Math.round(pw.y - ow.y) + ' px.');
				}
				var m = matchTexts(ow.texts, pw.texts);
				var realMissing = m.missing.filter(function (t) { return t.t.length > 2; });
				var realExtra = m.extra.filter(function (t) { return t.t.length > 2; });
				if (realMissing.length) {
					var ratio = realMissing.length / Math.max(1, ow.texts.length);
					add(ratio > 0.3 ? 'fail' : 'warn', name + ': ' + realMissing.length + ' texter saknas (' + realMissing.slice(0, 3).map(function (t) { return '"' + short(t.t) + '"'; }).join(', ') + ').');
				}
				if (realExtra.length) {
					add('warn', name + ': ' + realExtra.length + ' nya texter (' + realExtra.slice(0, 3).map(function (t) { return '"' + short(t.t) + '"'; }).join(', ') + ').');
				}
				var moved = [];
				var styled = [];
				m.pairs.forEach(function (pair) {
					var a = pair[0], b = pair[1];
					if (Math.abs(a.x - b.x) > POS_TOL || Math.abs(a.y - b.y) > POS_TOL) {
						moved.push('"' + short(a.t) + '" ' + Math.round(b.x - a.x) + ', ' + Math.round(b.y - a.y) + ' px');
					}
					var sd = styleDiff(a, b);
					if (sd.length) {
						styled.push('"' + short(a.t) + '": ' + sd.join(', '));
					}
				});
				if (moved.length) {
					add('warn', name + ': ' + moved.length + ' texter flyttade (' + moved.slice(0, 2).join('; ') + ').');
				}
				if (styled.length) {
					add('warn', name + ': annan stil på ' + styled.length + ' texter (' + styled.slice(0, 2).join('; ') + ').');
				}
				var used = {};
				var imgMissing = [];
				var imgSized = [];
				ow.imgs.forEach(function (oi) {
					var k = -1;
					for (var i = 0; i < pw.imgs.length; i++) {
						if (!used[i] && pw.imgs[i].k === oi.k) { k = i; break; }
					}
					if (k < 0) { imgMissing.push(oi.k); return; }
					used[k] = true;
					var pi = pw.imgs[k];
					if (Math.abs(pi.w - oi.w) > POS_TOL || Math.abs(pi.h - oi.h) > POS_TOL || Math.abs(pi.x - oi.x) > POS_TOL || Math.abs(pi.y - oi.y) > POS_TOL) {
						imgSized.push(oi.k + ' ' + Math.round(oi.w) + '×' + Math.round(oi.h) + ' → ' + Math.round(pi.w) + '×' + Math.round(pi.h));
					}
				});
				if (imgMissing.length) {
					// Skilj på "borta ur sidkoden" och "finns men är dold/utan storlek" – avgörande för felsökning.
					var inDom = (pw.imgsTotal || 0) - pw.imgs.length;
					var why = (pw.imgsWhy || []).filter(function (w) { return imgMissing.some(function (k) { return w.indexOf(k + ':') === 0; }); });
					add(imgMissing.length === ow.imgs.length ? 'fail' : 'warn', name + ': ' + imgMissing.length + ' av ' + ow.imgs.length + ' bilder saknas (' + imgMissing.slice(0, 3).join(', ') + '). Nya versionen har ' + (pw.imgsTotal || 0) + ' bilder i sidkoden' + (inDom > 0 ? ', varav ' + inDom + ' dolda eller utan storlek' : '') + '.' + (why.length ? ' Orsak: ' + why.slice(0, 2).join('; ') + '.' : ''));
				}
				if (imgSized.length) {
					add('warn', name + ': ' + imgSized.length + ' bilder har annan storlek eller plats (' + imgSized.slice(0, 2).join('; ') + ').');
				}
			} else {
				// Innehållet skiljer sig – jämför vilka textstilar som förekommer.
				var os = {}, ps = {};
				ow.texts.forEach(function (t) { os[styleKey(t)] = t; });
				pw.texts.forEach(function (t) { ps[styleKey(t)] = t; });
				var lost = Object.keys(os).filter(function (k) { return !ps[k]; });
				if (lost.length) {
					add('warn', name + ': ' + lost.length + ' textstilar finns inte i den nya versionen (t.ex. ' + lost.slice(0, 2).map(function (k) { return '"' + short(os[k].t) + '" ' + k.replace(/\|/g, ' '); }).join('; ') + ').');
				}
				if (ow.imgs.length && !pw.imgs.length) {
					add('fail', name + ': bilderna saknas.');
				}
			}
		});
		var status = 'ok';
		issues.forEach(function (i) {
			if (i.level === 'fail') { status = 'fail'; }
			else if (i.level === 'warn' && status !== 'fail') { status = 'warn'; }
		});
		return { status: status, issues: issues };
	}

	function worst(list) {
		var order = { fail: 3, warn: 2, ok: 1, skip: 0 };
		var w = 'skip';
		list.forEach(function (s) { if (order[s] > order[w]) { w = s; } });
		return w;
	}

	/* ------------------------------------------------------------------ *
	 * Stabila selektorer för positionsberoende regler
	 * ------------------------------------------------------------------ */

	var EL_ID = /^elementor-element-(?!populated$)[0-9a-z]{2,12}$/;
	var SKIP_CLASS = /^(elementor-invisible|animated|is-|e-con-|elementor-animation|jet-|cbx-|elementor-motion|elementor-sticky|hover|focus|active|current)/;

	function cssEsc(s) {
		return String(s).replace(/([^\w-])/g, '\\$1');
	}

	function part(n) {
		var cls = Array.prototype.filter.call(n.classList || [], function (c) { return !SKIP_CLASS.test(c) && !EL_ID.test(c); }).slice(0, 2);
		return n.tagName.toLowerCase() + cls.map(function (c) { return '.' + cssEsc(c); }).join('');
	}

	/**
	 * En stabil selektor för ett element som en skör selektor träffar: från
	 * närmaste Elementor-element (dess unika ID-klass) ner till elementet, så
	 * kort som möjligt men entydig inom Elementor-elementet. Specificiteten
	 * höjs (ID ur originalet, klassen tre gånger) så att kopian vinner lika
	 * mycket som originalet gjorde.
	 */
	function stableFor(el, fragile) {
		var anchor = el;
		var idClass = null;
		while (anchor && anchor.nodeType === 1) {
			idClass = Array.prototype.filter.call(anchor.classList, function (c) { return EL_ID.test(c); })[0];
			if (idClass) { break; }
			anchor = anchor.parentElement;
		}
		if (!idClass) {
			return null;
		}
		var head = 'html body ';
		var idm = String(fragile || '').match(/#([\w-]+)/);
		if (idm && el.closest('#' + cssEsc(idm[1]))) {
			head += '#' + idm[1] + ' ';
		}
		var a = '.' + idClass + '.' + idClass + '.' + idClass;
		if (anchor === el) {
			return head + a;
		}
		var chain = [];
		for (var x = el; x && x !== anchor; x = x.parentElement) {
			chain.unshift(x);
		}
		var tail = part(el);
		var i = chain.length - 2;
		var direct = false;
		while (anchor.querySelectorAll(tail).length > 1 && i >= -1) {
			if (i >= 0) {
				tail = part(chain[i]) + ' > ' + tail;
			} else {
				direct = true;
			}
			i--;
		}
		return head + a + (direct ? ' > ' : ' ') + tail;
	}

	var api = { norm: norm, imgKey: imgKey, collect: collect, measure: measure, diff: diff, worst: worst, stableFor: stableFor, WIDTHS: WIDTHS };

	if (typeof module !== 'undefined' && module.exports) {
		module.exports = api;
	}
	root.CBXAuto = api;

	/* ------------------------------------------------------------------ *
	 * Webbläsarens körning
	 * ------------------------------------------------------------------ */

	if (typeof document === 'undefined' || typeof CBX === 'undefined') {
		return;
	}
	var card = document.querySelector('[data-cbx-auto]');
	if (!card) {
		return;
	}

	function post(data) {
		var body = new FormData();
		body.append('action', 'cbx_auto');
		body.append('nonce', CBX.nonce);
		Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
		return fetch(CBX.ajax, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) {
				return r.json().catch(function () {
					throw new Error('Servern svarade inte med JSON (HTTP ' + r.status + '). Kontrollera PHP-felloggen.');
				});
			})
			.then(function (json) {
				if (!json || !json.success) {
					throw new Error((json && json.data && json.data.message) || 'Något gick fel');
				}
				return json.data;
			});
	}

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function sleep(ms) {
		return new Promise(function (r) { setTimeout(r, ms); });
	}

	var stage = document.createElement('div');
	stage.className = 'cbx-auto-stage';
	stage.setAttribute('aria-hidden', 'true');
	document.body.appendChild(stage);

	var FREEZE = '*,*::before,*::after{animation-duration:0s!important;animation-delay:0s!important;transition:none!important;scroll-behavior:auto!important}' +
		'.elementor-invisible{visibility:visible!important}.animated{animation:none!important;opacity:1!important;transform:none!important}';

	function loadFrame(url, size) {
		return new Promise(function (resolve, reject) {
			var f = document.createElement('iframe');
			f.style.width = size.w + 'px';
			f.style.height = size.h + 'px';
			f.setAttribute('tabindex', '-1');
			var done = false;
			var timer = setTimeout(function () {
				if (!done) { done = true; f.remove(); reject(new Error('Sidan laddades inte inom 60 s: ' + url)); }
			}, 60000);
			f.addEventListener('load', function () {
				if (done) { return; }
				done = true;
				clearTimeout(timer);
				resolve(f);
			});
			f.src = url + (url.indexOf('?') >= 0 ? '&' : '?') + 'cbx_ap=' + Date.now();
			stage.appendChild(f);
		});
	}

	function settle(f) {
		var doc;
		try {
			doc = f.contentDocument;
		} catch (e) {
			return Promise.reject(new Error('Sidan kan inte läsas (annan domän?).'));
		}
		if (!doc || !doc.body) {
			return Promise.reject(new Error('Sidan laddades inte.'));
		}
		var st = doc.createElement('style');
		st.textContent = FREEZE;
		doc.head.appendChild(st);
		var imgs = Array.prototype.slice.call(doc.images);
		imgs.forEach(function (im) {
			im.loading = 'eager';
			im.decoding = 'sync';
			if (!im.getAttribute('src') && im.dataset && im.dataset.src) {
				im.src = im.dataset.src;
			}
		});
		var win = f.contentWindow;
		try { win.scrollTo(0, doc.documentElement.scrollHeight); } catch (e) {}
		var loaded = Promise.all(imgs.map(function (im) {
			return im.complete ? null : new Promise(function (r) { im.addEventListener('load', r); im.addEventListener('error', r); });
		}));
		var fonts = doc.fonts && doc.fonts.ready ? doc.fonts.ready : Promise.resolve();
		return Promise.race([Promise.all([loaded, fonts]), sleep(10000)])
			.then(function () {
				try { win.scrollTo(0, 0); win.dispatchEvent(new win.Event('resize')); } catch (e) {}
				return stable(doc);
			})
			.then(function () { return doc; });
	}

	/** Väntar tills layouten står still (skript som räknar ut rutnät, karuseller …). */
	function stable(doc) {
		function sig() {
			var s = doc.documentElement.scrollHeight + ':';
			var roots = doc.querySelectorAll('[data-elementor-id]');
			for (var i = 0; i < roots.length; i++) {
				var r = roots[i].getBoundingClientRect();
				s += Math.round(r.height) + ',';
			}
			return s;
		}
		var last = '';
		var same = 0;
		var tries = 0;
		return new Promise(function (resolve) {
			(function tick() {
				var now = sig();
				same = now === last ? same + 1 : 0;
				last = now;
				if (same >= 2 || ++tries > 25) {
					resolve();
					return;
				}
				setTimeout(tick, 300);
			})();
		});
	}

	function measureUrl(url, rootSel, ids, size) {
		return loadFrame(url, size).then(function (f) {
			return settle(f).then(function (doc) {
				var m = collect(doc, rootSel, ids);
				f.remove();
				return m;
			}, function (e) { f.remove(); throw e; });
		});
	}

	/* ---------- UI ---------- */

	var stepsEl = card.querySelector('[data-cbx-auto-steps]');
	var reportEl = card.querySelector('[data-cbx-auto-report]');
	var STATUS = { ok: ['OK', 'ok'], warn: ['Avvikelse', 'warn'], fail: ['Fel', 'bad'], skip: ['Ej jämförd', 'neutral'], run: ['Pågår', 'neutral'], wait: ['Väntar', 'neutral'] };

	var planSteps = [];
	var planLabels = {};
	var box = card.querySelector('[data-cbx-auto-box]');
	var nowEl = card.querySelector('[data-cbx-auto-now]');
	var barEl = box ? box.querySelector('.cbx-progress__bar') : null;

	function setStep(name, status, msg) {
		var idx = planSteps.indexOf(name);
		if (nowEl && idx >= 0 && (status === 'run' || status === 'fail')) {
			nowEl.textContent = (idx + 1) + ' av ' + planSteps.length + ': ' + (planLabels[name] || name) + (msg ? ' – ' + msg : '');
		}
		if (barEl && idx >= 0) {
			barEl.style.width = Math.round(((idx + (status === 'run' ? 0.5 : 1)) / planSteps.length) * 100) + '%';
		}
		var li = stepsEl.querySelector('[data-step="' + name + '"]');
		if (!li) { return; }
		var st = STATUS[status] || STATUS.wait;
		li.className = 'cbx-auto-step cbx-auto-step--' + status;
		li.querySelector('.cbx-badge').className = 'cbx-badge cbx-badge--' + st[1];
		li.querySelector('.cbx-badge').textContent = st[0];
		if (msg !== undefined) {
			li.querySelector('.cbx-auto-msg').textContent = msg;
		}
	}

	function renderSteps(steps, labels) {
		planSteps = steps;
		planLabels = labels;
		if (box) { box.hidden = false; }
		if (barEl) { barEl.style.width = '0%'; }
		stepsEl.innerHTML = steps.map(function (s) {
			return '<li class="cbx-auto-step" data-step="' + esc(s) + '"><span class="cbx-badge cbx-badge--neutral">Väntar</span> <strong>' + esc(labels[s] || s) + '</strong> <span class="cbx-auto-msg"></span></li>';
		}).join('');
	}

	function renderReport(report) {
		if (!report || !report.jobs) {
			reportEl.innerHTML = '';
			return;
		}
		var counts = { ok: 0, warn: 0, fail: 0, skip: 0 };
		report.jobs.forEach(function (j) { counts[j.status] = (counts[j.status] || 0) + 1; });
		var head = '<div class="cbx-stats">' +
			'<div class="ok"><strong>' + counts.ok + '</strong><span>identiska</span></div>' +
			'<div class="' + (counts.warn ? 'warn' : 'ok') + '"><strong>' + counts.warn + '</strong><span>med avvikelser</span></div>' +
			'<div class="' + (counts.fail ? 'bad' : 'ok') + '"><strong>' + counts.fail + '</strong><span>med fel</span></div>' +
			'<div><strong>' + counts.skip + '</strong><span>ej jämförda</span></div></div>';
		var verdict = report.verdict ? '<p class="cbx-auto-verdict cbx-auto-verdict--' + esc(report.verdictLevel || 'ok') + '">' + esc(report.verdict) + '</p>' : '';
		var meta = '<p class="cbx-meta">' + esc({ test: 'Testa', apply: 'Genomför', 'switch': 'Stäng av Crocoblock', undo: 'Ångra allt' }[report.mode] || report.mode) + ' · ' + esc(report.finished || report.started || '') + '</p>';
		var rows = report.jobs.map(function (j) {
			var st = STATUS[j.status] || STATUS.skip;
			var body = '';
			(j.results || []).forEach(function (r) {
				if (!r.issues || !r.issues.length) { return; }
				body += '<li><strong>' + esc(r.phase === 'verify' ? 'Publicerad sida, ' : r.phase === 'verify_switch' ? 'Utan Crocoblock, ' : '') + esc(r.width) + ':</strong><ul>' +
					r.issues.slice(0, 12).map(function (i) { return '<li class="cbx-issue cbx-issue--' + esc(i.level) + '">' + esc(i.msg) + '</li>'; }).join('') + '</ul></li>';
			});
			var links = (j.original_url ? '<a href="' + esc(j.original_url) + '" target="_blank" rel="noopener">original</a>' : '') +
				(j.preview_url && !report.cleaned ? ' · <a href="' + esc(j.preview_url) + '" target="_blank" rel="noopener">förhandsvisning</a>' : '');
			var note = j.note ? '<p class="description">' + esc(j.note) + '</p>' : '';
			var manual = j.manual ? '<p class="description">' + j.manual + ' widgets kan inte konverteras automatiskt och ligger kvar.</p>' : '';
			return '<details class="cbx-row"' + (j.status === 'fail' ? ' open' : '') + '><summary><span class="cbx-badge cbx-badge--' + st[1] + '">' + esc(st[0]) + '</span> ' + esc(j.label) + ' <span class="cbx-meta">' + links + '</span></summary>' + note + manual + (body ? '<ul class="cbx-log">' + body + '</ul>' : '') + '</details>';
		}).join('');
		reportEl.innerHTML = verdict + head + meta + rows;
	}

	function lock(on) {
		var els = Array.prototype.slice.call(card.querySelectorAll('button, input'))
			.concat(Array.prototype.slice.call(document.querySelectorAll('[data-cbx-scan],[data-cbx-batch]')));
		els.forEach(function (b) {
			if (on) {
				b.dataset.cbxWas = b.disabled ? '1' : '';
				b.disabled = true;
			} else {
				b.disabled = b.dataset.cbxWas === '1';
			}
		});
		card.classList.toggle('is-running', on);
	}

	/** Kör ett serversteg till slut (stegen som körs i omgångar). */
	function serverStep(step) {
		var offset = 0;
		function next() {
			return post({ op: 'step', step: step, offset: offset }).then(function (res) {
				if (!res.done) {
					offset = res.data && res.data.next ? res.data.next : offset + 1;
					var d = res.data || {};
					setStep(step, 'run', d.total ? d.processed + ' / ' + d.total : (d.processed ? d.processed + ' klara' : ''));
					return next();
				}
				return res;
			});
		}
		setStep(step, 'run', '');
		return next();
	}

	/** Slår upp de sköra selektorerna på originalsidorna. */
	/**
	 * Slår upp de sköra selektorerna på originalsidorna, i alla tre bredder:
	 * strukturen kan skilja (t.ex. parallax-lager bara på dator), och då ska
	 * den stabila kopian bara gälla i de bredder där originalet träffade.
	 */
	function stabilize(sels, jobs) {
		var pages = [];
		jobs.forEach(function (j) { if (j.original_url && pages.indexOf(j.original_url) < 0) { pages.push(j.original_url); } });
		pages = pages.slice(0, 40);
		var hits = {};   // sel → stabil → { bredd: true }
		var bp = { mobile: 767, tablet: 1024 };
		var withHits = [];
		function scan(url, size) {
			return loadFrame(url, size).then(function (f) {
				return settle(f).then(function (doc) {
					try {
						var b = f.contentWindow.elementorFrontendConfig.responsive.breakpoints;
						bp = { mobile: b.mobile.value, tablet: b.tablet.value };
					} catch (e) {}
					var any = false;
					sels.forEach(function (s) {
						var found;
						try { found = doc.querySelectorAll(s.query); } catch (e) { return; }
						Array.prototype.forEach.call(found, function (el) {
							var st = stableFor(el, s.sel);
							if (st) {
								any = true;
								hits[s.sel] = hits[s.sel] || {};
								hits[s.sel][st] = hits[s.sel][st] || {};
								hits[s.sel][st][size.name] = true;
							}
						});
					});
					f.remove();
					return any;
				}, function () { f.remove(); return false; });
			}, function () { return false; });
		}
		var chain = Promise.resolve();
		pages.forEach(function (url, n) {
			chain = chain.then(function () {
				setStep('stabilize', 'run', (n + 1) + ' / ' + pages.length + ' sidor');
				return scan(url, WIDTHS[0]).then(function (any) { if (any) { withHits.push(url); } });
			});
		});
		return chain.then(function () {
			var c2 = Promise.resolve();
			withHits.forEach(function (url, n) {
				WIDTHS.slice(1).forEach(function (size) {
					c2 = c2.then(function () {
						setStep('stabilize', 'run', 'kontrollerar ' + size.name + ', ' + (n + 1) + ' / ' + withHits.length + ' sidor');
						return scan(url, size);
					});
				});
			});
			return c2;
		}).then(function () {
			var media = {
				dator: '(min-width: ' + (bp.tablet + 1) + 'px)',
				surfplatta: '(min-width: ' + (bp.mobile + 1) + 'px) and (max-width: ' + bp.tablet + 'px)',
				mobil: '(max-width: ' + bp.mobile + 'px)'
			};
			var map = {};
			Object.keys(hits).forEach(function (sel) {
				map[sel] = Object.keys(hits[sel]).map(function (st) {
					var w = Object.keys(hits[sel][st]);
					return { s: st, media: w.length === WIDTHS.length ? [] : w.map(function (k) { return media[k]; }) };
				});
			});
			return map;
		});
	}

	function jobUrl(job, phase) {
		return phase === 'compare' ? job.preview_url : job.original_url;
	}

	/** Jämför alla jobb i tre bredder. phase: compare | verify | verify_switch */
	function compareJobs(jobs, phase, baseline, report) {
		var list = jobs.filter(function (j) { return j.original_url && j.preview_url; });
		var total = list.length * WIDTHS.length;
		var n = 0;
		var chain = Promise.resolve();
		list.forEach(function (job) {
			var entry = report.jobs.filter(function (r) { return r.key === job.key; })[0];
			WIDTHS.forEach(function (size) {
				chain = chain.then(function () {
					n++;
					setStep(phase, 'run', n + ' / ' + total + ' – ' + job.label + ' (' + size.name + ')');
					if (phase === 'baseline') {
						return measureUrl(job.original_url, job.original_root, job.widgets, size).then(function (m) {
							baseline[job.key + '|' + size.name] = m;
						}, function (e) {
							entry.results.push({ phase: phase, width: size.name, status: 'fail', issues: [{ level: 'fail', msg: e.message }] });
							entry.status = 'fail';
						});
					}
					var base = baseline[job.key + '|' + size.name];
					if (phase !== 'compare' && !base) {
						entry.results.push({ phase: phase, width: size.name, status: 'skip', issues: [{ level: 'info', msg: 'Originalet mättes inte före konverteringen.' }] });
						return;
					}
					var newRoot = phase === 'compare' ? job.preview_root : (phase === 'verify_switch' ? job.verify_root_switch : job.verify_root);
					var opts = { context: phase === 'compare' ? job.context : false, loose: job.loose };
					function pair() {
						var origP = phase === 'compare' ? measureUrl(job.original_url, job.original_root, job.widgets, size) : Promise.resolve(base);
						return Promise.all([origP, measureUrl(jobUrl(job, phase), newRoot, job.widgets, size)]);
					}
					// En skillnad bekräftas med en ny mätning innan den rapporteras –
					// sidor som laddar in innehåll med skript ger annars falsklarm.
					return pair().then(function (p1) {
						var d1 = diff(p1[0], p1[1], opts);
						if (d1.status === 'ok' || d1.status === 'skip') {
							return [p1, d1];
						}
						setStep(phase, 'run', n + ' / ' + total + ' – ' + job.label + ' (' + size.name + ', kontrollmäter)');
						return pair().then(function (p2) {
							var d2 = diff(p2[0], p2[1], opts);
							var order = { ok: 0, skip: 0, warn: 1, fail: 2 };
							return order[d2.status] <= order[d1.status] ? [p2, d2] : [p1, d1];
						}, function () { return [p1, d1]; });
					}).then(function (res) {
						if (phase === 'compare') {
							baseline[job.key + '|' + size.name] = res[0][0];
						}
						entry.results.push({ phase: phase, width: size.name, status: res[1].status, issues: res[1].issues });
					}, function (e) {
						entry.results.push({ phase: phase, width: size.name, status: 'fail', issues: [{ level: 'fail', msg: e.message }] });
					}).then(function () {
						entry.status = worst(entry.results.map(function (r) { return r.status; }));
						renderReport(report);
					});
				});
			});
		});
		return chain.then(function () {
			var statuses = list.map(function (job) {
				var e = report.jobs.filter(function (r) { return r.key === job.key; })[0];
				return worst(e.results.filter(function (r) { return r.phase === phase; }).map(function (r) { return r.status; }));
			});
			return statuses.length ? worst(statuses) : 'skip';
		});
	}

	/**
	 * Beslutsruta: körningen pausar tills användaren väljer. Med timeout räknas
	 * det förvalda (säkra) valet ner och körs automatiskt – används när
	 * Crocoblock är avstängt och sajten kan se trasig ut för besökare.
	 */
	function decide(opts) {
		return new Promise(function (resolve) {
			var box = document.createElement('div');
			box.className = 'cbx-auto-decide';
			box.innerHTML = '<p class="cbx-auto-decide__title">Ditt beslut behövs</p><p class="cbx-auto-decide__text">' + esc(opts.text) + '</p><p class="cbx-auto-decide__actions"></p>';
			var actions = box.querySelector('.cbx-auto-decide__actions');
			var timer = null;
			function done(key) {
				if (timer) { clearInterval(timer); }
				box.remove();
				resolve(key);
			}
			var countBtn = null;
			var countBase = '';
			opts.choices.forEach(function (c) {
				var b = document.createElement('button');
				b.type = 'button';
				b.className = 'button' + (c.primary ? ' button-primary' : '');
				b.dataset.key = c.key;
				b.textContent = c.label;
				b.addEventListener('click', function () { done(c.key); });
				actions.appendChild(b);
				if (c.key === opts.timeoutKey) { countBtn = b; countBase = c.label; }
			});
			reportEl.parentNode.insertBefore(box, reportEl);
			try { box.scrollIntoView({ block: 'center' }); } catch (e) {}
			var left = opts.timeout || 0;
			if (left && countBtn) {
				countBtn.textContent = countBase + ' (' + left + ' s)';
				timer = setInterval(function () {
					left--;
					countBtn.textContent = countBase + ' (' + left + ' s)';
					if (left <= 0) { done(opts.timeoutKey); }
				}, 1000);
			}
			if (nowEl) { nowEl.textContent = 'Pausad – väntar på ditt beslut (se rutan ovanför rapporten).'; }
		});
	}

	function runAutopilot(mode) {
		var opts = {
			csshero: !!(card.querySelector('[name="cbx_auto_csshero"]') || {}).checked,
			switch: !!(card.querySelector('[name="cbx_auto_switch"]') || {}).checked,
			allow_warn: !!(card.querySelector('[name="cbx_auto_allow_warn"]') || {}).checked
		};
		var question = {
			apply: 'Sajten testas först. Blir testet godkänt konverteras sidorna' + (opts.csshero ? ' och CSS Hero avvecklas' : '') + '. Vid minsta fel återställs allt automatiskt.\n\nHar du tagit en full backup av databasen? Fortsätt?',
			'switch': 'Crocoblock stängs av och de nya sidhuvudena, sidfötterna och popuperna publiceras. Sajten jämförs före och efter – skiljer något slås Crocoblock på igen automatiskt.\n\nFortsätt?',
			undo: 'Allt återställs: originalsidorna, Crocoblock, mallar och CSS Hero.\n\nFortsätt?'
		}[mode];
		if (question && !window.confirm(question)) {
			return;
		}
		lock(true);
		var report = { mode: mode, started: new Date().toLocaleString('sv-SE'), jobs: [], steps: {} };
		var baseline = {};
		var jobs = [];
		var stop = null;       // anledning att avbryta
		var rollback = false;
		var stale = false;     // sidan laddades före en uppdatering av tillägget

		function ok(status) {
			return status === 'ok' || status === 'skip' || (status === 'warn' && opts.allow_warn);
		}

		/**
		 * Paus vid avvikelse: användaren avgör om körningen ska fortsätta.
		 * När Crocoblock är avstängt (switch-stegen) kan sajten se trasig ut
		 * för besökare – då räknas det säkra valet ner och körs automatiskt.
		 */
		function askAbout(step, detail) {
			var off = /switch/.test(step); // Crocoblock är avstängt just nu
			var text, choices;
			if (step === 'compare') {
				text = 'Testet visar ' + detail + ' – granska rapporten nedan. Ingenting är ändrat på sajten än. Vill du konvertera ändå?';
				choices = [
					{ key: 'abort', label: 'Avbryt – ändra ingenting', primary: true },
					{ key: 'continue', label: 'Konvertera ändå' }
				];
			} else if (off) {
				text = 'Sajten skiljer sig från hur den såg ut med Crocoblock på' + (/http/.test(step) ? ' (' + detail + ')' : '') + ' – granska rapporten nedan. Crocoblock är AVSTÄNGT just nu, så besökarna kan se felet. Väljer du inget slås Crocoblock på igen när nedräkningen är klar.';
				choices = [
					{ key: 'rollback', label: 'Slå på Crocoblock igen', primary: true },
					{ key: 'keep', label: 'Behåll avstängt trots skillnaderna' }
				];
			} else {
				text = 'Den publicerade sajten skiljer sig från originalet' + (/http/.test(step) ? ' (' + detail + ')' : '') + ' – granska rapporten nedan. Crocoblock är fortfarande på. Vill du behålla konverteringen eller återställa allt?';
				choices = [
					{ key: 'rollback', label: 'Återställ allt', primary: true },
					{ key: 'keep', label: 'Behåll konverteringen' }
				];
			}
			var T = root.CBX_DECIDE_TIMEOUT != null ? root.CBX_DECIDE_TIMEOUT : 120;
			return decide({ text: text, choices: choices, timeout: off ? T : 0, timeoutKey: 'rollback' }).then(function (choice) {
				report.decisions = report.decisions || {};
				report.decisions[step] = choice;
				setStep(step, choice === 'continue' || choice === 'keep' ? 'warn' : 'fail', { continue: 'Du valde att fortsätta.', keep: 'Du valde att behålla.', abort: 'Du valde att avbryta.', rollback: 'Du valde att återställa.' }[choice]);
				if (choice === 'continue' || choice === 'keep') {
					return;
				}
				if (choice === 'abort') {
					stop = 'Du valde att avbryta – ingenting är ändrat.';
					return;
				}
				stop = 'Du valde att återställa.';
				rollback = true;
			});
		}

		post({ op: 'start', mode: mode, csshero: opts.csshero ? 1 : '', switch: opts.switch ? 1 : '', allow_warn: opts.allow_warn ? 1 : '' }).then(function (plan) {
			if (plan.version && plan.version !== CBX_JS_VERSION) {
				// Sidan laddades före en uppdatering av tillägget – gammal logik i
				// webbläsaren mot ny server ger fel beteende (t.ex. inga beslutspauser).
				lock(false);
				stale = true;
				window.alert('Tillägget har uppdaterats (' + plan.version + ') sedan den här sidan laddades. Sidan laddas om – klicka sedan på knappen igen.');
				if (!root.CBX_NO_RELOAD) { window.location.reload(); }
				throw new Error('stale');
			}
			renderSteps(plan.steps, plan.labels);
			var chain = Promise.resolve();
			var skipSwitch = false;
			plan.steps.forEach(function (step) {
				chain = chain.then(function () {
					if (stop && step !== 'cleanup') {
						setStep(step, 'skip', 'Hoppades över.');
						return;
					}
					if (skipSwitch && /switch/.test(step)) {
						setStep(step, 'skip', 'Hoppades över.');
						return;
					}
					if (step === 'cleanup') {
						var anyIssue = report.jobs.some(function (j) { return j.status === 'warn' || j.status === 'fail'; });
						if (anyIssue || (stop && !rollback)) {
							setStep(step, 'skip', 'Förhandsvisningarna finns kvar så att du kan titta på avvikelserna. De tas bort vid nästa godkända test eller med Ångra allt.');
							return;
						}
					}
					if (step === 'stabilize') {
						return serverStep(step).then(function (res) {
							var sels = (res.data && res.data.selectors) || [];
							if (!sels.length) {
								setStep(step, 'ok', res.message);
								return;
							}
							return stabilize(sels, jobs).then(function (map) {
								return post({ op: 'stabilize_save', map: JSON.stringify(map) });
							}).then(function (r2) {
								setStep(step, 'ok', r2.message);
								report.steps[step] = 'ok';
							});
						});
					}
					if (step === 'baseline') {
						setStep(step, 'run', '');
						return compareJobs(jobs, step, baseline, report).then(function (status) {
							setStep(step, status === 'fail' ? 'fail' : 'ok', status === 'fail' ? 'Sidor kunde inte mätas – se rapporten.' : 'Mätt i tre bredder.');
							if (status === 'fail') {
								stop = 'Sajten kunde inte mätas före avstängningen.';
							}
						});
					}
					if (step === 'compare' || step === 'verify' || step === 'verify_switch') {
						setStep(step, 'run', '');
						return compareJobs(jobs, step, baseline, report).then(function (status) {
							var msg = { ok: 'Identiskt i alla tre bredder.', warn: 'Avvikelser – se rapporten.', fail: 'Fel – se rapporten.', skip: 'Inget att jämföra.' }[status];
							setStep(step, status, msg);
							report.steps[step] = status;
							if (ok(status)) { return; }
							if (mode === 'test') {
								stop = 'Testet visar ' + (status === 'fail' ? 'fel' : 'avvikelser') + '.';
								return;
							}
							return askAbout(step, status === 'fail' ? 'fel' : 'avvikelser');
						});
					}
					return serverStep(step).then(function (res) {
						setStep(step, res.status, res.message);
						report.steps[step] = res.status;
						if ((step === 'previews' || step === 'live_jobs') && res.data && res.data.jobs) {
							jobs = res.data.jobs;
							report.jobs = jobs.map(function (j) {
								return { key: j.key, label: j.label, original_url: j.original_url, preview_url: j.preview_url, note: j.note, manual: j.manual, status: 'skip', results: [] };
							});
							renderReport(report);
						}
						if (step === 'switch' && res.data && res.data.skip_rest) {
							skipSwitch = true;
						}
						if (res.status === 'fail') {
							if (step === 'http_check' || step === 'http_check_switch') {
								// En bedömningsfråga, inte ett driftfel – låt användaren avgöra.
								return askAbout(step, res.message);
							}
							stop = res.message;
							rollback = ['convert', 'switch', 'csshero'].indexOf(step) >= 0;
						}
					});
				});
			});
			return chain;
		}).catch(function (e) {
			if (stale) { return; }
			stop = e.message;
			rollback = mode === 'apply';
		}).then(function () {
			if (stale) { return; }
			if (rollback) {
				return post({ op: 'step', step: 'rollback' }).then(function (res) {
					report.rolledBack = res.message;
				}, function (e) {
					report.rolledBack = 'Återställningen misslyckades: ' + e.message + ' Använd Ångra allt.';
				});
			}
		}).then(function () {
			if (stale) { return; }
			report.finished = new Date().toLocaleString('sv-SE');
			report.cleaned = report.steps.cleanup === 'ok';
			var compare = report.steps.compare;
			if (mode === 'undo') {
				report.verdictLevel = stop ? 'bad' : 'ok';
				report.verdict = stop ? 'Ångra misslyckades: ' + stop : 'Allt är återställt. ' + (stepsEl.querySelector('[data-step="undo"] .cbx-auto-msg') || {}).textContent;
			} else if (stop) {
				report.verdictLevel = 'bad';
				report.verdict = { apply: 'Inte genomfört. ', 'switch': 'Crocoblock är kvar. ', test: 'Stoppat. ' }[mode] + stop + (report.rolledBack ? ' Allt är återställt: ' + report.rolledBack : '');
			} else if (mode === 'switch') {
				report.verdictLevel = 'ok';
				report.verdict = 'Crocoblock är avstängt och sajten ser likadan ut som innan.';
			} else if (mode === 'test') {
				report.verdictLevel = compare === 'ok' ? 'ok' : (compare === 'warn' ? 'warn' : 'neutral');
				report.verdict = compare === 'ok' ? 'Godkänt: allt som konverteras ser likadant ut som originalet i alla tre bredder. Sajten är oförändrad.' :
					(compare === 'warn' ? 'Små avvikelser – se nedan. Sajten är oförändrad.' : 'Inget kunde jämföras visuellt. Sajten är oförändrad.');
			} else {
				report.verdictLevel = 'ok';
				report.verdict = 'Genomfört: sajten är konverterad och ser ut som originalet' + (report.steps.switch === 'ok' ? ', och Crocoblock är avstängt.' : '.') + (report.steps.switch === 'warn' ? ' Crocoblock är kvar eftersom något fortfarande använder det.' : '');
			}
			// Körningen gick vidare på användarens beslut – säg det i stället för "ser ut som originalet".
			var dec = report.decisions || {};
			var keptLive = ['verify', 'verify_switch', 'http_check', 'http_check_switch'].some(function (k) { return dec[k] === 'keep'; });
			if (!stop && (keptLive || dec.compare === 'continue')) {
				report.verdictLevel = 'warn';
				if (mode === 'switch') {
					report.verdict = 'Crocoblock är avstängt. Du godkände skillnaderna mot originalet – se rapporten.';
				} else if (keptLive) {
					report.verdict = 'Genomfört: sajten är konverterad. Du godkände skillnaderna mot originalet – se rapporten.';
				} else {
					report.verdict += ' Du godkände testets avvikelser.';
				}
			}
			renderReport(report);
			if (nowEl) { nowEl.textContent = report.verdict; }
			if (barEl) { barEl.style.width = '100%'; }
			return post({ op: 'report', report: JSON.stringify(report) }).catch(function () {});
		}).then(function () {
			if (stale) { return; }
			lock(false);
			if (mode !== 'test' && !root.CBX_NO_RELOAD) {
				// Statusen överst (konverterade sidor, Crocoblock på/av) ändrades – visa den nya.
				setTimeout(function () { window.location.href = window.location.href.split('&cbx_')[0].split('#')[0]; }, 2500);
			}
		});
	}

	card.querySelectorAll('[data-cbx-auto-run]').forEach(function (b) {
		b.addEventListener('click', function () { runAutopilot(b.getAttribute('data-cbx-auto-run')); });
	});

	if (CBX.autoReport) {
		renderReport(CBX.autoReport);
	}
})(typeof window !== 'undefined' ? window : globalThis);
