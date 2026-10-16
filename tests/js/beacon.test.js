import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { loadScript } from './helpers.js';

function clearCookies() {
	document.cookie.split(';').forEach((cookie) => {
		const name = cookie.split('=')[0].trim();
		if (name) document.cookie = name + '=; Max-Age=0; Path=/';
	});
}

function scanMode(extra = []) {
	document.cookie = 'cmk_scan=1; Path=/';
	extra.forEach((cookie) => (document.cookie = cookie + '; Path=/'));
}

function resources(names) {
	vi.spyOn(window.performance, 'getEntriesByType').mockReturnValue(names.map((name) => ({ name })));
}

const sent = () => JSON.parse(window.fetch.mock.calls[0][1].body);
const bytes = (text) => new TextEncoder().encode(text).length;
const flush = async () => {
	for (let i = 0; i < 5; i++) await Promise.resolve();
};

// Each test loads a fresh copy of the script into the same window, so drop the pagehide listeners it leaves behind.
let pagehide = [];

beforeEach(() => {
	delete window.__cmkBeacon;
	pagehide = [];
	const add = window.addEventListener.bind(window);
	vi.spyOn(window, 'addEventListener').mockImplementation((type, listener, options) => {
		if (type === 'pagehide') pagehide.push(listener);
		add(type, listener, options);
	});
	vi.useFakeTimers();
	clearCookies();
	localStorage.clear();
	sessionStorage.clear();
	window.fetch = vi.fn(() => Promise.resolve({ status: 200 }));
});

afterEach(() => {
	pagehide.forEach((listener) => window.removeEventListener('pagehide', listener));
	vi.useRealTimers();
	vi.restoreAllMocks();
	clearCookies();
	localStorage.clear();
	sessionStorage.clear();
});

