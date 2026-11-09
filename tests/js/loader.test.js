import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { loadScript } from './helpers.js';

const BOOT = { v: 2, cats: ['necessary', 'preferences', 'analytics', 'marketing'], days: 180 };

function start(boot = BOOT) {
	delete window.cmk;
	window.cmkBoot = boot;
	loadScript('cmk-loader.js');
}

function setConsent(value) {
	const raw = typeof value === 'string' ? value : encodeURIComponent(JSON.stringify(value));
	document.cookie = 'cmk_consent=' + raw + '; Path=/';
}

function valid(categories, overrides = {}) {
	return { v: 2, c: categories, t: 1700000000, ...overrides };
}

/** Scripts that are not inert: what the browser would actually run. */
function live() {
	return [...document.querySelectorAll('script:not([type="text/plain"])')];
}

// jsdom really executes activated scripts, in its own context (not the Node global that `window` is here),
// so give the sample snippets harmless globals there to call.
const SNIPPET_GLOBALS = ['a', 'm', 'two', 'late'];

beforeEach(() => {
	document.body.innerHTML = '';
	SNIPPET_GLOBALS.forEach((name) => {
		jsdom.window[name] = () => {};
	});
});

afterEach(() => {
	// Remove any cookie override first: a test may have made writes throw.
	if (Object.getOwnPropertyDescriptor(document, 'cookie')) delete document.cookie;
	if (Object.getOwnPropertyDescriptor(document, 'readyState')) delete document.readyState;
	document.cookie = 'cmk_consent=; Path=/; Max-Age=0';
	delete window.cmk;
	delete window.cmkBoot;
	if (Object.getOwnPropertyDescriptor(document, 'currentScript')) delete document.currentScript;
	SNIPPET_GLOBALS.forEach((name) => {
		delete jsdom.window[name];
	});
});

describe('without consent', () => {
	it('reports no choice and only the necessary category', () => {
		start();

		expect(window.cmk.get()).toBeNull();
		expect(window.cmk.has('necessary')).toBe(true);
		expect(window.cmk.has('analytics')).toBe(false);
	});

	it('leaves non-necessary scripts inert', () => {
		document.body.innerHTML = '<script type="text/plain" data-cmk-category="analytics">window.__ran = 1;</script>';
		start();

		expect(live()).toHaveLength(0);
		expect(document.querySelector('script[data-cmk-done]')).toBeNull();
	});

	it('still runs scripts marked necessary', () => {
		document.body.innerHTML = '<script type="text/plain" data-cmk-category="necessary">window.__n = 1;</script>';
		start();

		expect(live()).toHaveLength(1);
		expect(live()[0].text).toBe('window.__n = 1;');
	});

	it('never activates a script with a missing, unknown or hostile category', () => {
		document.body.innerHTML = [
			'<script type="text/plain">a()</script>',
			'<script type="text/plain" data-cmk-category="">b()</script>',
			'<script type="text/plain" data-cmk-category="bogus">c()</script>',
			'<script type="text/plain" data-cmk-category="&lt;script&gt;">d()</script>',
		].join('');
		setConsent(valid(['necessary', 'analytics', 'marketing', 'preferences']));
		start();

		expect(live()).toHaveLength(0);
	});
});

describe('with a valid consent cookie', () => {
	it('activates only the consented categories', () => {
		document.body.innerHTML =
			'<script type="text/plain" data-cmk-category="analytics">a()</script>' +
			'<script type="text/plain" data-cmk-category="marketing">m()</script>';
		setConsent(valid(['necessary', 'analytics']));
		start();

		expect(live().map((s) => s.text)).toEqual(['a()']);
		expect(window.cmk.get().c).toEqual(['necessary', 'analytics']);
		expect(window.cmk.has('analytics')).toBe(true);
		expect(window.cmk.has('marketing')).toBe(false);
	});

	it('keeps document order and copies attributes, async off for external scripts', () => {
		document.body.innerHTML =
			'<script type="text/plain" data-cmk-category="analytics" src="/one.js" data-x="1"></script>' +
			'<script type="text/plain" data-cmk-category="analytics">two()</script>' +
			'<script type="text/plain" data-cmk-category="analytics" src="/three.js"></script>';
		setConsent(valid(['necessary', 'analytics']));
		start();

		const scripts = live();
		expect(scripts.map((s) => s.getAttribute('src') || s.text)).toEqual(['/one.js', 'two()', '/three.js']);
		expect(scripts[0].getAttribute('data-x')).toBe('1');
		expect(scripts[0].async).toBe(false);
		expect(scripts[0].hasAttribute('data-cmk-category')).toBe(false);
	});

	it('restores a remembered script type such as module', () => {
		document.body.innerHTML = '<script type="text/plain" data-cmk-category="analytics" data-cmk-type="module">import("/x.js")</script>';
		setConsent(valid(['necessary', 'analytics']));
		start();

		expect(live()[0].type).toBe('module');
	});

	it('activates each script at most once', () => {
		document.body.innerHTML = '<script type="text/plain" data-cmk-category="analytics">a()</script>';
		setConsent(valid(['necessary', 'analytics']));
		start();
		window.cmk.activate();
		window.cmk.activate();

		expect(live()).toHaveLength(1);
	});

	it('picks up inert scripts added later when activate() is called again', () => {
		setConsent(valid(['necessary', 'analytics']));
		start();
		document.body.insertAdjacentHTML('beforeend', '<script type="text/plain" data-cmk-category="analytics">late()</script>');
		window.cmk.activate();

		expect(live().map((s) => s.text)).toEqual(['late()']);
	});
});

