/* Webbläsartest av autopiloten: mätning, jämförelse och hela körningen
 * (test, genomför, automatisk återställning) mot en attrapp av admin-sidan.
 *
 *   node tests/browser/autopilot-test.js
 */
'use strict';

const http = require('http');
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const JS = fs.readFileSync(path.join(__dirname, '../../assets/autopilot.js'), 'utf8');
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');

let fails = 0;
let count = 0;
function ok(name, cond, extra) {
	count++;
	if (!cond) {
		fails++;
		console.log('FAIL: ' + name + (extra ? ' – ' + JSON.stringify(extra).slice(0, 600) : ''));
	}
}

/* ---------- Fixturer ---------- */

const POSTS = [
	{ t: 'Tjärdrottningen i Vindeln', e: 'Jag fick höra talas om Tjärdrottningen när jag bodde i Västerbotten', img: 'tjar' },
	{ t: 'Ebba von Eckermann - kvinnan som inte lät sig tystas', e: 'En berättelse om mod och frihet i en svår tid', img: 'ebba' },
	{ t: 'Fisket i Östersjön', e: 'Torsken har nästan försvunnit och fiskarna oroar sig', img: 'fisket' },
];
const FONT = 'font-family:Georgia,serif';

function jetWidget(id) {
	const items = POSTS.map((p) =>
		`<div class="jet-posts__item" style="padding:10px;box-sizing:border-box;width:100%"><div class="post-thumbnail"><a href="#"><img src="/img/${p.img}-768x512.png" width="300" height="200" style="display:block;width:100%;height:auto"></a></div>` +
		`<div class="jet-posts__inner-content"><h4 class="entry-title" style="margin:14px 0 10px;font-size:22px;line-height:1.1;${FONT};color:#272727;font-weight:400"><a href="#" style="color:inherit;text-decoration:none">${p.t}</a></h4>` +
		`<div class="entry-excerpt" style="font-size:16px;line-height:1.3;${FONT};color:#272727">${p.e}…</div></div></div>`).join('');
	return `<div class="elementor-element elementor-element-${id} elementor-widget-jet-posts"><div class="elementor-widget-container"><div class="jet-posts" style="display:flex;flex-wrap:wrap;margin:0 -10px">${items}</div></div></div>`;
}

function elWidget(id, opts) {
	opts = opts || {};
	const gap = opts.gap == null ? 20 : opts.gap;
	const items = POSTS.map((p) =>
		`<article class="elementor-post"><a class="elementor-post__thumbnail__link" href="#"><img src="/img/${p.img}-300x200.png" width="300" height="200" style="display:block;width:100%;height:auto"></a>` +
		`<div class="elementor-post__text"><h3 class="elementor-post__title" style="margin:14px 0 10px;font-size:${opts.fs || 22}px;line-height:1.1;${FONT};color:#272727;font-weight:400"><a href="#" style="color:inherit;text-decoration:none">${p.t}</a></h3>` +
		`<div class="elementor-post__excerpt"><p style="margin:0;font-size:16px;line-height:1.3;${FONT};color:#272727">${p.e}…</p></div></div></article>`).join('');
	// Elementors mobilmeny: dold, men finns i DOM:en.
	const dropdown = '<nav style="max-height:0;overflow:hidden"><a href="#">Dold menypunkt</a></nav><span aria-hidden="true" style="position:absolute">Skärmläsartext</span>';
	return `<div class="elementor-element elementor-element-${id} elementor-widget-posts"><div class="elementor-widget-container" style="padding:10px 0">${dropdown}<div class="elementor-posts-container" style="display:grid;row-gap:${gap}px">${items}</div></div></div>`;
}

// Som CSS Hero: en regel som pekar på en position i strukturen.
const FRAGILE = 'html > body > div.wrap > div > section > div:nth-child(2) > div:nth-child(3) > div > a';
const BUTTON = '<div class="elementor-element elementor-element-b1"><div class="elementor-widget-container"><a class="elementor-button" href="#" style="display:inline-block;padding:6px 12px">Ladda fler</a></div></div>';

