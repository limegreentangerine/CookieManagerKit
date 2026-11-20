import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { loadScript } from './helpers.js';

function page() {
	document.body.innerHTML = `
		<div data-cmk-contrast hidden><ul>
			<li data-cmk-contrast-pair="text" data-message="text %s" data-kept="kept text %s" hidden></li>
			<li data-cmk-contrast-pair="button" data-message="button %s" data-kept="kept button %s" hidden></li>
			<li data-cmk-contrast-pair="widget" data-message="widget %s" data-kept="kept widget %s" hidden></li>
			<li data-cmk-contrast-pair="accent" data-message="accent %s" data-kept="kept accent %s" hidden></li>
		</ul></div>
		<div class="cmk-preview-stage"><div class="cmk" data-cmk-preview data-position="bottom-right" data-layout="stacked" data-font="inherit" data-shadow="on">
			<img data-cmk-preview-icon src="/files/cookie.svg" alt="">
		</div>
		<button data-cmk-preview-widget data-display="icon"><img src="/files/cookie.svg" alt=""><span>Cookie settings</span></button></div>
		<input name="iconFileId" type="hidden" value="5">
		<select id="widgetDisplay"><option value="text">x</option><option value="icon" selected>x</option><option value="both">x</option></select>
		<input id="ignoreContrast" type="checkbox">
		<input id="widgetCustomColours" type="checkbox"><input id="tokenWidgetBg" value="#0b5fff"><input id="tokenWidgetFg" value="#ffffff">
		<input id="tokenBg" value="#ffffff"><input id="tokenFg" value="#111111"><input id="tokenAccent" value="#0b5fff">
		<input id="tokenButtonBg" value="#0b5fff"><input id="tokenButtonFg" value="#ffffff">
		<select id="position"><option value="bottom-right">x</option><option value="bottom-left">x</option></select>
		<select id="layout"><option value="stacked">x</option><option value="inline">x</option></select>
		<select id="font"><option value="inherit">x</option><option value="system">x</option></select>
		<input id="radius" value="8"><input id="shadow" type="checkbox" checked>`;
}

const preview = () => document.querySelector('[data-cmk-preview]');
const widget = () => document.querySelector('[data-cmk-preview-widget]');
const warnings = () =>
	[...document.querySelectorAll('[data-cmk-contrast-pair]')].filter((li) => !li.hidden).map((li) => li.textContent);
let iconHandler = null;
let fileDetails = null;
const change = (id, value) => {
	const field = document.getElementById(id);
	field.value = value;
	field.dispatchEvent(new Event('input', { bubbles: true }));
};

beforeEach(() => {
	page();
	iconHandler = null;
	fileDetails = vi.fn();
	window.ConcreteFileManager = { getFileDetails: (id, cb) => fileDetails(id, cb) };
	window.jQuery = () => ({ on: (event, selector, fn) => (iconHandler = fn) });
	loadScript('cmk-dashboard.js');
});

afterEach(() => {
	delete window.jQuery;
	delete window.ConcreteFileManager;
	document.body.innerHTML = '';
});