describe('a stale, foreign or hostile cookie is no consent at all', () => {
	const cases = {
		'older policy version': valid(['necessary', 'analytics'], { v: 1 }),
		'newer policy version': valid(['necessary', 'analytics'], { v: 3 }),
		'unknown handle (category deleted or renamed)': valid(['necessary', 'analytics', 'bogus']),
		'handle is an object': valid(['necessary', { a: 1 }]),
		'handle is a script tag': valid(['necessary', '<script>alert(1)</script>']),
		'c is a string': valid('analytics'),
		'c missing necessary': valid(['analytics']),
		'time missing': { v: 2, c: ['necessary'] },
		'time is a string': valid(['necessary'], { t: 'now' }),
		'version is a string': valid(['necessary'], { v: '2' }),
		'json array': [2, ['necessary']],
		'json null': null,
		'json number': 5,
		'prototype pollution attempt': '{"__proto__":{"v":2,"c":["necessary"],"t":1}}',
	};

	Object.entries(cases).forEach(([name, value]) => {
		it(name, () => {
			document.body.innerHTML = '<script type="text/plain" data-cmk-category="analytics">a()</script>';
			setConsent(typeof value === 'string' ? encodeURIComponent(value) : value);
			start();

			expect(window.cmk.get()).toBeNull();
			expect(live()).toHaveLength(0);
		});
	});

	['%E0%A4%A', encodeURIComponent('not json'), '', encodeURIComponent('{"v":2,'), 'x'.repeat(5000)].forEach((raw) => {
		it('survives garbage: ' + raw.slice(0, 20), () => {
			setConsent(raw);
			expect(() => start()).not.toThrow();
			expect(window.cmk.get()).toBeNull();
		});
	});
});

describe('save()', () => {
	it('stores necessary plus the chosen known categories, activates them and reports the change', () => {
		document.body.innerHTML = '<script type="text/plain" data-cmk-category="analytics">a()</script>';
		start();
		const seen = [];
		window.cmk.on('consent:changed', (event) => seen.push(event));

		const saved = window.cmk.save(['analytics']);

		expect(saved).toEqual(['necessary', 'analytics']);
		expect(window.cmk.get().c).toEqual(['necessary', 'analytics']);
		expect(live().map((s) => s.text)).toEqual(['a()']);
		expect(seen).toEqual([{ categories: ['necessary', 'analytics'], previous: null }]);
	});

	it('drops unknown, hostile and duplicate handles and always keeps necessary', () => {
		start();

		expect(window.cmk.save(['bogus', '<script>', 'analytics', 'analytics', 5, null])).toEqual(['necessary', 'analytics']);
		expect(window.cmk.save([])).toEqual(['necessary']);
		expect(window.cmk.save('analytics')).toEqual(['necessary']);
		expect(window.cmk.save(undefined)).toEqual(['necessary']);
	});

	it('round-trips through the cookie so the next page load sees the same choice', () => {
		start();
		window.cmk.save(['marketing']);

		start(); // a new page load reads the cookie again

		expect(window.cmk.get().c).toEqual(['necessary', 'marketing']);
		expect(window.cmk.get().v).toBe(2);
	});

	it('reports the previous choice so callers can see what was withdrawn', () => {
		setConsent(valid(['necessary', 'analytics']));
		start();
		const seen = [];
		window.cmk.on('consent:changed', (event) => seen.push(event));

		window.cmk.save([]);

		expect(seen[0]).toEqual({ categories: ['necessary'], previous: ['necessary', 'analytics'] });
	});

	it('writes a first-party, script-readable cookie with the documented attributes', () => {
		const writes = [];
		Object.defineProperty(document, 'cookie', { configurable: true, get: () => '', set: (value) => writes.push(value) });
		start();

		window.cmk.save(['analytics']);

		const written = writes.at(-1);
		expect(written).toMatch(/^cmk_consent=/);
		expect(written).toContain('Path=/');
		expect(written).toContain('Max-Age=15552000'); // 180 days
		expect(written).toContain('SameSite=Lax');
		expect(written).not.toContain('HttpOnly');
		expect(JSON.parse(decodeURIComponent(written.split(';')[0].split('=')[1]))).toMatchObject({ v: 2, c: ['necessary', 'analytics'] });
	});

	it('still applies the choice for this page view when cookies cannot be written', () => {
		document.body.innerHTML = '<script type="text/plain" data-cmk-category="analytics">a()</script>';
		Object.defineProperty(document, 'cookie', {
			configurable: true,
			get: () => '',
			set: () => {
				throw new Error('cookies are blocked');
			},
		});
		start();

		expect(() => window.cmk.save(['analytics'])).not.toThrow();
		expect(live().map((s) => s.text)).toEqual(['a()']);
		expect(window.cmk.get().c).toEqual(['necessary', 'analytics']);
	});

	it('copes with writes that are silently ignored (cookies disabled)', () => {
		document.body.innerHTML = '<script type="text/plain" data-cmk-category="analytics">a()</script>';
		Object.defineProperty(document, 'cookie', { configurable: true, get: () => '', set: () => {} });
		start();

		window.cmk.save(['analytics']);

		expect(live()).toHaveLength(1);
	});

	it('isolates a throwing listener from the others', () => {
		start();
		const calls = [];
		window.cmk.on('consent:changed', () => {
			throw new Error('boom');
		});
		window.cmk.on('consent:changed', () => calls.push('second'));

		expect(() => window.cmk.save([])).not.toThrow();
		expect(calls).toEqual(['second']);
	});
});

