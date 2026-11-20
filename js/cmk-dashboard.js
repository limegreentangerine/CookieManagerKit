/*
 * Appearance page live preview. Only validated values are applied: six digit hex colours as CSS custom
 * properties, a clamped radius, data attributes taken from fixed allowlists and http(s) or path icon URLs.
 * Nothing is parsed as markup.
 */
(function (w, d) {
	'use strict';

	var HEX = /^#[0-9a-fA-F]{6}$/;
	var FIELDS = {
		bg: 'tokenBg',
		fg: 'tokenFg',
		accent: 'tokenAccent',
		buttonBg: 'tokenButtonBg',
		buttonFg: 'tokenButtonFg'
	};
	var VARS = {
		bg: '--cmk-bg',
		fg: '--cmk-fg',
		accent: '--cmk-accent',
		buttonBg: '--cmk-button-bg',
		buttonFg: '--cmk-button-fg',
		widgetBg: '--cmk-widget-bg',
		widgetFg: '--cmk-widget-fg'
	};
	var CHOICES = {
		position: ['bottom-left', 'bottom-right', 'bottom-bar', 'center-modal'],
		layout: ['stacked', 'inline'],
		font: ['inherit', 'system']
	};
	var DISPLAYS = ['text', 'icon', 'both'];
	var iconUrl = '';
	var iconRequest = 0;

	function field(id) {
		return d.getElementById(id);
	}

	function luminance(hex) {
		return [1, 3, 5]
			.map(function (i) {
				var c = parseInt(hex.substr(i, 2), 16) / 255;

				return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
			})
			.reduce(function (sum, c, i) {
				return sum + c * [0.2126, 0.7152, 0.0722][i];
			}, 0);
	}

	function ratio(a, b) {
		var x = luminance(a);
		var y = luminance(b);

		return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
	}

	/* The colours as the live banner receives them: the settings button falls back to the button colours. */
	function readTokens() {
		var tokens = {};

		Object.keys(FIELDS).forEach(function (name) {
			var input = field(FIELDS[name]);

			if (input && HEX.test(input.value)) {
				tokens[name] = input.value;
			}
		});

		var custom = field('widgetCustomColours');
		var widgetBg = field('tokenWidgetBg');
		var widgetFg = field('tokenWidgetFg');

		tokens.widgetBg =
			custom && custom.checked && widgetBg && HEX.test(widgetBg.value)
				? widgetBg.value
				: tokens.buttonBg;
		tokens.widgetFg =
			custom && custom.checked && widgetFg && HEX.test(widgetFg.value)
				? widgetFg.value
				: tokens.buttonFg;

		return tokens;
	}

	/*
	 * Mirror of applyStyle() in cmk-runtime.js: a pair under 4.5:1 is dropped so the stylesheet defaults apply,
	 * unless keep (the ignore-contrast setting) is on. Returns the measured ratio of each failing pair, keyed by
	 * warning name. Keep the two in step.
	 */
	function dropUnreadable(tokens, keep) {
		var dropped = {};

		function pair(warning, fg, bg) {
			if (
				HEX.test(tokens[fg]) &&
				HEX.test(tokens[bg]) &&
				ratio(tokens[fg], tokens[bg]) < 4.5
			) {
				dropped[warning] = ratio(tokens[fg], tokens[bg]);
				if (!keep) {
					delete tokens[fg];
					delete tokens[bg];
				}
			}
		}

		pair('text', 'fg', 'bg');
		pair('button', 'buttonFg', 'buttonBg');
		pair('widget', 'widgetFg', 'widgetBg');

		var against = HEX.test(tokens.bg) ? tokens.bg : '#ffffff';

		if (HEX.test(tokens.accent) && ratio(tokens.accent, against) < 4.5) {
			dropped.accent = ratio(tokens.accent, against);
			if (!keep) {
				delete tokens.accent;
			}
		}

		return dropped;
	}

	function warn(dropped, custom, keep) {
		var box = d.querySelector('[data-cmk-contrast]');

		if (!box) {
			return;
		}

		var any = false;

		Array.prototype.forEach.call(
			box.querySelectorAll('[data-cmk-contrast-pair]'),
			function (item) {
				var name = item.getAttribute('data-cmk-contrast-pair');
				/* without its own colours the settings button repeats the button warning */
				var show = name in dropped && (name !== 'widget' || custom);

				item.hidden = !show;
				item.textContent = show
					? (item.getAttribute(keep ? 'data-kept' : 'data-message') || '').replace(
							'%s',
							dropped[name].toFixed(1)
						)
					: '';
				any = any || show;
			}
		);
		box.hidden = !any;
	}

	function paint(node, tokens, radius) {
		Object.keys(VARS).forEach(function (name) {
			if (HEX.test(tokens[name])) {
				node.style.setProperty(VARS[name], tokens[name]);
			} else {
				node.style.removeProperty(VARS[name]);
			}
		});

		if (radius >= 0 && radius <= 32) {
			node.style.setProperty('--cmk-radius', radius + 'px');
		}
	}

	function apply() {
		var preview = d.querySelector('[data-cmk-preview]');

		if (!preview) {
			return;
		}

		var tokens = readTokens();
		var custom = field('widgetCustomColours');

		var ignore = field('ignoreContrast');
		var keep = Boolean(ignore && ignore.checked);

		warn(dropUnreadable(tokens, keep), Boolean(custom && custom.checked), keep);

		var radiusField = field('radius');
		var radius = radiusField ? parseInt(radiusField.value, 10) : NaN;
		var widget = d.querySelector('[data-cmk-preview-widget]');

		paint(preview, tokens, radius);

		Object.keys(CHOICES).forEach(function (id) {
			var input = field(id);

			if (input && CHOICES[id].indexOf(input.value) !== -1) {
				preview.setAttribute('data-' + id, input.value);
			}
		});

		var shadow = field('shadow');

		if (shadow) {
			preview.setAttribute('data-shadow', shadow.checked ? 'on' : 'off');
		}

		var icon = preview.querySelector('[data-cmk-preview-icon]');

		if (icon) {
			icon.hidden = iconUrl === '';
			if (iconUrl !== '' && icon.getAttribute('src') !== iconUrl) {
				icon.setAttribute('src', iconUrl);
			}
		}

		if (widget) {
			paint(widget, tokens, radius);

			var choice = field('widgetDisplay');
			/* like the live button: no icon means the wording is shown */
			var display =
				iconUrl !== '' && choice && DISPLAYS.indexOf(choice.value) !== -1
					? choice.value
					: 'text';
			var widgetIcon = widget.querySelector('img');
			var words = widget.querySelector('span');

			widget.setAttribute('data-display', display);
			if (widgetIcon) {
				widgetIcon.hidden = display === 'text';
				if (iconUrl !== '' && widgetIcon.getAttribute('src') !== iconUrl) {
					widgetIcon.setAttribute('src', iconUrl);
				}
			}
			if (words) {
				words.hidden = display === 'icon';
			}
		}
	}

	function safeIconUrl(url) {
		return typeof url === 'string' && /^(https?:\/\/|\/(?!\/))/i.test(url) ? url : '';
	}

	/* The file selector reports a new choice with a jQuery change on its hidden input; look up the file's URL. */
	function iconChanged(input) {
		var id = parseInt(input.value, 10);
		var request = ++iconRequest;

		if (
			!(id > 0) ||
			!w.ConcreteFileManager ||
			typeof w.ConcreteFileManager.getFileDetails !== 'function'
		) {
			iconUrl = '';
			apply();

			return;
		}

		w.ConcreteFileManager.getFileDetails(id, function (response) {
			var file = response && response.files && response.files[0];

			if (request !== iconRequest) {
				return;
			}

			/* genericType 1 is an image; anything else gives no icon, as on the live banner */
			iconUrl = file && Number(file.genericType) === 1 ? safeIconUrl(file.url) : '';
			apply();
		});
	}

	var initialIcon = d.querySelector('[data-cmk-preview-icon]');

	iconUrl = initialIcon ? safeIconUrl(initialIcon.getAttribute('src')) : '';

	d.addEventListener('input', apply);
	d.addEventListener('change', apply);
	if (w.jQuery) {
		w.jQuery(d).on('change', 'input[name="iconFileId"]', function () {
			iconChanged(this);
		});
	}
	apply();
})(window, document);
