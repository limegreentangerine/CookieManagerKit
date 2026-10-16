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