describe('boot data', () => {
	it('defaults the lifetime and ignores invalid category handles in boot', () => {
		const writes = [];
		Object.defineProperty(document, 'cookie', { configurable: true, get: () => '', set: (value) => writes.push(value) });
		start({ v: 2, cats: ['necessary', 'analytics', '<script>', 5, 'analytics'] });

		expect(window.cmk.known).toEqual(['necessary', 'analytics']);
		window.cmk.save(['analytics']);
		expect(writes.at(-1)).toContain('Max-Age=15552000');
	});

	it('does not run twice if the loader is inlined twice', () => {
		start();
		const first = window.cmk;
		loadScript('cmk-loader.js');

		expect(window.cmk).toBe(first);
	});

	it('defers activation until the DOM is parsed when the document is still loading', () => {
		document.body.innerHTML = '<script type="text/plain" data-cmk-category="analytics">a()</script>';
		setConsent(valid(['necessary', 'analytics']));
		let readyState = 'loading';
		Object.defineProperty(document, 'readyState', { configurable: true, get: () => readyState });

		start();
		expect(live()).toHaveLength(0);

		readyState = 'interactive';
		document.dispatchEvent(new Event('DOMContentLoaded'));
		expect(live()).toHaveLength(1);
	});
});

describe('hardening', () => {
	it('treats an unreadable cookie jar as no consent and still exposes the full API', () => {
		document.body.innerHTML =
			'<script type="text/plain" data-cmk-category="analytics">a()</script>' +
			'<script type="text/plain" data-cmk-category="necessary">m()</script>';
		Object.defineProperty(document, 'cookie', {
			configurable: true,
			get: () => {
				throw new Error('SecurityError');
			},
			set: () => {},
		});

		expect(() => start()).not.toThrow();
		expect(window.cmk).toBeDefined();
		expect(window.cmk.get()).toBeNull();
		expect(window.cmk.has('necessary')).toBe(true);
		expect(window.cmk.has('analytics')).toBe(false);
		expect(live().map((s) => s.text)).toEqual(['m()']);

		expect(() => window.cmk.save(['analytics'])).not.toThrow();
		expect(live().map((s) => s.text)).toEqual(['a()', 'm()']);
	});

	it('tolerates event names that collide with Object.prototype members', () => {
		start();

		expect(() => {
			window.cmk.on('__proto__', () => {});
			window.cmk.on('constructor', () => {});
			window.cmk.on('toString', () => {});
		}).not.toThrow();
		expect(() => window.cmk.save([])).not.toThrow();
	});

	it('always treats necessary as known, even when boot omits it', () => {
		start({ v: 2, cats: ['analytics'], days: 180 });

		expect(window.cmk.known).toContain('necessary');
		window.cmk.save(['analytics']);

		start({ v: 2, cats: ['analytics'], days: 180 }); // next page load

		expect(window.cmk.get().c).toEqual(['necessary', 'analytics']);
	});
});