describe('the Appearance preview', () => {
	it('applies valid colours as custom properties', () => {
		change('tokenBg', '#f0f0e0');
		change('tokenButtonFg', '#EEFFEE');

		expect(preview().style.getPropertyValue('--cmk-bg')).toBe('#f0f0e0');
		expect(preview().style.getPropertyValue('--cmk-button-fg')).toBe('#EEFFEE');
	});

	it('never applies anything that is not a six digit hex colour, falling back to the default', () => {
		change('tokenBg', '#f0f0e0');
		['red', '#fff', 'url(javascript:alert(1))', '#102030;}</style><script>alert(1)</script>', ''].forEach((bad) => {
			change('tokenBg', bad);
			expect(preview().style.getPropertyValue('--cmk-bg'), bad).toBe('');
		});
	});

	it('applies position, layout and font only from the allowlist', () => {
		change('position', 'bottom-left');
		change('layout', 'inline');
		change('font', 'system');
		expect(preview().dataset.position).toBe('bottom-left');
		expect(preview().dataset.layout).toBe('inline');
		expect(preview().dataset.font).toBe('system');

		const select = document.getElementById('position');
		select.add(new Option('evil', '"><img src=x onerror=alert(1)>'));
		select.value = '"><img src=x onerror=alert(1)>';
		select.dispatchEvent(new Event('change', { bubbles: true }));
		expect(preview().dataset.position).toBe('bottom-left');
		expect(document.querySelector('img[onerror]')).toBeNull();
	});

	it('clamps the radius to 0 to 32 and ignores nonsense', () => {
		change('radius', '12');
		expect(preview().style.getPropertyValue('--cmk-radius')).toBe('12px');
		change('radius', '999');
		expect(preview().style.getPropertyValue('--cmk-radius')).toBe('12px');
		change('radius', 'abc');
		expect(preview().style.getPropertyValue('--cmk-radius')).toBe('12px');
	});

	it('toggles the shadow', () => {
		const box = document.getElementById('shadow');
		box.checked = false;
		box.dispatchEvent(new Event('change', { bubbles: true }));

		expect(preview().dataset.shadow).toBe('off');
	});

	it('does nothing on pages without a preview', () => {
		document.body.innerHTML = '<input id="tokenBg" value="#ffffff">';

		expect(() => loadScript('cmk-dashboard.js')).not.toThrow();
	});

	it('drops an unreadable pair like the live banner and says why', () => {
		change('tokenButtonBg', '#e9bc29');
		change('tokenAccent', '#e9bc29');

		expect(preview().style.getPropertyValue('--cmk-button-bg')).toBe('');
		expect(preview().style.getPropertyValue('--cmk-button-fg')).toBe('');
		expect(preview().style.getPropertyValue('--cmk-accent')).toBe('');
		expect(preview().style.getPropertyValue('--cmk-bg')).toBe('#ffffff');
		expect(warnings()).toEqual(['button 1.8', 'accent 1.8']);
		expect(document.querySelector('[data-cmk-contrast]').hidden).toBe(false);

		change('tokenButtonFg', '#111111');
		expect(preview().style.getPropertyValue('--cmk-button-bg')).toBe('#e9bc29');
		expect(warnings()).toEqual(['accent 1.8']);
	});

	it('hides the warnings when every pair is readable', () => {
		expect(warnings()).toEqual([]);
		expect(document.querySelector('[data-cmk-contrast]').hidden).toBe(true);
	});

	it('gives the settings button the button colours unless it has its own', () => {
		change('tokenButtonBg', '#003300');
		expect(widget().style.getPropertyValue('--cmk-widget-bg')).toBe('#003300');

		const own = document.getElementById('widgetCustomColours');
		own.checked = true;
		own.dispatchEvent(new Event('change', { bubbles: true }));
		change('tokenWidgetBg', '#eeeeee');

		expect(widget().style.getPropertyValue('--cmk-widget-bg')).toBe('');
		expect(warnings()).toEqual(['widget 1.2']);
	});

	it('only warns about the settings button when it has its own colours', () => {
		change('tokenButtonBg', '#eeeeee');

		expect(warnings()).toEqual(['button 1.2']);
	});

	it('switches the settings button between icon and wording', () => {
		expect(widget().dataset.display).toBe('icon');
		expect(widget().querySelector('span').hidden).toBe(true);

		const select = document.getElementById('widgetDisplay');
		select.value = 'both';
		select.dispatchEvent(new Event('change', { bubbles: true }));

		expect(widget().dataset.display).toBe('both');
		expect(widget().querySelector('img').hidden).toBe(false);
		expect(widget().querySelector('span').hidden).toBe(false);
	});

	it('shows a newly chosen icon and only accepts images with a safe URL', () => {
		const input = document.querySelector('input[name="iconFileId"]');
		input.value = '9';
		fileDetails.mockImplementation((id, cb) => cb({ files: [{ genericType: 1, url: '/files/new.png' }] }));
		iconHandler.call(input);

		expect(fileDetails).toHaveBeenCalledWith(9, expect.any(Function));
		expect(preview().querySelector('img').getAttribute('src')).toBe('/files/new.png');
		expect(widget().querySelector('img').getAttribute('src')).toBe('/files/new.png');

		fileDetails.mockImplementation((id, cb) => cb({ files: [{ genericType: 1, url: 'javascript:alert(1)' }] }));
		iconHandler.call(input);
		expect(preview().querySelector('img').hidden).toBe(true);
		expect(widget().dataset.display).toBe('text');

		fileDetails.mockImplementation((id, cb) => cb({ files: [{ genericType: 2, url: '/files/doc.pdf' }] }));
		iconHandler.call(input);
		expect(preview().querySelector('img').hidden).toBe(true);
	});

	it('removes the icon when the choice is cleared', () => {
		const input = document.querySelector('input[name="iconFileId"]');
		input.value = '';
		iconHandler.call(input);

		expect(fileDetails).not.toHaveBeenCalled();
		expect(preview().querySelector('img').hidden).toBe(true);
		expect(widget().dataset.display).toBe('text');
		expect(widget().querySelector('span').hidden).toBe(false);
	});

	it('ignores a slow answer for an icon that has since been replaced', () => {
		const input = document.querySelector('input[name="iconFileId"]');
		const pending = [];
		fileDetails.mockImplementation((id, cb) => pending.push(cb));
		input.value = '9';
		iconHandler.call(input);
		input.value = '10';
		iconHandler.call(input);
		pending[1]({ files: [{ genericType: 1, url: '/files/ten.png' }] });
		pending[0]({ files: [{ genericType: 1, url: '/files/nine.png' }] });

		expect(preview().querySelector('img').getAttribute('src')).toBe('/files/ten.png');
	});

	it('keeps unreadable colours when told to ignore contrast, and still warns', () => {
		const box = document.getElementById('ignoreContrast');
		box.checked = true;
		box.dispatchEvent(new Event('change', { bubbles: true }));
		change('tokenButtonBg', '#e9bc29');
		change('tokenAccent', '#e9bc29');

		expect(preview().style.getPropertyValue('--cmk-button-bg')).toBe('#e9bc29');
		expect(preview().style.getPropertyValue('--cmk-button-fg')).toBe('#ffffff');
		expect(preview().style.getPropertyValue('--cmk-accent')).toBe('#e9bc29');
		expect(widget().style.getPropertyValue('--cmk-widget-bg')).toBe('#e9bc29');
		expect(warnings()).toEqual(['kept button 1.8', 'kept accent 1.8']);

		box.checked = false;
		box.dispatchEvent(new Event('change', { bubbles: true }));
		expect(preview().style.getPropertyValue('--cmk-button-bg')).toBe('');
		expect(warnings()).toEqual(['button 1.8', 'accent 1.8']);
	});
});
