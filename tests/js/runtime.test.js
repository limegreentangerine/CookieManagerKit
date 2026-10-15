import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { loadScript } from './helpers.js';

const BOOT = { v: 2, cats: ['necessary', 'preferences', 'analytics', 'marketing'], days: 180, post: '/cookie-manager/consent', manual: true };

function config(overrides = {}) {
	return {
		version: 'abc123abc123',
		policyVersion: 2,
		locale: 'en_GB',
		banner: {
			title: 'Your privacy',
			body: 'We use cookies.\nChoose below.',
			labels: { acceptAll: 'Accept all', rejectAll: 'Reject all', customise: 'Customise', save: 'Save', widget: 'Cookie settings' },
			policyUrl: 'https://example.com/cookies',
			policyLinkText: 'Cookie policy',
		},
		categories: [
			{ handle: 'necessary', necessary: true, title: 'Necessary', description: 'Required', services: [], cookies: ['CONCRETE', 'cmk_consent'] },
			{ handle: 'preferences', necessary: false, title: 'Preferences', description: 'Remember choices', services: [], cookies: [] },
			{ handle: 'analytics', necessary: false, title: 'Analytics', description: 'Measure visits', services: [{ name: 'GA', vendor: 'Google', description: 'Counts visits' }], cookies: [] },
			{ handle: 'marketing', necessary: false, title: 'Marketing', description: 'Ads', services: [], cookies: [] },
		],
		style: { position: 'bottom-right', layout: 'stacked', font: 'inherit', tokens: { bg: '#ffffff', fg: '#111111', accent: '#0b5fff', buttonBg: '#0b5fff', buttonFg: '#ffffff' }, radius: 8, shadow: true },
		lifetimeDays: 180,
		widget: true,
		...overrides,
	};
}

function consent(categories) {
	document.cookie = 'cmk_consent=' + encodeURIComponent(JSON.stringify({ v: 2, c: categories, t: 1700000000 })) + '; Path=/';
}

function mount(cfg = config(), boot = BOOT) {
	delete window.cmk;
	delete window.cmkRuntime;
	window.cmkBoot = boot;
	loadScript('cmk-loader.js');
	loadScript('cmk-runtime.js');
	window.cmkRuntime.reload = vi.fn();
	window.cmkRuntime.start(cfg);
}

const root = () => document.querySelector('.cmk');
const prefs = () => document.querySelector('.cmk__prefs');
const button = (text) => [...document.querySelectorAll('button')].find((b) => b.textContent === text);
const saved = () => window.cmk.get().c;

// jsdom really executes activated scripts, in its own context, so give the sample snippet a harmless global there.
beforeEach(() => {
	jsdom.window.a = () => {};
	document.body.innerHTML = '';
	window.fetch = vi.fn(() => Promise.resolve({ ok: true }));
});

afterEach(() => {
	delete jsdom.window.a;
	if (window.cmkRuntime) window.cmkRuntime.stop();
	document.cookie = 'cmk_consent=; Path=/; Max-Age=0';
	delete window.cmk;
	delete window.cmkRuntime;
	delete window.cmkBoot;
	vi.restoreAllMocks();
});