describe('nonce mode', () => {
	const NONCE_BOOT = { ...BOOT, nonce: true };

	// jsdom gives eval'd code no document.currentScript, so stand in for the inline loader tag.
	function ownScript(nonce, viaAttribute = false) {
		Object.defineProperty(document, 'currentScript', {
			configurable: true,
			get: () => (viaAttribute ? { nonce: '', getAttribute: () => nonce } : { nonce, getAttribute: () => '' }),
		});
	}

	function inertWithNonce(category, code, nonce) {
		const el = document.createElement('script');
		el.type = 'text/plain';
		el.setAttribute('data-cmk-category', category);
		if (nonce) {
			el.setAttribute('nonce', nonce);
			try {
				el.nonce = nonce;
			} catch (e) {
				/* property not supported by this jsdom */
			}
		}
		el.text = code;
		document.body.appendChild(el);
		return el;
	}

	it('activates only inert scripts whose nonce equals the loader own nonce, and keeps it', () => {
		ownScript('abc123');
		inertWithNonce('analytics', 'a()', 'abc123');
		setConsent(valid(['necessary', 'analytics']));
		start(NONCE_BOOT);

		const scripts = live();
		expect(scripts.map((s) => s.text)).toEqual(['a()']);
		expect(scripts[0].nonce || scripts[0].getAttribute('nonce')).toBe('abc123');
	});

	it('does not activate an inert script with a different nonce', () => {
		ownScript('abc123');
		inertWithNonce('analytics', 'a()', 'attacker');
		setConsent(valid(['necessary', 'analytics']));
		start(NONCE_BOOT);

		expect(live()).toHaveLength(0);
		expect(document.querySelector('script[data-cmk-done]')).toBeNull();
	});

	it('does not activate an inert script without a nonce', () => {
		ownScript('abc123');
		inertWithNonce('analytics', 'a()');
		setConsent(valid(['necessary', 'analytics']));
		start(NONCE_BOOT);

		expect(live()).toHaveLength(0);
	});

	it('does not activate a necessary script with a wrong nonce', () => {
		ownScript('abc123');
		inertWithNonce('necessary', 'm()', 'wrong');
		start(NONCE_BOOT);

		expect(live()).toHaveLength(0);
	});

	it('fails closed but keeps the API when the loader has no current script', () => {
		inertWithNonce('necessary', 'm()', 'abc123');
		inertWithNonce('analytics', 'a()', 'abc123');
		setConsent(valid(['necessary', 'analytics']));
		start(NONCE_BOOT);

		expect(live()).toHaveLength(0);
		expect(window.cmk.get().c).toEqual(['necessary', 'analytics']);
		expect(window.cmk.has('necessary')).toBe(true);
	});

	it('fails closed when the loader tag carries no nonce, and the API still works', () => {
		ownScript('');
		inertWithNonce('analytics', 'a()', 'abc123');
		inertWithNonce('analytics', 'two()');
		start(NONCE_BOOT);

		expect(live()).toHaveLength(0);
		expect(window.cmk.get()).toBeNull();
		expect(window.cmk.has('necessary')).toBe(true);
		expect(window.cmk.save(['analytics'])).toEqual(['necessary', 'analytics']);
		expect(live()).toHaveLength(0);
	});

	it('reads the loader nonce from the attribute when the property is empty', () => {
		ownScript('abc123', true);
		inertWithNonce('analytics', 'a()', 'abc123');
		setConsent(valid(['necessary', 'analytics']));
		start(NONCE_BOOT);

		expect(live().map((s) => s.text)).toEqual(['a()']);
	});

	it('leaves behaviour unchanged when nonce mode is off', () => {
		ownScript('abc123');
		inertWithNonce('analytics', 'a()');
		setConsent(valid(['necessary', 'analytics']));
		start({ ...BOOT, nonce: false });

		expect(live().map((s) => s.text)).toEqual(['a()']);
	});
});

const GUID = '3f2504e0-4f89-11d3-9a0c-0305e82c3301';

