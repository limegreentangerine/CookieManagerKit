import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { loadScript } from './helpers.js';

function page() {
	document.body.innerHTML = `
		<div class="cmk-preview-stage"><div class="cmk" data-cmk-preview data-position="bottom-right" data-layout="stacked" data-font="inherit" data-shadow="on"></div></div>
		<input id="tokenBg" value="#ffffff"><input id="tokenFg" value="#111111"><input id="tokenAccent" value="#0b5fff">
		<input id="tokenButtonBg" value="#0b5fff"><input id="tokenButtonFg" value="#ffffff">
		<select id="position"><option value="bottom-right">x</option><option value="bottom-left">x</option></select>
		<select id="layout"><option value="stacked">x</option><option value="inline">x</option></select>
		<select id="font"><option value="inherit">x</option><option value="system">x</option></select>
		<input id="radius" value="8"><input id="shadow" type="checkbox" checked>`;
}

const preview = () => document.querySelector('[data-cmk-preview]');
const change = (id, value) => {
	const field = document.getElementById(id);
	field.value = value;
	field.dispatchEvent(new Event('input', { bubbles: true }));
};

beforeEach(() => {
	page();
	loadScript('cmk-dashboard.js');
});

afterEach(() => {
	document.body.innerHTML = '';
});

describe('the Appearance preview', () => {
	it('applies valid colours as custom properties', () => {
		change('tokenBg', '#102030');
		change('tokenButtonFg', '#AABBCC');

		expect(preview().style.getPropertyValue('--cmk-bg')).toBe('#102030');
		expect(preview().style.getPropertyValue('--cmk-button-fg')).toBe('#AABBCC');
	});

	it('ignores anything that is not a six digit hex colour', () => {
		change('tokenBg', '#102030');
		['red', '#fff', 'url(javascript:alert(1))', '#102030;}</style><script>alert(1)</script>', ''].forEach((bad) => {
			change('tokenBg', bad);
			expect(preview().style.getPropertyValue('--cmk-bg'), bad).toBe('#102030');
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
		expect(document.querySelector('img')).toBeNull();
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
});