describe('showing the banner', () => {
	it('shows an accessible banner with the content when there is no valid choice', () => {
		mount();

		expect(root()).not.toBeNull();
		expect(root().getAttribute('role')).toBe('dialog');
		expect(root().getAttribute('aria-labelledby')).toBe('cmk-title');
		expect(document.getElementById('cmk-title').textContent).toBe('Your privacy');
		expect(document.getElementById('cmk-body').textContent).toBe('We use cookies.Choose below.');
		expect(document.getElementById('cmk-body').querySelectorAll('br')).toHaveLength(1);
		expect(button('Accept all')).toBeTruthy();
		expect(button('Reject all')).toBeTruthy();
		expect(button('Customise')).toBeTruthy();
		expect(prefs().hidden).toBe(true);
	});

	it('does not show the banner once a valid choice exists, but keeps the widget', () => {
		consent(['necessary', 'analytics']);
		mount();

		expect(root()).toBeNull();
		expect(document.querySelector('.cmk-widget')).not.toBeNull();
		expect(document.querySelector('.cmk-widget').textContent).toBe('Cookie settings');
	});

	it('omits the widget when the admin turned it off', () => {
		mount(config({ widget: false }));

		expect(document.querySelector('.cmk-widget')).toBeNull();
	});

	it('applies position, layout, font and shadow from an allowlist only', () => {
		mount(config({ style: { ...config().style, position: 'bottom-left', layout: 'inline', font: 'system', shadow: false } }));

		expect(root().dataset.position).toBe('bottom-left');
		expect(root().dataset.layout).toBe('inline');
		expect(root().dataset.font).toBe('system');
		expect(root().dataset.shadow).toBe('off');
	});

	it('falls back to defaults for hostile style values and ignores invalid colours', () => {
		mount(config({ style: { position: '"><img src=x onerror=alert(1)>', layout: 'x', font: 'url(x)', radius: 9999, shadow: true, tokens: { bg: 'red;}</style><script>alert(1)</script>', fg: '#222222', accent: 5, buttonBg: null, buttonFg: '#abcdef' } } }));

		expect(root().dataset.position).toBe('bottom-right');
		expect(root().dataset.layout).toBe('stacked');
		expect(root().dataset.font).toBe('inherit');
		expect(root().style.getPropertyValue('--cmk-bg')).toBe('');
		expect(root().style.getPropertyValue('--cmk-fg')).toBe('#222222');
		expect(root().style.getPropertyValue('--cmk-button-fg')).toBe('#abcdef');
		expect(root().style.getPropertyValue('--cmk-radius')).toBe('');
		expect(document.querySelector('img')).toBeNull();
	});

	it('ignores a missing or invalid config and does nothing without the loader', () => {
		mount(null);
		expect(root()).toBeNull();

		mount('nope');
		expect(root()).toBeNull();

		delete window.cmk;
		delete window.cmkRuntime;
		loadScript('cmk-runtime.js');
		expect(() => window.cmkRuntime.start(config())).not.toThrow();
		expect(root()).toBeNull();
	});
});

describe('hostile content is rendered as text, never as markup', () => {
	const evil = '<img src=x onerror=alert(1)><script>window.__pwned=1</script>"\'`';

	it('banner, category and service text', () => {
		const cfg = config();
		cfg.banner.title = evil;
		cfg.banner.body = evil + '\n' + evil;
		cfg.banner.labels = { acceptAll: evil, rejectAll: evil, customise: evil, save: evil, widget: evil };
		cfg.banner.policyLinkText = evil;
		cfg.categories[2].title = evil;
		cfg.categories[2].description = evil;
		cfg.categories[2].services = [{ name: evil, vendor: evil, description: evil }];
		cfg.categories[0].cookies = [evil];
		mount(cfg);

		expect(document.querySelector('img')).toBeNull();
		expect(document.querySelectorAll('.cmk script, .cmk-widget script')).toHaveLength(0);
		expect(document.getElementById('cmk-title').textContent).toBe(evil);
		expect(window.__pwned).toBeUndefined();
		expect(document.querySelectorAll('[onerror]')).toHaveLength(0);
	});

	it('only links http(s) and relative policy URLs, with safe rel', () => {
		const link = (url) => {
			const cfg = config();
			cfg.banner.policyUrl = url;
			mount(cfg);
			const anchor = document.querySelector('a.cmk__link');
			window.cmkRuntime.stop();
			return anchor;
		};

		expect(link('https://example.com/p').getAttribute('href')).toBe('https://example.com/p');
		expect(link('/privacy').getAttribute('href')).toBe('/privacy');
		expect(link('https://example.com/p').getAttribute('rel')).toBe('noopener noreferrer');

		['javascript:alert(1)', 'JaVaScRiPt:alert(1)', "java\tscript:alert(1)", 'data:text/html,x', 'vbscript:x', '//evil.example', '/\\evil.example', '', null, 5].forEach((bad) => {
			expect(link(bad), String(bad)).toBeNull();
		});
	});

	it('skips categories whose handle is not a valid slug', () => {
		const cfg = config();
		cfg.categories.push({ handle: '<script>', necessary: false, title: 'Bad', description: '', services: [], cookies: [] });
		cfg.categories.push({ handle: 5, necessary: false, title: 'Bad', description: '', services: [], cookies: [] });
		mount(cfg);

		expect(document.querySelectorAll('.cmk__cat')).toHaveLength(4);
	});
});

