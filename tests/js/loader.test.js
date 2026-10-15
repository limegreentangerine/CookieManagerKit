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