describe('the consent id', () => {
	it('is read from a valid cookie and exposed', () => {
		setConsent(valid(['necessary', 'analytics'], { i: GUID }));
		start();

		expect(window.cmk.get().i).toBe(GUID);
		expect(window.cmk.id()).toBe(GUID);
		expect(window.cmk.reconsent()).toBe(false);
	});

	it('drops a malformed or hostile id but keeps the choice', () => {
		setConsent(valid(['necessary', 'analytics'], { i: '"><script>alert(1)</script>' }));
		start();

		expect(window.cmk.get().c).toEqual(['necessary', 'analytics']);
		expect(window.cmk.get().i).toBeUndefined();
		expect(window.cmk.id()).toBe('');
	});

	it('rejects an id that is not lowercase hex', () => {
		setConsent(valid(['necessary'], { i: GUID.toUpperCase() }));
		start();

		expect(window.cmk.id()).toBe('');
	});

	it('keeps the id of a cookie from an older policy version and flags re-consent', () => {
		setConsent(valid(['necessary', 'analytics'], { v: 1, i: GUID }));
		start();

		expect(window.cmk.get()).toBeNull();
		expect(window.cmk.reconsent()).toBe(true);
		expect(window.cmk.id()).toBe(GUID);
	});

	it('does not flag re-consent for no cookie or a garbage cookie', () => {
		start();
		expect(window.cmk.reconsent()).toBe(false);

		setConsent('%7Bnot-json');
		start();
		expect(window.cmk.reconsent()).toBe(false);
		expect(window.cmk.id()).toBe('');
	});

	it('writes the id back into the cookie on the next save, so it stays constant', () => {
		setConsent(valid(['necessary'], { v: 1, i: GUID }));
		start();
		window.cmk.save(['analytics']);

		const stored = JSON.parse(decodeURIComponent(document.cookie.split('; ').find((p) => p.startsWith('cmk_consent=')).slice(12)));
		expect(stored.i).toBe(GUID);
		expect(stored.v).toBe(2);
		expect(window.cmk.reconsent()).toBe(false);
	});

	it('setId stores a well-formed id and refuses anything else', () => {
		start();
		window.cmk.save(['analytics']);

		expect(window.cmk.setId('nope')).toBe(false);
		expect(window.cmk.setId('<b>')).toBe(false);
		expect(window.cmk.id()).toBe('');
		expect(window.cmk.setId(GUID)).toBe(true);
		expect(window.cmk.id()).toBe(GUID);
		expect(JSON.parse(decodeURIComponent(document.cookie.split('; ').find((p) => p.startsWith('cmk_consent=')).slice(12))).i).toBe(GUID);
	});

	it('setId does nothing when there is no choice yet', () => {
		start();

		expect(window.cmk.setId(GUID)).toBe(false);
		expect(document.cookie).not.toContain('cmk_consent');
	});
});

describe('Consent Mode v2', () => {
	const SIGNALS = [
		'ad_storage',
		'ad_user_data',
		'ad_personalization',
		'analytics_storage',
		'functionality_storage',
		'personalization_storage',
		'security_storage'
	];
	const calls = () => window.dataLayer.map((args) => [...args]);

	beforeEach(() => {
		delete window.dataLayer;
		delete window.gtag;
	});

	it('emits nothing when it is off', () => {
		start({ ...BOOT, cm: false });

		expect(window.dataLayer).toBeUndefined();
		expect(window.gtag).toBeUndefined();
	});

	it('emits nothing when cm is not exactly true', () => {
		start({ ...BOOT, cm: 'yes' });

		expect(window.dataLayer).toBeUndefined();
	});

	it('denies every signal by default, before anything else, with wait_for_update', () => {
		start({ ...BOOT, cm: true });
		const [first] = calls();

		expect(first[0]).toBe('consent');
		expect(first[1]).toBe('default');
		expect(Object.keys(first[2]).sort()).toEqual([...SIGNALS, 'wait_for_update'].sort());
		SIGNALS.filter((s) => s !== 'security_storage').forEach((s) => expect(first[2][s]).toBe('denied'));
		expect(first[2].security_storage).toBe('granted');
		expect(first[2].wait_for_update).toBe(500);
		expect(calls()).toHaveLength(1);
	});

	it('applies a stored choice in the same tick as the default', () => {
		setConsent(valid(['necessary', 'analytics']));
		start({ ...BOOT, cm: true });
		const all = calls();

		expect(all[0][1]).toBe('default');
		expect(all[1][1]).toBe('update');
		expect(all[1][2]).toMatchObject({
			analytics_storage: 'granted',
			ad_storage: 'denied',
			ad_user_data: 'denied',
			ad_personalization: 'denied',
			functionality_storage: 'denied',
			personalization_storage: 'denied',
			security_storage: 'granted'
		});
	});

	it.each([
		[['necessary', 'preferences'], { functionality_storage: 'granted', personalization_storage: 'granted', analytics_storage: 'denied', ad_storage: 'denied' }],
		[['necessary', 'marketing'], { ad_storage: 'granted', ad_user_data: 'granted', ad_personalization: 'granted', analytics_storage: 'denied' }],
		[['necessary'], { ad_storage: 'denied', analytics_storage: 'denied', functionality_storage: 'denied', security_storage: 'granted' }]
	])('maps %j', (categories, expected) => {
		start({ ...BOOT, cm: true });
		window.cmk.save(categories);
		const updates = calls().filter((c) => c[1] === 'update');

		expect(updates.at(-1)[2]).toMatchObject(expected);
	});

	it('updates on every change of choice, and a withdrawal denies again', () => {
		start({ ...BOOT, cm: true });
		window.cmk.save(['analytics']);
		window.cmk.save([]);
		const updates = calls().filter((c) => c[1] === 'update');

		expect(updates).toHaveLength(2);
		expect(updates[0][2].analytics_storage).toBe('granted');
		expect(updates[1][2].analytics_storage).toBe('denied');
	});

	it('keeps an existing dataLayer and gtag and only appends', () => {
		window.dataLayer = [['js', 'x']];
		const existing = function () {};
		window.gtag = existing;
		start({ ...BOOT, cm: true });

		expect(window.gtag).toBe(existing);
		expect(window.dataLayer[0]).toEqual(['js', 'x']);
	});

	it('a category with no signal (custom) changes nothing Google sees', () => {
		start({ ...BOOT, cats: [...BOOT.cats, 'social'], cm: true });
		window.cmk.save(['social']);
		const last = calls().filter((c) => c[1] === 'update').at(-1)[2];

		SIGNALS.filter((s) => s !== 'security_storage').forEach((s) => expect(last[s]).toBe('denied'));
	});
});