describe('making a choice', () => {
	it('accept all stores every category, tells the server and closes the banner', () => {
		mount();
		button('Accept all').click();

		expect(saved()).toEqual(['necessary', 'preferences', 'analytics', 'marketing']);
		expect(root()).toBeNull();
		expect(window.fetch).toHaveBeenCalledTimes(1);

		const [url, options] = window.fetch.mock.calls[0];
		expect(url).toBe('/cookie-manager/consent');
		expect(options.method).toBe('POST');
		expect(options.credentials).toBe('same-origin');
		expect(options.headers['Content-Type']).toBe('application/json');
		expect(JSON.parse(options.body)).toEqual({ v: 2, c: ['necessary', 'preferences', 'analytics', 'marketing'] });
		expect(window.cmkRuntime.reload).not.toHaveBeenCalled();
	});

	it('reject all stores only necessary', () => {
		mount();
		button('Reject all').click();

		expect(saved()).toEqual(['necessary']);
		expect(root()).toBeNull();
	});

	it('customise lists every category, necessary is ticked and locked, the rest start unticked', () => {
		mount();
		button('Customise').click();

		expect(prefs().hidden).toBe(false);
		const box = (handle) => document.querySelector('[data-cmk-cat="' + handle + '"]');
		expect(box('necessary').checked).toBe(true);
		expect(box('necessary').disabled).toBe(true);
		['preferences', 'analytics', 'marketing'].forEach((handle) => {
			expect(box(handle).checked).toBe(false);
			expect(box(handle).disabled).toBe(false);
		});
	});

	it('saves exactly the ticked categories', () => {
		mount();
		button('Customise').click();
		document.querySelector('[data-cmk-cat="analytics"]').checked = true;
		button('Save').click();

		expect(saved()).toEqual(['necessary', 'analytics']);
		expect(JSON.parse(window.fetch.mock.calls[0][1].body).c).toEqual(['necessary', 'analytics']);
	});

	it('activates inert scripts straight away through the loader', () => {
		document.body.innerHTML = '<script type="text/plain" data-cmk-category="analytics">a()</script>';
		mount();
		button('Accept all').click();

		expect([...document.querySelectorAll('script:not([type="text/plain"])')]).toHaveLength(1);
	});
});