describe('the scan-mode beacon', () => {
	it('does nothing without the scan-mode marker cookie', () => {
		document.cookie = '_ga=1; Path=/';
		loadScript('cmk-beacon.js');
		vi.advanceTimersByTime(5000);

		expect(window.fetch).not.toHaveBeenCalled();
	});

	it('reports names and hosts only, never values', () => {
		scanMode(['_ga=SECRET-COOKIE-VALUE']);
		localStorage.setItem('cart', 'SECRET-LOCAL-VALUE');
		sessionStorage.setItem('draft', 'SECRET-SESSION-VALUE');
		resources(['https://cdn.example.net/a.js?token=SECRET-QUERY', window.location.origin + '/own.js', 'not a url']);

		loadScript('cmk-beacon.js');
		vi.advanceTimersByTime(3000);

		const body = window.fetch.mock.calls[0][1].body;
		const report = JSON.parse(body);

		expect(report.c).toEqual(expect.arrayContaining(['_ga', 'cmk_scan']));
		expect(report.s).toEqual(expect.arrayContaining(['cart', 'draft']));
		expect(report.h).toEqual(['cdn.example.net']);
		expect(report.p).toBe('/');
		expect(Object.keys(report).sort()).toEqual(['c', 'h', 'p', 's']);
		expect(body).not.toContain('SECRET');
	});

	it('ignores non-http resource schemes and keeps the path free of query and hash', () => {
		scanMode();
		window.history.replaceState(null, '', '/page?secret=1#frag');
		resources(['data:text/plain,hello', 'blob:https://cdn.example.net/x', 'https://cdn.example.net/a.js', 'ftp://files.example.net/a']);

		loadScript('cmk-beacon.js');
		vi.advanceTimersByTime(3000);

		const report = sent();
		window.history.replaceState(null, '', '/');

		expect(report.h).toEqual(['cdn.example.net']);
		expect(report.p).toBe('/page');
	});

	it('posts JSON to the report endpoint next to the script, with the browser credentials', () => {
		scanMode();
		loadScript('cmk-beacon.js');
		vi.advanceTimersByTime(3000);

		const [url, options] = window.fetch.mock.calls[0];

		expect(new URL(url).pathname.endsWith('/report')).toBe(true);
		expect(new URL(url).origin).toBe(window.location.origin);
		expect(options.method).toBe('POST');
		expect(options.credentials).toBe('same-origin');
		expect(options.headers['Content-Type']).toBe('application/json');
	});

	it('waits for the page to settle before reporting', () => {
		scanMode();
		loadScript('cmk-beacon.js');
		vi.advanceTimersByTime(1000);

		expect(window.fetch).not.toHaveBeenCalled();
	});

	it('caps each list at 100 items and each name at 128 characters', () => {
		scanMode();

		for (let i = 0; i < 150; i++) localStorage.setItem('key' + i, 'v');
		localStorage.setItem('x'.repeat(500), 'v');

		loadScript('cmk-beacon.js');
		vi.advanceTimersByTime(3000);

		const report = sent();

		expect(report.s.length).toBeLessThanOrEqual(100);
		report.s.forEach((name) => expect(name.length).toBeLessThanOrEqual(128));
	});

	it('keeps the whole payload under 32768 bytes even with maximal multibyte input', () => {
		scanMode();

		for (let i = 0; i < 150; i++) {
			localStorage.setItem(String(i).padStart(3, '0') + '€'.repeat(125), 'v');
			sessionStorage.setItem(String(i).padStart(3, '0') + '\u{1F36A}'.repeat(60), 'v');
		}

		resources(Array.from({ length: 150 }, (_, i) => 'https://' + String(i).padStart(3, '0') + '.example.net/a.js'));
		loadScript('cmk-beacon.js');
		vi.advanceTimersByTime(3000);

		const body = window.fetch.mock.calls[0][1].body;
		const report = JSON.parse(body);

		expect(bytes(body)).toBeLessThanOrEqual(32768);
		expect(report.s.length).toBeGreaterThan(0);
		expect(report.s.length).toBeLessThan(200);
		['c', 's', 'h'].forEach((key) => expect(report[key].length).toBeLessThanOrEqual(100));
		expect(window.fetch).toHaveBeenCalledTimes(1);
	});

	it('trims deterministically', () => {
		const run = () => {
			window.fetch.mockClear();
			delete window.__cmkBeacon;
			localStorage.clear();
			for (let i = 0; i < 100; i++) localStorage.setItem(String(i).padStart(3, '0') + '€'.repeat(125), 'v');
			loadScript('cmk-beacon.js');
			vi.advanceTimersByTime(3000);
			return window.fetch.mock.calls[0][1].body;
		};

		scanMode();
		const first = run();
		expect(run()).toBe(first);
	});

	it('removes duplicates', () => {
		scanMode();
		localStorage.setItem('same', '1');
		sessionStorage.setItem('same', '2');
		resources(['https://cdn.example.net/a.js', 'https://cdn.example.net/b.js']);

		loadScript('cmk-beacon.js');
		vi.advanceTimersByTime(3000);

		const report = sent();

		expect(report.s.filter((name) => name === 'same')).toHaveLength(1);
		expect(report.h).toEqual(['cdn.example.net']);
	});

	it('sends hostile names as plain strings and never touches the page', () => {
		scanMode();
		localStorage.setItem('"><img src=x onerror=alert(1)>', 'v');
		document.body.innerHTML = '<p id="keep">unchanged</p>';

		loadScript('cmk-beacon.js');
		vi.advanceTimersByTime(3000);

		expect(sent().s).toContain('"><img src=x onerror=alert(1)>');
		expect(document.body.innerHTML).toBe('<p id="keep">unchanged</p>');
	});

	it('stops by clearing the marker when the server refuses the token', async () => {
		scanMode();
		window.fetch = vi.fn(() => Promise.resolve({ status: 401 }));

		loadScript('cmk-beacon.js');
		vi.advanceTimersByTime(3000);
		await flush();

		expect(document.cookie).not.toContain('cmk_scan=1');
	});

	it.each([401, 403])('never reports again after a %i, even if the marker comes back', async (status) => {
		scanMode();
		window.fetch = vi.fn(() => Promise.resolve({ status }));

		loadScript('cmk-beacon.js');
		vi.advanceTimersByTime(3000);
		await flush();

		scanMode();
		localStorage.setItem('late', '1');
		window.dispatchEvent(new Event('pagehide'));
		vi.advanceTimersByTime(60000);

		expect(window.fetch).toHaveBeenCalledTimes(1);
	});

	it('stops reporting when the marker disappears', () => {
		scanMode();
		loadScript('cmk-beacon.js');
		clearCookies();
		localStorage.setItem('late', '1');
		vi.advanceTimersByTime(5000);
		window.dispatchEvent(new Event('pagehide'));

		expect(window.fetch).not.toHaveBeenCalled();
	});

	it('gives up for good after a few refused or failed reports', async () => {
		scanMode();
		window.fetch = vi.fn(() => Promise.resolve({ status: 400 }));

		loadScript('cmk-beacon.js');
		vi.advanceTimersByTime(3000);
		await flush();

		for (let i = 0; i < 8; i++) {
			localStorage.setItem('k' + i, '1');
			window.dispatchEvent(new Event('pagehide'));
			await flush();
		}

		expect(window.fetch.mock.calls.length).toBe(3);
		expect(document.cookie).toContain('cmk_scan=1');
	});

	it('counts network errors as failures too', async () => {
		scanMode();
		window.fetch = vi.fn(() => Promise.reject(new Error('offline')));

		loadScript('cmk-beacon.js');
		vi.advanceTimersByTime(3000);
		await flush();

		for (let i = 0; i < 8; i++) {
			localStorage.setItem('k' + i, '1');
			window.dispatchEvent(new Event('pagehide'));
			await flush();
		}

		expect(window.fetch.mock.calls.length).toBe(3);
	});

	it('does not repeat an unchanged report', () => {
		scanMode();
		loadScript('cmk-beacon.js');
		vi.advanceTimersByTime(3000);
		window.dispatchEvent(new Event('pagehide'));

		expect(window.fetch).toHaveBeenCalledTimes(1);
	});

	it('survives storage that throws', () => {
		scanMode();
		vi.spyOn(Storage.prototype, 'key').mockImplementation(() => {
			throw new Error('blocked');
		});
		localStorage.setItem('a', '1');

		loadScript('cmk-beacon.js');
		vi.advanceTimersByTime(3000);

		expect(sent().c).toContain('cmk_scan');
	});

	it('skips nameless cookies so their text is never reported as a name', () => {
		scanMode();
		document.cookie = '=NAMELESS-VALUE; Path=/';
		document.cookie = 'plain-nameless-value; Path=/';

		loadScript('cmk-beacon.js');
		vi.advanceTimersByTime(3000);

		const body = window.fetch.mock.calls[0][1].body;

		expect(body).not.toContain('NAMELESS-VALUE');
		expect(body).not.toContain('plain-nameless-value');
	});

	it('matches the marker exactly', () => {
		document.cookie = 'xcmk_scan=1; Path=/';
		loadScript('cmk-beacon.js');
		vi.advanceTimersByTime(5000);

		expect(window.fetch).not.toHaveBeenCalled();
	});

	it('removes lone surrogates so the JSON stays valid', () => {
		scanMode();
		localStorage.setItem('a\ud800b', 'v');
		localStorage.setItem('c\udc00d', 'v');
		localStorage.setItem('ok\u{1F36A}', 'v');

		loadScript('cmk-beacon.js');
		vi.advanceTimersByTime(3000);

		const body = window.fetch.mock.calls[0][1].body;

		expect(body).not.toMatch(/\ud[89a-f][0-9a-f]{2}/i);
		expect(sent().s).toEqual(expect.arrayContaining(['ab', 'cd', 'ok\u{1F36A}']));
	});

	it('collapses leading slashes in the path', () => {
		scanMode();
		window.history.replaceState(null, '', window.location.origin + '//x/y');
		loadScript('cmk-beacon.js');
		vi.advanceTimersByTime(3000);
		const report = sent();
		window.history.replaceState(null, '', '/');

		expect(report.p).toBe('/x/y');
	});

	it('only loads once per page', () => {
		scanMode();
		loadScript('cmk-beacon.js');
		loadScript('cmk-beacon.js');
		vi.advanceTimersByTime(3000);

		expect(window.fetch).toHaveBeenCalledTimes(1);
	});

	it('refuses to run from a script on another origin', () => {
		scanMode();
		Object.defineProperty(document, 'currentScript', { configurable: true, get: () => ({ src: 'https://evil.example.net/cookie-manager/scan/beacon.js' }) });

		try {
			loadScript('cmk-beacon.js');
			vi.advanceTimersByTime(5000);
		} finally {
			delete document.currentScript;
		}

		expect(window.fetch).not.toHaveBeenCalled();
	});
});