describe('the default model and GPC', () => {
	const setGpc = (value) => Object.defineProperty(window.navigator, 'globalPrivacyControl', { value, configurable: true });

	beforeEach(() => {
		delete window.dataLayer;
		delete window.gtag;
	});

	afterEach(() => {
		delete window.navigator.globalPrivacyControl;
		delete window.dataLayer;
		delete window.gtag;
	});

	it('opt-in starts with only necessary', () => {
		start({ ...BOOT, model: 'opt_in' });

		expect(window.cmk.initial()).toEqual(['necessary']);
	});

	it('opt-out starts with every category', () => {
		start({ ...BOOT, model: 'opt_out' });

		expect(window.cmk.initial()).toEqual(['necessary', 'preferences', 'analytics', 'marketing']);
	});

	it('an unknown or missing model is opt-in', () => {
		start({ ...BOOT, model: '"><script>' });
		expect(window.cmk.initial()).toEqual(['necessary']);

		start({ ...BOOT });
		expect(window.cmk.initial()).toEqual(['necessary']);
	});

	it('GPC removes marketing from the starting selection in either model', () => {
		setGpc(true);
		start({ ...BOOT, model: 'opt_out' });
		expect(window.cmk.gpc()).toBe(true);
		expect(window.cmk.initial()).toEqual(['necessary', 'preferences', 'analytics']);

		start({ ...BOOT, model: 'opt_in' });
		expect(window.cmk.initial()).toEqual(['necessary']);
	});

	it('only a real true counts as GPC', () => {
		setGpc('1');
		start({ ...BOOT });
		expect(window.cmk.gpc()).toBe(false);
	});

	it('opt-out runs optional scripts before any choice, GPC keeps marketing inert', () => {
		document.body.innerHTML =
			'<script type="text/plain" data-cmk-category="analytics">a()</script>' +
			'<script type="text/plain" data-cmk-category="marketing">m()</script>';
		setGpc(true);
		start({ ...BOOT, model: 'opt_out' });

		expect(live().map((s) => s.text)).toEqual(['a()']);
		expect(window.cmk.has('analytics')).toBe(true);
		expect(window.cmk.has('marketing')).toBe(false);
		expect(window.cmk.get()).toBeNull();
	});

	it('opt-out writes no cookie until the visitor acts', () => {
		start({ ...BOOT, model: 'opt_out' });

		expect(document.cookie).not.toContain('cmk_consent');
	});

	it('a stored choice always beats the model', () => {
		setConsent(valid(['necessary']));
		start({ ...BOOT, model: 'opt_out' });

		expect(window.cmk.has('analytics')).toBe(false);
	});

	it('Consent Mode applies the opt-out starting selection straight after the default', () => {
		start({ ...BOOT, model: 'opt_out', cm: true });
		const calls = window.dataLayer.map((a) => [...a]);

		expect(calls[0][1]).toBe('default');
		expect(calls[1][1]).toBe('update');
		expect(calls[1][2]).toMatchObject({ analytics_storage: 'granted', ad_storage: 'granted' });
	});

	it('Consent Mode under opt-out and GPC keeps ad signals denied', () => {
		setGpc(true);
		start({ ...BOOT, model: 'opt_out', cm: true });
		const update = window.dataLayer.map((a) => [...a]).find((c) => c[1] === 'update');

		expect(update[2]).toMatchObject({ analytics_storage: 'granted', ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied' });
	});

	it('Consent Mode under opt-in sends no update before a choice', () => {
		start({ ...BOOT, model: 'opt_in', cm: true });

		expect(window.dataLayer.map((a) => [...a]).filter((c) => c[1] === 'update')).toHaveLength(0);
	});
});

describe('opt-out and a stored choice from an older policy version', () => {
	const setGpc = (value) => Object.defineProperty(window.navigator, 'globalPrivacyControl', { value, configurable: true });
	const OUT = { ...BOOT, model: 'opt_out' };
	const stale = (categories, overrides = {}) => setConsent({ v: 1, c: categories, t: 1700000000, ...overrides });
	const SCRIPTS =
		'<script type="text/plain" data-cmk-category="preferences">two()</script>' +
		'<script type="text/plain" data-cmk-category="analytics">a()</script>' +
		'<script type="text/plain" data-cmk-category="marketing">m()</script>';

	beforeEach(() => {
		delete window.dataLayer;
		delete window.gtag;
	});

	afterEach(() => {
		delete window.navigator.globalPrivacyControl;
		delete window.dataLayer;
		delete window.gtag;
	});

	it('a prior rejecter stays at necessary and optional scripts stay inert', () => {
		document.body.innerHTML = SCRIPTS;
		stale(['necessary']);
		start(OUT);

		expect(window.cmk.initial()).toEqual(['necessary']);
		expect(live()).toHaveLength(0);
		expect(window.cmk.get()).toBeNull();
		expect(window.cmk.reconsent()).toBe(true);
	});

	it('a prior accepter of analytics keeps analytics, marketing stays off', () => {
		document.body.innerHTML = SCRIPTS;
		stale(['necessary', 'analytics']);
		start(OUT);

		expect(window.cmk.initial()).toEqual(['necessary', 'analytics']);
		expect(live().map((s) => s.text)).toEqual(['a()']);
		expect(window.cmk.has('marketing')).toBe(false);
		expect(window.cmk.get()).toBeNull();
	});

	it('a category added after the stale choice stays off', () => {
		stale(['necessary', 'analytics']);
		start({ ...OUT, cats: [...BOOT.cats, 'newcat'] });

		expect(window.cmk.initial()).toEqual(['necessary', 'analytics']);
		expect(window.cmk.has('newcat')).toBe(false);
	});

	it('hostile or garbage categories in the stale cookie leave only necessary', () => {
		[undefined, null, 'analytics', { 0: 'analytics' }, 7, [1, {}, null, 'nope', '<script>']].forEach((c) => {
			stale(['x'], { c });
			start(OUT);

			expect(window.cmk.initial()).toEqual(['necessary']);
		});
	});

	it('only known string entries of the stale cookie count', () => {
		stale(['analytics', 5, 'bogus', null]);
		start(OUT);

		expect(window.cmk.initial()).toEqual(['necessary', 'analytics']);
	});

	it('GPC still removes marketing from a prior accepter', () => {
		setGpc(true);
		stale(['necessary', 'analytics', 'marketing']);
		start(OUT);

		expect(window.cmk.initial()).toEqual(['necessary', 'analytics']);
	});

	it('opt-in with a prior accepter is still only necessary', () => {
		stale(['necessary', 'analytics']);
		start({ ...BOOT, model: 'opt_in' });

		expect(window.cmk.initial()).toEqual(['necessary']);
		expect(window.cmk.has('analytics')).toBe(false);
	});

	it('Consent Mode sends no update for a prior rejecter', () => {
		stale(['necessary']);
		start({ ...OUT, cm: true });
		const calls = window.dataLayer.map((a) => [...a]);

		expect(calls.filter((c) => c[1] === 'update')).toHaveLength(0);
		expect(calls[0][1]).toBe('default');
	});
});

describe('embeds', () => {
	const URL = 'https://www.youtube.com/embed/x';
	const WRAPPED = (category = 'marketing', src = URL) =>
		`<span class="cmk-embed" style="display:block"><span class="cmk-embed__box" data-cmk-embed-placeholder>blocked <button type="button" class="cmk-embed__accept" data-cmk-embed-accept="${category}">Accept</button> <button type="button" class="cmk-embed__prefs" data-cmk-open>Prefs</button></span><iframe data-cmk-embed=${category} data-cmk-src="${src}"></iframe></span>`;
	const BARE = (category = 'marketing', src = URL) =>
		`<iframe data-cmk-embed="${category}" data-cmk-src="${src}"></iframe>`;
	const frame = () => document.querySelector('iframe');
	const placeholder = () => document.querySelector('[data-cmk-embed-placeholder]');

	it('keeps the iframe unloaded and the placeholder shown without consent', () => {
		document.body.innerHTML = WRAPPED();
		start();

		expect(frame().hasAttribute('src')).toBe(false);
		expect(frame().getAttribute('data-cmk-src')).toBe(URL);
		expect(placeholder()).not.toBeNull();
	});

	it('loads the iframe and removes only the placeholder once its category is consented', () => {
		document.body.innerHTML = WRAPPED();
		setConsent(valid(['necessary', 'marketing']));
		start();

		expect(frame().getAttribute('src')).toBe(URL);
		expect(frame().hasAttribute('data-cmk-src')).toBe(false);
		expect(placeholder()).toBeNull();
	});

	it('replaces the wrapper with the iframe, in the wrapper position', () => {
		document.body.innerHTML = '<div id="host"><b>before</b>' + WRAPPED() + '<i>after</i></div>';
		setConsent(valid(['necessary', 'marketing']));
		start();

		const host = document.getElementById('host');

		expect(document.querySelector('.cmk-embed')).toBeNull();
		expect(frame().parentNode).toBe(host);
		expect(frame().previousSibling.tagName).toBe('B');
		expect(frame().nextSibling.tagName).toBe('I');
		expect(frame().getAttribute('src')).toBe(URL);
	});

	it('keeps the wrapper when it holds anything besides the placeholder and the iframe', () => {
		document.body.innerHTML = WRAPPED().replace('</iframe></span>', '</iframe><em>extra</em></span>');
		setConsent(valid(['necessary', 'marketing']));
		start();

		expect(frame().getAttribute('src')).toBe(URL);
		expect(placeholder()).toBeNull();
		expect(frame().parentNode.className).toBe('cmk-embed');
	});

	it('loads a bare iframe that has no wrapper or placeholder', () => {
		document.body.innerHTML = '<p data-cmk-embed-placeholder>keep me</p>' + BARE();
		setConsent(valid(['necessary', 'marketing']));
		start();

		expect(frame().getAttribute('src')).toBe(URL);
		expect(frame().hasAttribute('data-cmk-src')).toBe(false);
		expect(placeholder()).not.toBeNull();
	});

	it('does not remove a placeholder outside a cmk-embed wrapper', () => {
		document.body.innerHTML = '<div><span data-cmk-embed-placeholder>x</span><iframe data-cmk-embed=marketing data-cmk-src="' + URL + '"></iframe></div>';
		setConsent(valid(['necessary', 'marketing']));
		start();

		expect(frame().getAttribute('src')).toBe(URL);
		expect(placeholder()).not.toBeNull();
	});

	it('leaves a fail-closed srcdoc iframe untouched', () => {
		document.body.innerHTML = '<iframe srcdoc></iframe>';
		setConsent(valid(['necessary', 'marketing']));
		start();

		expect(frame().hasAttribute('src')).toBe(false);
		expect(frame().hasAttribute('srcdoc')).toBe(true);
		expect(frame().hasAttribute('data-cmk-done')).toBe(false);
	});

	it('does not load an embed of a category that was not consented', () => {
		document.body.innerHTML = WRAPPED('marketing');
		setConsent(valid(['necessary', 'analytics']));
		start();

		expect(frame().hasAttribute('src')).toBe(false);
		expect(placeholder()).not.toBeNull();
	});

	it.each(['javascript:alert(1)', 'data:text/html,hi', '//evil.example/x', '/local', ''])('refuses the source %j', (src) => {
		document.body.innerHTML = WRAPPED('marketing', src) + BARE('marketing', src);
		setConsent(valid(['necessary', 'marketing']));
		start();

		document.querySelectorAll('iframe').forEach((f) => expect(f.hasAttribute('src')).toBe(false));
		expect(placeholder()).not.toBeNull();
	});

	it('loads straight away in the opt-out model', () => {
		document.body.innerHTML = WRAPPED();
		start({ ...BOOT, model: 'opt_out' });

		expect(frame().getAttribute('src')).toBe(URL);
	});

	it('does not load for a category the page does not know about', () => {
		document.body.innerHTML = BARE('Bad Handle!') + BARE('nonsense');
		setConsent(valid(['necessary', 'marketing']));
		start();

		document.querySelectorAll('iframe').forEach((f) => expect(f.hasAttribute('src')).toBe(false));
	});

	it('loads the embed after save() grants its category', () => {
		document.body.innerHTML = WRAPPED();
		start();
		window.cmk.save(['marketing']);

		expect(frame().getAttribute('src')).toBe(URL);
		expect(placeholder()).toBeNull();
	});

	it('does not touch a frame twice', () => {
		document.body.innerHTML = WRAPPED();
		setConsent(valid(['necessary', 'marketing']));
		start();
		frame().setAttribute('src', 'https://changed.example/');
		window.cmk.activate();

		expect(frame().getAttribute('src')).toBe('https://changed.example/');
	});
});