describe('changing the choice later', () => {
	it('reopens from any [data-cmk-open] element with the current choice ticked', () => {
		consent(['necessary', 'analytics']);
		mount();
		document.body.insertAdjacentHTML('beforeend', '<a href="#" id="footer-link" data-cmk-open><span id="inner">Cookie settings</span></a>');

		document.getElementById('inner').click(); // a click on a child still opens it

		expect(root()).not.toBeNull();
		expect(prefs().hidden).toBe(false);
		expect(document.querySelector('[data-cmk-cat="analytics"]').checked).toBe(true);
		expect(document.querySelector('[data-cmk-cat="marketing"]').checked).toBe(false);
	});

	it('reopens from the floating widget', () => {
		consent(['necessary']);
		mount();
		document.querySelector('.cmk-widget').click();

		expect(root()).not.toBeNull();
	});

	it('reloads the page when a previously consented category is withdrawn', () => {
		consent(['necessary', 'analytics']);
		mount();
		document.querySelector('.cmk-widget').click();
		document.querySelector('[data-cmk-cat="analytics"]').checked = false;
		button('Save').click();

		expect(saved()).toEqual(['necessary']);
		expect(window.cmkRuntime.reload).toHaveBeenCalledTimes(1);
	});

	it('does not reload when categories are only added or unchanged', () => {
		consent(['necessary', 'analytics']);
		mount();
		document.querySelector('.cmk-widget').click();
		document.querySelector('[data-cmk-cat="marketing"]').checked = true;
		button('Save').click();

		expect(saved()).toEqual(['necessary', 'analytics', 'marketing']);
		expect(window.cmkRuntime.reload).not.toHaveBeenCalled();
	});

	it('Escape closes the preferences only when a choice already exists', () => {
		consent(['necessary']);
		mount();
		document.querySelector('.cmk-widget').click();
		document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
		expect(root()).toBeNull();

		document.cookie = 'cmk_consent=; Path=/; Max-Age=0';
		window.cmkRuntime.stop();
		mount();
		document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
		expect(root()).not.toBeNull();
	});

	it('stop() removes every listener and node it added', () => {
		mount();
		window.cmkRuntime.stop();

		expect(root()).toBeNull();
		expect(document.querySelector('.cmk-widget')).toBeNull();
		document.body.insertAdjacentHTML('beforeend', '<a data-cmk-open id="x">x</a>');
		document.getElementById('x').click();
		expect(root()).toBeNull();
	});
});

describe('when the consent endpoint cannot be reached', () => {
	it('keeps the choice when fetch rejects', async () => {
		window.fetch = vi.fn(() => Promise.reject(new Error('offline')));
		mount();
		button('Accept all').click();
		await Promise.resolve();

		expect(saved()).toContain('analytics');
		expect(root()).toBeNull();
	});

	it('keeps the choice when the server answers with an error', async () => {
		window.fetch = vi.fn(() => Promise.resolve({ ok: false, status: 500 }));
		mount();
		button('Reject all').click();
		await Promise.resolve();

		expect(saved()).toEqual(['necessary']);
	});

	it('keeps the choice when fetch throws synchronously', () => {
		window.fetch = vi.fn(() => {
			throw new Error('blocked by an extension');
		});
		mount();

		expect(() => button('Accept all').click()).not.toThrow();
		expect(saved()).toContain('analytics');
	});

	it('keeps the choice when fetch does not exist', () => {
		delete window.fetch;
		mount();

		expect(() => button('Accept all').click()).not.toThrow();
		expect(saved()).toContain('analytics');
	});

	it('does not post at all when no endpoint is configured', () => {
		mount(config(), { ...BOOT, post: undefined });
		button('Accept all').click();

		expect(window.fetch).not.toHaveBeenCalled();
		expect(saved()).toContain('analytics');
	});
});

describe('automatic start', () => {
	it('fetches the config itself when not in manual mode, without credentials', async () => {
		const cfg = config();
		window.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve(cfg) }));
		delete window.cmk;
		delete window.cmkRuntime;
		window.cmkBoot = { ...BOOT, manual: false, cfg: '/cookie-manager/config/en_GB.json?v=abc' };
		loadScript('cmk-loader.js');
		loadScript('cmk-runtime.js');
		await new Promise((resolve) => setTimeout(resolve, 0));

		expect(window.fetch).toHaveBeenCalledWith('/cookie-manager/config/en_GB.json?v=abc', { credentials: 'omit' });
		expect(root()).not.toBeNull();
	});

	it('stays silent when the config cannot be loaded (scripts stay blocked)', async () => {
		window.fetch = vi.fn(() => Promise.resolve({ ok: false, status: 500 }));
		delete window.cmk;
		delete window.cmkRuntime;
		window.cmkBoot = { ...BOOT, manual: false, cfg: '/cookie-manager/config/en_GB.json' };
		loadScript('cmk-loader.js');

		expect(() => loadScript('cmk-runtime.js')).not.toThrow();
		await new Promise((resolve) => setTimeout(resolve, 0));
		expect(root()).toBeNull();
	});
});