function page(rootId, widget, opts) {
	opts = opts || {};
	// JetElements parallax lägger (bara på dator) ett eget element först i sektionen; konverteringen tar bort det.
	const parallax = opts.parallax ? '<script>if (innerWidth > 1024) { document.querySelector(".elementor-element-s1").insertAdjacentHTML("afterbegin", "<div class=\\"jet-parallax-section\\"></div>"); }</script>' : '';
	const stable = opts.stable && state.stable ? '<style id="cbx-stable-css">' + state.stable + '</style>' : '';
	return `<!doctype html><html><head><meta charset="utf-8"><title>t</title><style>body{margin:0;${FONT}} .wrap{width:300px;margin:40px auto} @media(max-width:500px){.wrap{width:280px}} .elementor-widget-jet-posts .jet-posts__item{animation:fade 2s}@keyframes fade{from{opacity:0;transform:translateY(40px)}to{opacity:1}} ${FRAGILE}{color:rgb(255, 0, 0);text-transform:uppercase}</style>${stable}</head>` +
		`<body><header style="height:60px;background:#111;color:#fff">Sidhuvud</header><div class="wrap"><div data-elementor-id="${rootId}" class="elementor elementor-${rootId}">` +
		`<section class="elementor-element elementor-element-s1"><div class="elementor-container"><p style="margin:0 0 10px">Ingress som inte ändras</p>${widget}${BUTTON}</div></section></div></div>${parallax}</body></html>`;
}

/* ---------- Attrapp av sajten ---------- */

const state = { converted: false, liveBroken: false, preview: 'good', calls: [], report: null, opts: null, stable: '', stableMap: null, noStabilize: false };

function route(url) {
	const u = new URL(url, 'http://x');
	if (u.pathname.startsWith('/img/')) {
		return { type: 'image/png', body: PNG };
	}
	if (u.pathname === '/admin') {
		return {
			type: 'text/html',
			body: `<!doctype html><html><head><meta charset="utf-8"></head><body>
				<section class="cbx-flow" data-cbx-auto>
					<input type="checkbox" name="cbx_auto_allow_warn">
					<button data-cbx-auto-run="test">Testa</button><button data-cbx-auto-run="apply">Genomför</button>
					<button data-cbx-auto-run="switch">Stäng av</button><button data-cbx-auto-run="disabled-demo" disabled>X</button>
					<button data-cbx-auto-run="undo">Ångra allt</button>
					<div data-cbx-auto-box hidden><div class="cbx-progress"><div class="cbx-progress__bar"></div><span data-cbx-auto-now></span></div>
					<details><ol data-cbx-auto-steps></ol></details></div><div data-cbx-auto-report></div>
				</section>
				<script>window.CBX = { ajax: '/ajax', nonce: 'n', autoReport: null }; window.CBX_NO_RELOAD = true;</script>
				<script src="/autopilot.js"></script></body></html>`,
		};
	}
	if (u.pathname === '/autopilot.js') {
		return { type: 'text/javascript', body: JS };
	}
	if (u.pathname === '/orig/home') {
		if (!state.converted) {
			return { type: 'text/html', body: page(230, jetWidget('e2'), { parallax: true }) };
		}
		return { type: 'text/html', body: page(230, state.liveBroken ? '' : elWidget('e2'), { stable: true }) };
	}
	if (u.pathname === '/prev/home') {
		const v = state.preview;
		const w = v === 'missing' ? '' : elWidget('e2', v === 'gap' ? { gap: 35 } : v === 'font' ? { fs: 20 } : {});
		return { type: 'text/html', body: page(6620, w, { stable: true }) };
	}
	return null;
}

const STEPS = {
	test: ['scan', 'content_types', 'templates', 'site_css', 'dry', 'previews', 'stabilize', 'compare', 'cleanup'],
	apply: ['scan', 'content_types', 'templates', 'site_css', 'dry', 'previews', 'stabilize', 'compare', 'convert', 'http_check', 'verify', 'cleanup'],
	switch: ['live_jobs', 'baseline', 'switch', 'http_check_switch', 'verify_switch'],
	undo: ['undo'],
};

