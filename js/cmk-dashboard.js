/*
 * Appearance page live preview. Only validated values are applied: six digit hex colours as CSS custom
 * properties, a clamped radius, and data attributes taken from fixed allowlists. Nothing is parsed as markup.
 */
(function (d) {
	'use strict';

	var HEX = /^#[0-9a-fA-F]{6}$/;
	var VARS = {
		tokenBg: '--cmk-bg',
		tokenFg: '--cmk-fg',
		tokenAccent: '--cmk-accent',
		tokenButtonBg: '--cmk-button-bg',
		tokenButtonFg: '--cmk-button-fg'
	};
	var CHOICES = {
		position: ['bottom-left', 'bottom-right', 'bottom-bar', 'center-modal'],
		layout: ['stacked', 'inline'],
		font: ['inherit', 'system']
	};

	function apply() {
		var preview = d.querySelector('[data-cmk-preview]');

		if (!preview) {
			return;
		}

		Object.keys(VARS).forEach(function (id) {
			var field = d.getElementById(id);

			if (field && HEX.test(field.value)) {
				preview.style.setProperty(VARS[id], field.value);
			}
		});

		Object.keys(CHOICES).forEach(function (id) {
			var field = d.getElementById(id);

			if (field && CHOICES[id].indexOf(field.value) !== -1) {
				preview.setAttribute('data-' + id, field.value);
			}
		});

		var radiusField = d.getElementById('radius');
		var radius = radiusField ? parseInt(radiusField.value, 10) : NaN;

		if (radius >= 0 && radius <= 32) {
			preview.style.setProperty('--cmk-radius', radius + 'px');
		}

		var shadow = d.getElementById('shadow');

		if (shadow) {
			preview.setAttribute('data-shadow', shadow.checked ? 'on' : 'off');
		}
	}

	d.addEventListener('input', apply);
	d.addEventListener('change', apply);
	apply();
})(document);