describe('focus is returned to whatever opened the dialog', () => {
	const addLink = () => {
		document.body.insertAdjacentHTML('beforeend', '<a href="#" id="footer-link" data-cmk-open>Cookie settings</a>');
		return document.getElementById('footer-link');
	};

	it('returns focus to a footer link after Escape', () => {
		consent(['necessary']);
		mount();
		const link = addLink();
		link.focus();
		link.click();
		expect(document.activeElement).not.toBe(link);

		document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

		expect(root()).toBeNull();
		expect(document.activeElement).toBe(link);
	});

	it('returns focus to the link after Save when the widget is off', () => {
		consent(['necessary']);
		mount(config({ widget: false }));
		const link = addLink();
		link.focus();
		link.click();
		button('Save').click();

		expect(document.activeElement).toBe(link);
	});

	it('returns focus to the widget when the widget opened it', () => {
		consent(['necessary']);
		mount();
		const widget = document.querySelector('.cmk-widget');
		widget.focus();
		widget.click();
		button('Reject all').click();

		expect(document.activeElement).toBe(widget);
	});

	it('falls back to the widget when the opener left the page', () => {
		consent(['necessary']);
		mount();
		const link = addLink();
		link.focus();
		link.click();
		link.remove();

		expect(() => button('Save').click()).not.toThrow();
		expect(document.activeElement).toBe(document.querySelector('.cmk-widget'));
	});
});

describe('focus trap for the centred modal', () => {
	const modal = () => config({ style: { ...config().style, position: 'center-modal' } });
	const tab = (shift = false) => {
		const event = new KeyboardEvent('keydown', { key: 'Tab', shiftKey: shift, cancelable: true, bubbles: true });
		document.dispatchEvent(event);
		return event;
	};

	it('wraps Tab from the last control to the first and skips hidden preferences', () => {
		mount(modal());
		expect(root().getAttribute('aria-modal')).toBe('true');
		const first = document.querySelector('a.cmk__link');
		button('Customise').focus();

		const event = tab();

		expect(event.defaultPrevented).toBe(true);
		expect(document.activeElement).toBe(first);
	});

	it('wraps Shift+Tab from the first control to the last', () => {
		mount(modal());
		document.querySelector('a.cmk__link').focus();

		const event = tab(true);

		expect(event.defaultPrevented).toBe(true);
		expect(document.activeElement).toBe(button('Customise'));
	});

	it('includes the preference controls once they are shown', () => {
		mount(modal());
		button('Customise').click();
		button('Save').focus();

		tab();

		expect(document.activeElement).toBe(document.querySelector('a.cmk__link'));
	});

	it('pulls focus into the dialog when it is outside', () => {
		mount(modal());
		document.body.insertAdjacentHTML('beforeend', '<button id="outside">x</button>');
		document.getElementById('outside').focus();

		tab();

		expect(document.activeElement).toBe(document.querySelector('a.cmk__link'));
	});

	it('moves focus to the title when first shown automatically', () => {
		mount(modal());

		expect(document.activeElement).toBe(document.getElementById('cmk-title'));
	});

	it('does not steal focus for the other positions', () => {
		document.body.insertAdjacentHTML('beforeend', '<button id="before">x</button>');
		document.getElementById('before').focus();
		mount();

		expect(document.activeElement).toBe(document.getElementById('before'));
	});

	it('does not trap other positions', () => {
		mount();
		expect(root().getAttribute('aria-modal')).toBe('false');
		button('Customise').focus();

		const event = tab();

		expect(event.defaultPrevented).toBe(false);
		expect(document.activeElement).toBe(button('Customise'));
	});

	it('removes the trap on stop()', () => {
		mount(modal());
		window.cmkRuntime.stop();
		const event = tab();

		expect(event.defaultPrevented).toBe(false);
	});
});