function ajax(fields, base) {
	state.calls.push(fields.op + (fields.step ? ':' + fields.step : ''));
	if (fields.op === 'start') {
		state.opts = fields;
		const steps = STEPS[fields.mode].slice();
		if (fields.switch) {
			steps.splice(steps.indexOf('cleanup'), 0, 'switch', 'http_check_switch', 'verify_switch');
		}
		return { steps, labels: {}, mode: fields.mode };
	}
	if (fields.op === 'stabilize_save') {
		state.stableMap = JSON.parse(fields.map);
		const sels = state.stableMap[FRAGILE] || [];
		const decl = '{color:rgb(255, 0, 0);text-transform:uppercase}';
		state.stable = sels.map((e) => (e.media.length ? e.media.map((m) => '@media ' + m + '{' + e.s + decl + '}').join('') : e.s + decl)).join('');
		return { status: 'ok', message: sels.length + ' stabila', done: true };
	}
	if (fields.op === 'report') {
		state.report = JSON.parse(fields.report);
		return { saved: true };
	}
	const step = fields.step;
	if (step === 'dry' && fields.offset === '0') {
		return { status: 'ok', message: '', done: false, data: { next: 8, processed: 8 } };
	}
	if (step === 'live_jobs') {
		return { status: 'ok', message: '1 sida', done: true, data: { jobs: [
			{ key: 'live-230', label: 'Startsida', widgets: [], context: false, manual: 0, note: '', original_url: base + '/orig/home', original_root: '[data-elementor-id="230"]', preview_url: base + '/orig/home', preview_root: '[data-elementor-id="230"]', verify_root: '[data-elementor-id="230"]', verify_root_switch: '[data-elementor-id="230"]' },
		] } };
	}
	if (step === 'switch') {
		state.switched = true;
		if (state.breakOnSwitch) { state.liveBroken = true; }
	}
	if (step === 'undo') {
		state.converted = false;
		return { status: 'ok', message: '3 sidor återställda.', done: true };
	}
	if (step === 'stabilize') {
		return { status: 'ok', message: '', done: true, data: { selectors: state.noStabilize ? [] : [{ sel: FRAGILE, query: FRAGILE, pseudo: '' }] } };
	}
	if (step === 'previews') {
		return {
			status: 'ok', message: '1 förhandsvisning', done: true, data: {
				jobs: [
					{ key: 'doc-230', label: 'Startsida', widgets: ['e2', 's1'], context: false, manual: 0, note: '', original_url: base + '/orig/home', original_root: '[data-elementor-id="230"]', preview_url: base + '/prev/home', preview_root: '[data-elementor-id="6620"]', verify_root: '[data-elementor-id="230"]', verify_root_switch: '[data-elementor-id="230"]' },
					{ key: 'doc-9', label: 'Popup', widgets: [], context: false, manual: 1, note: 'Jämförs inte visuellt.', original_url: '', original_root: '', preview_url: base + '/prev/home', preview_root: '', verify_root: '', verify_root_switch: '' },
				],
			},
		};
	}
	if (step === 'convert') {
		state.converted = true;
	}
	if (step === 'rollback') {
		if (state.opts && state.opts.mode === 'switch') {
			state.switched = false;
			state.liveBroken = false;
			return { status: 'ok', message: 'Crocoblock på igen.', done: true };
		}
		state.converted = false;
		return { status: 'ok', message: '1 sidor återställda.', done: true };
	}
	if (step === 'compare' || step === 'verify' || step === 'verify_switch') {
		return { status: 'ok', message: '', done: true, data: { jobs: [] } };
	}
	return { status: 'ok', message: step + ' klart', done: true };
}

function parseMultipart(body) {
	const out = {};
	const re = /name="([^"]+)"\r\n\r\n([\s\S]*?)\r\n--/g;
	let m;
	while ((m = re.exec(body))) {
		out[m[1]] = m[2];
	}
	return out;
}

/* ---------- Körning ---------- */