describe('the always-allowed cookies', () => {
	const withCookies = (cookies) => {
		const cfg = config();
		cfg.categories[0].cookies = cookies;
		return cfg;
	};
	const items = () => [...document.querySelectorAll('.cmk__cookies li')];

	it('shows name, purpose and duration as text', () => {
		mount(withCookies([{ name: 'CONCRETE', purpose: 'Keeps you signed in.', duration: 'Session' }]));

		expect(items()).toHaveLength(1);
		expect(items()[0].querySelector('code').textContent).toBe('CONCRETE');
		expect(items()[0].textContent).toBe('CONCRETE \u2014 Keeps you signed in. (Session)');
	});

	it('keeps hostile strings as text', () => {
		mount(withCookies([{ name: '<img src=x onerror=alert(1)>', purpose: '<b>bold</b>', duration: '<i>x</i>' }]));

		expect(document.querySelector('.cmk__cookies img, .cmk__cookies b, .cmk__cookies i')).toBeNull();
		expect(items()[0].textContent).toContain('<b>bold</b>');
		expect(items()[0].children).toHaveLength(1);
	});

	it('still renders old plain-string entries and skips junk', () => {
		mount(withCookies(['CONCRETE', 42, null, { purpose: 'no name' }, { name: '' }, 'cmk_consent']));

		expect(items().map((li) => li.textContent)).toEqual(['CONCRETE', 'cmk_consent']);
	});

	it('omits the dash and parentheses for empty parts', () => {
		mount(withCookies([{ name: 'a', purpose: 'Does a thing.' }, { name: 'b', duration: 'Session' }, { name: 'c', purpose: '', duration: '' }]));

		expect(items().map((li) => li.textContent)).toEqual(['a \u2014 Does a thing.', 'b (Session)', 'c']);
	});
});

describe('missing text falls back to built-in English', () => {
	it('gives every control and the heading an accessible name', () => {
		const cfg = config();
		cfg.banner = { labels: {}, policyUrl: '', policyLinkText: '' };
		mount(cfg);

		expect(document.getElementById('cmk-title').textContent).toBe('We value your privacy');
		[...document.querySelectorAll('button')].forEach((b) => expect(b.textContent.trim()).not.toBe(''));
		expect(['Accept all', 'Reject all', 'Customise', 'Save my choices', 'Cookie settings'].every((t) => button(t))).toBe(true);
	});

	it('falls back for non-string labels and keeps the widget off when disabled', () => {
		const cfg = config({ widget: false });
		cfg.banner.labels = { acceptAll: 5, rejectAll: '', customise: null, save: {}, widget: [] };
		mount(cfg);

		expect(button('Accept all')).toBeTruthy();
		expect(button('Reject all')).toBeTruthy();
		expect(document.querySelector('.cmk-widget')).toBeNull();
	});

	it('uses the fallback widget label', () => {
		consent(['necessary']);
		const cfg = config();
		cfg.banner.labels = {};
		mount(cfg);

		expect(document.querySelector('.cmk-widget').textContent).toBe('Cookie settings');
	});

	it('shows the handle for a category without a title', () => {
		const cfg = config();
		cfg.categories[2].title = '';
		mount(cfg);

		expect(document.querySelector('label[for="cmk-cat-analytics"] .cmk__cat-title').textContent).toBe('analytics');
	});
});

describe('category and text robustness', () => {
	it('keeps only the first category for a duplicated handle', () => {
		const cfg = config();
		cfg.categories.push({ handle: 'analytics', necessary: false, title: 'Dupe', description: '', services: [], cookies: [] });
		mount(cfg);

		expect(document.querySelectorAll('.cmk__cat')).toHaveLength(4);
		expect(document.querySelectorAll('#cmk-cat-analytics')).toHaveLength(1);
		expect(document.body.textContent).not.toContain('Dupe');
	});

	it('splits CRLF and CR line breaks without leaving a carriage return', () => {
		const cfg = config();
		cfg.banner.body = 'one\r\ntwo\rthree';
		mount(cfg);

		expect(document.getElementById('cmk-body').textContent).toBe('onetwothree');
		expect(document.getElementById('cmk-body').querySelectorAll('br')).toHaveLength(2);
	});
});