(async () => {
	const server = http.createServer((req, res) => {
		if (req.url === '/ajax' && req.method === 'POST') {
			let body = '';
			req.on('data', (c) => { body += c; });
			req.on('end', () => {
				const data = ajax(parseMultipart(body), 'http://127.0.0.1:' + server.address().port);
				res.writeHead(200, { 'Content-Type': 'application/json' });
				res.end(JSON.stringify({ success: true, data }));
			});
			return;
		}
		const r = route(req.url);
		if (!r) {
			res.writeHead(404);
			res.end('nf');
			return;
		}
		res.writeHead(200, { 'Content-Type': r.type });
		res.end(r.body);
	});
	await new Promise((r) => server.listen(0, '127.0.0.1', r));
	const base = 'http://127.0.0.1:' + server.address().port;

	const browser = await chromium.launch();
	const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
	const pg = await ctx.newPage();
	pg.on('dialog', (d) => d.accept());
	pg.on('pageerror', (e) => ok('no page errors', false, e.message));

	/* --- Enhetstester av mätning och jämförelse i en riktig webbläsare --- */
	async function measurePair(variant) {
		state.preview = variant;
		state.converted = false;
		const o = await pg.goto(base + '/orig/home').then(() => pg.evaluate(() => CBXAuto.collect(document, '[data-elementor-id="230"]', ['e2'])));
		const p = await pg.goto(base + '/prev/home').then(() => pg.evaluate(() => CBXAuto.collect(document, '[data-elementor-id="6620"]', ['e2'])));
		return { o, p, d: await pg.evaluate(([a, b]) => CBXAuto.diff(a, b, {}), [o, p]) };
	}
	await pg.goto(base + '/admin');
	// collect/diff behöver bara CBXAuto – ladda skriptet på fixtursidorna.
	const FREEZE = '<style>*{animation:none!important;transition:none!important}</style>';
	await pg.route('**/orig/home', async (r) => { const res = await r.fetch(); r.fulfill({ response: res, body: (await res.text()).replace('</head>', FREEZE + '</head>').replace('</body>', '<script>' + JS + '</script></body>') }); });
	await pg.route('**/prev/home', async (r) => { const res = await r.fetch(); r.fulfill({ response: res, body: (await res.text()).replace('</head>', FREEZE + '</head>').replace('</body>', '<script>' + JS + '</script></body>') }); });

	// Vänta ut animationen i originalet (fryses av autopiloten i ramar, inte här).
	let m = await measurePair('good');
	ok('collect: texts measured', m.o.widgets.e2.texts.length === 6 && m.p.widgets.e2.texts.length === 6, [m.o.widgets.e2.texts.length, m.p.widgets.e2.texts.map((t) => t.t)]);
	ok('collect: hidden dropdown and aria-hidden ignored', !m.p.widgets.e2.texts.some((t) => /Dold|Skärmläsar/.test(t.t)));
	ok('collect: images keyed without size', m.o.widgets.e2.imgs.map((i) => i.k).join() === 'tjar,ebba,fisket' && m.p.widgets.e2.imgs.map((i) => i.k).join() === 'tjar,ebba,fisket');
	m = await measurePair('good');
	ok('diff: identical layout is ok', m.d.status === 'ok', m.d.issues);
	ok('diff: same height', Math.abs(m.o.widgets.e2.h - m.p.widgets.e2.h) < 1, [m.o.widgets.e2.h, m.p.widgets.e2.h]);

	m = await measurePair('gap');
	ok('diff: larger gap → warn', m.d.status === 'warn' && m.d.issues.some((i) => /höjd/.test(i.msg)) && m.d.issues.some((i) => /flyttade/.test(i.msg)), m.d.issues);

	m = await measurePair('font');
	ok('diff: font size → warn with style detail', m.d.status === 'warn' && m.d.issues.some((i) => /storlek 22px → 20px/.test(i.msg)), m.d.issues);

	m = await measurePair('missing');
	ok('diff: missing widget → fail', m.d.status === 'fail' && /saknas/.test(m.d.issues[0].msg), m.d.issues);

	const ctxDiff = await pg.evaluate(() => {
		const a = { widgets: { x: { visible: true, x: 0, y: 0, w: 100, h: 100, texts: [{ t: 'A', fs: '22px', ff: 'georgia', fw: '400', c: 'rgb(0, 0, 0)', tt: 'none', fst: 'normal', td: 'none' }], imgs: [{ k: 'a' }] } } };
		const b = { widgets: { x: { visible: true, x: 0, y: 0, w: 100, h: 300, texts: [{ t: 'Annat inlägg', fs: '22px', ff: 'georgia', fw: '400', c: 'rgb(0, 0, 0)', tt: 'none', fst: 'normal', td: 'none' }], imgs: [{ k: 'b' }] } } };
		return [CBXAuto.diff(a, b, { context: true }).status, CBXAuto.diff(a, b, {}).status];
	});
	ok('diff: context template compares styles only', ctxDiff[0] === 'ok' && ctxDiff[1] !== 'ok', ctxDiff);

	await pg.unroute('**/orig/home');
	await pg.unroute('**/prev/home');

	/* --- Hela körningen --- */
	async function run(mode, setup) {
		Object.assign(state, { converted: false, liveBroken: false, preview: 'good', calls: [], report: null, stable: '', stableMap: null, noStabilize: false, switched: false, breakOnSwitch: false }, setup || {});
		await pg.goto(base + '/admin');
		if (setup && setup.allowWarn) {
			await pg.check('[name="cbx_auto_allow_warn"]');
		}
		await pg.click('[data-cbx-auto-run="' + mode + '"]');
		await pg.waitForFunction(() => !document.querySelector('[data-cbx-auto-run]').disabled && document.querySelector('.cbx-auto-verdict'), null, { timeout: 120000 });
		return {
			verdict: await pg.textContent('.cbx-auto-verdict'),
			steps: await pg.$$eval('[data-step]', (els) => els.map((e) => e.getAttribute('data-step') + ':' + e.className.replace(/.*--/, ''))),
			report: state.report,
			calls: state.calls.slice(),
		};
	}

	let r = await run('test');
	ok('run test: verdict ok', /^Godkänt/.test(r.verdict), r.verdict);
	ok('run test: compare ok in 3 widths', r.report.jobs[0].status === 'ok' && r.report.jobs[0].results.length === 3, r.report.jobs[0]);
	ok('run test: popup not compared', r.report.jobs[1].status === 'skip');
	ok('run test: cleanup ran', r.calls.includes('step:cleanup'));
	ok('run test: no convert', !r.calls.includes('step:convert'));
	ok('run test: dry ran in batches', r.calls.filter((c) => c === 'step:dry').length === 2);
	ok('run test: report saved', r.calls.includes('report'));

	ok('run test: fragile rule resolved to stable selector, desktop only', r.calls.includes('stabilize_save') && JSON.stringify(state.stableMap[FRAGILE]) === JSON.stringify([{ s: 'html body .elementor-element-b1.elementor-element-b1.elementor-element-b1 a.elementor-button', media: ['(min-width: 1025px)'] }]), state.stableMap);

	r = await run('test', { noStabilize: true });
	ok('run test without stabilizing: lost CSS Hero style detected', r.report.jobs[0].status === 'warn' && JSON.stringify(r.report.jobs[0].results).indexOf('Ladda fler') >= 0 && JSON.stringify(r.report.jobs[0].results).indexOf('versaler uppercase') >= 0, r.report.jobs[0].results[0]);

	r = await run('test', { preview: 'gap' });
	ok('run test gap: warn verdict', /avvikelser/i.test(r.verdict) && r.report.jobs[0].status === 'warn', r.verdict);
	ok('run test gap: previews kept', !r.calls.includes('step:cleanup'));
	ok('run test gap: issues listed', (await pg.$$('.cbx-issue--warn')).length > 0);

	r = await run('apply');
	ok('run apply: done', /^Genomfört/.test(r.verdict), r.verdict);
	ok('run apply: converted and verified', r.calls.includes('step:convert') && r.report.jobs[0].results.filter((x) => x.phase === 'verify').every((x) => x.status === 'ok'), r.report.jobs[0].results);
	ok('run apply: no rollback', !r.calls.includes('step:rollback'));
	ok('run apply: site stays converted', state.converted === true);

	r = await run('apply', { preview: 'gap' });
	ok('run apply gap: stopped before convert', !r.calls.includes('step:convert') && /Inte genomfört/.test(r.verdict), r.calls);

	r = await run('apply', { preview: 'gap', allowWarn: true });
	ok('run apply gap allowed: converts', r.calls.includes('step:convert'));
	ok('run apply gap allowed: live differs from baseline → still ok since live = good', /Genomfört|Inte/.test(r.verdict));

	// Konverteringen gick bra i förhandsvisningen men den publicerade sidan blev trasig.
	state.liveBroken = true;
	r = await run('apply', { liveBroken: true });
	ok('run apply broken live: rollback', r.calls.includes('step:rollback') && state.converted === false, r.calls);
	ok('run apply broken live: verdict', /Inte genomfört/.test(r.verdict) && /återställt/i.test(r.verdict), r.verdict);
	ok('run apply broken live: fail recorded', r.report.jobs[0].results.some((x) => x.phase === 'verify' && x.status === 'fail'));

	// Steg 3: stäng av Crocoblock – sajten mäts före och efter.
	r = await run('switch', { converted: true });
	ok('run switch: ok', /avstängt/.test(r.verdict) && r.calls.includes('step:switch') && !r.calls.includes('step:rollback'), r.verdict);
	ok('run switch: baseline measured then verified', r.report.jobs[0].results.some((x) => x.phase === 'verify_switch' && x.status === 'ok'), r.report.jobs[0].results);
	ok('run switch: progress line shows verdict', /avstängt/.test(await pg.textContent('[data-cbx-auto-now]')));
	ok('run switch: disabled button stays disabled', await pg.$eval('[data-cbx-auto-run="disabled-demo"]', (b) => b.disabled));

	r = await run('switch', { converted: true, breakOnSwitch: true });
	ok('run switch broken: rolled back', r.calls.includes('step:rollback') && state.switched === false && /Crocoblock är kvar/.test(r.verdict), [r.calls, r.verdict]);

	r = await run('undo', { converted: true });
	ok('run undo: restored', /Allt är återställt/.test(r.verdict) && state.converted === false && r.calls.includes('step:undo'), r.verdict);

	// Rapporten visas igen efter omladdning.
	await pg.goto(base + '/admin');
	await pg.evaluate((rep) => { window.CBX.autoReport = rep; }, r.report);

	await browser.close();
	server.close();
	console.log(fails ? `\n${fails} av ${count} webbläsarkontroller misslyckades` : `Alla ${count} webbläsarkontroller OK`);
	process.exit(fails ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
