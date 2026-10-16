/*
 * Cookie Manager runtime: builds the banner and the preference centre from the config JSON.
 * Everything is built with createElement, textContent and setAttribute. Nothing from the config or from the
 * visitor is ever parsed as markup, and links exist only for http(s) or relative URLs.
 */
(function (w, d) {
	'use strict';

	var boot = w.cmkBoot || {};
	var HEX = /^#[0-9a-fA-F]{6}$/;
	var HANDLE = /^[a-z0-9_-]{1,32}$/;
	var POSITIONS = ['bottom-left', 'bottom-right', 'bottom-bar', 'center-modal'];
	var LAYOUTS = ['stacked', 'inline'];
	var FONTS = ['inherit', 'system'];
	var TOKEN_VARS = {
		bg: '--cmk-bg',
		fg: '--cmk-fg',
		accent: '--cmk-accent',
		buttonBg: '--cmk-button-bg',
		buttonFg: '--cmk-button-fg'
	};

	var cmk = null;
	var state = { config: null, root: null, widget: null, opener: null, started: false };
	var FALLBACK = {
		title: 'We value your privacy',
		acceptAll: 'Accept all',
		rejectAll: 'Reject all',
		customise: 'Customise',
		save: 'Save my choices',
		widget: 'Cookie settings'
	};
	var runtime = {
		reload: function () {
			w.location.reload();
		}
	};

	function text(value, fallback) {
		return typeof value === 'string' && value.trim() !== '' ? value : fallback;
	}

	function pick(value, allowed, fallback) {
		return typeof value === 'string' && allowed.indexOf(value) !== -1 ? value : fallback;
	}

	function safeHref(url) {
		if (typeof url !== 'string') {
			return '';
		}

		var trimmed = url.trim();
		var normalised = trimmed.replace(/[\u0000- ]+/g, '');
		var scheme = /^([a-z][a-z0-9+.-]*):/i.exec(normalised);

		if (normalised === '' || /^[\/\\]{2}/.test(normalised)) {
			return '';
		}

		return scheme && !/^https?$/i.test(scheme[1]) ? '' : trimmed;
	}

	function el(tag, attributes, children) {
		var node = d.createElement(tag);

		Object.keys(attributes || {}).forEach(function (name) {
			var value = attributes[name];

			if (value === null || value === undefined || value === false) {
				return;
			}

			if (name === 'text') {
				node.textContent = String(value);
			} else {
				node.setAttribute(name, value === true ? '' : String(value));
			}
		});

		(children || []).forEach(function (child) {
			if (child) {
				node.appendChild(typeof child === 'string' ? d.createTextNode(child) : child);
			}
		});

		return node;
	}

	/* Text with line breaks: text nodes and <br> elements we create ourselves. */
	function lines(text) {
		var out = [];

		String(text || '')
			.split(/\r\n|\r|\n/)
			.forEach(function (line, index) {
				if (index > 0) {
					out.push(d.createElement('br'));
				}

				out.push(d.createTextNode(line));
			});

		return out;
	}

	function applyStyle(node, style) {
		var tokens = style.tokens || {};

		Object.keys(TOKEN_VARS).forEach(function (name) {
			if (typeof tokens[name] === 'string' && HEX.test(tokens[name])) {
				node.style.setProperty(TOKEN_VARS[name], tokens[name]);
			}
		});

		var radius = parseInt(style.radius, 10);

		if (radius >= 0 && radius <= 32) {
			node.style.setProperty('--cmk-radius', radius + 'px');
		}
	}

	function button(className, text, onClick) {
		var node = el('button', { type: 'button', class: className, text: text });
		node.addEventListener('click', onClick);

		return node;
	}

	function validCategories(config) {
		var seen = {};

		return (Array.isArray(config.categories) ? config.categories : []).filter(
			function (category) {
				if (
					!category ||
					typeof category.handle !== 'string' ||
					!HANDLE.test(category.handle) ||
					Object.prototype.hasOwnProperty.call(seen, category.handle)
				) {
					return false;
				}

				seen[category.handle] = true;

				return true;
			}
		);
	}

	function servicesList(category) {
		var items = [];

		(Array.isArray(category.services) ? category.services : []).forEach(function (service) {
			if (service && typeof service.name === 'string') {
				items.push(
					el('li', {}, [
						el('strong', { text: service.name }),
						service.vendor ? ' (' + service.vendor + ')' : null,
						service.description ? ': ' + service.description : null
					])
				);
			}
		});

		return items.length ? el('ul', { class: 'cmk__services' }, items) : null;
	}

	function cookieEntry(entry) {
		var item = typeof entry === 'string' ? { name: entry } : entry;

		if (
			!item ||
			typeof item !== 'object' ||
			typeof item.name !== 'string' ||
			item.name === ''
		) {
			return null;
		}

		var purpose = typeof item.purpose === 'string' ? item.purpose : '';
		var duration = typeof item.duration === 'string' ? item.duration : '';
		var detail =
			(purpose ? ' \u2014 ' + purpose : '') + (duration ? ' (' + duration + ')' : '');

		return el('li', {}, [
			el('code', { text: item.name }),
			detail ? d.createTextNode(detail) : null
		]);
	}

	function cookiesList(category) {
		var items = (Array.isArray(category.cookies) ? category.cookies : [])
			.map(cookieEntry)
			.filter(Boolean);

		return items.length ? el('ul', { class: 'cmk__cookies' }, items) : null;
	}

	function ticked(prefs) {
		var handles = [];

		Array.prototype.forEach.call(prefs.querySelectorAll('input[data-cmk-cat]'), function (box) {
			if (box.checked && !box.disabled) {
				handles.push(box.getAttribute('data-cmk-cat'));
			}
		});

		return handles;
	}

	function post(categories) {
		if (!boot.post || typeof w.fetch !== 'function') {
			return;
		}

		try {
			w.fetch(boot.post, {
				method: 'POST',
				credentials: 'same-origin',
				keepalive: true,
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ v: boot.v, c: categories })
			}).catch(function () {
				/* the choice is already saved in the browser; the acknowledgement is best effort */
			});
		} catch (e) {
			/* same */
		}
	}

	function track(name) {
		if (!boot.event || typeof w.fetch !== 'function') {
			return;
		}

		try {
			w.fetch(boot.event, {
				method: 'POST',
				credentials: 'same-origin',
				keepalive: true,
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({
					v: boot.v,
					e: name,
					l: String((state.config && state.config.locale) || '')
				})
			}).catch(function () {
				/* statistics are best effort and never affect the visitor's choice */
			});
		} catch (e) {
			/* same */
		}
	}

	// Once per tab session and policy version. Blocked storage means the page view is simply counted.
	function trackShown() {
		var key = 'cmk_shown_' + boot.v;

		try {
			if (w.sessionStorage.getItem(key)) {
				return;
			}

			w.sessionStorage.setItem(key, '1');
		} catch (e) {
			/* storage blocked */
		}

		track('shown');
	}

	function choiceEvent(previous, saved, categories) {
		if (previous) {
			return 'changed';
		}

		if (saved.length === 1) {
			return 'reject_all';
		}

		return categories.every(function (category) {
			return saved.indexOf(category.handle) !== -1;
		})
			? 'accept_all'
			: 'custom';
	}

	function close(restoreFocus) {
		if (state.root && state.root.parentNode) {
			state.root.parentNode.removeChild(state.root);
		}

		state.root = null;

		if (restoreFocus) {
			var opener = state.opener;
			state.opener = null;

			if (
				opener &&
				opener !== d.body &&
				d.documentElement.contains(opener) &&
				typeof opener.focus === 'function' &&
				!opener.disabled
			) {
				opener.focus();
			} else if (state.widget) {
				state.widget.focus();
			}
		}
	}

	function commit(handles) {
		var previous = cmk.get();
		var saved = cmk.save(handles);
		var withdrew =
			previous &&
			previous.c.some(function (handle) {
				return saved.indexOf(handle) === -1;
			});

		post(saved);
		track(choiceEvent(previous, saved, validCategories(state.config)));
		close(true);

		if (withdrew) {
			runtime.reload();
		}
	}

	function build(config, expanded) {
		var banner = config.banner || {};
		var labels = banner.labels || {};
		var style = config.style || {};
		var granted = cmk.get() ? cmk.get().c : [];
		var categories = validCategories(config);

		var root = el('div', {
			class: 'cmk',
			role: 'dialog',
			'aria-modal': style.position === 'center-modal' ? 'true' : 'false',
			'aria-labelledby': 'cmk-title',
			'aria-describedby': 'cmk-body',
			'data-position': pick(style.position, POSITIONS, 'bottom-right'),
			'data-layout': pick(style.layout, LAYOUTS, 'stacked'),
			'data-font': pick(style.font, FONTS, 'inherit'),
			'data-shadow': style.shadow ? 'on' : 'off'
		});

		applyStyle(root, style);

		var prefs = el('div', { class: 'cmk__prefs', id: 'cmk-prefs' });
		prefs.hidden = !expanded;

		categories.forEach(function (category) {
			var necessary = category.necessary === true;
			var box = el('input', {
				type: 'checkbox',
				id: 'cmk-cat-' + category.handle,
				'data-cmk-cat': category.handle
			});
			box.checked = necessary || granted.indexOf(category.handle) !== -1;
			box.disabled = necessary;

			prefs.appendChild(
				el('div', { class: 'cmk__cat' }, [
					el('label', { class: 'cmk__cat-head', for: 'cmk-cat-' + category.handle }, [
						box,
						el('span', {
							class: 'cmk__cat-title',
							text: text(category.title, category.handle)
						})
					]),
					category.description
						? el('p', { class: 'cmk__cat-desc' }, lines(category.description))
						: null,
					servicesList(category),
					necessary ? cookiesList(category) : null
				])
			);
		});

		prefs.appendChild(
			button('cmk__btn cmk__btn--primary', text(labels.save, FALLBACK.save), function () {
				commit(ticked(prefs));
			})
		);

		var customise = button(
			'cmk__btn cmk__btn--link',
			text(labels.customise, FALLBACK.customise),
			function () {
				prefs.hidden = !prefs.hidden;
				customise.setAttribute('aria-expanded', prefs.hidden ? 'false' : 'true');
			}
		);
		customise.setAttribute('aria-controls', 'cmk-prefs');
		customise.setAttribute('aria-expanded', expanded ? 'true' : 'false');

		var href = safeHref(banner.policyUrl);

		root.appendChild(
			el('div', { class: 'cmk__panel' }, [
				el('h2', {
					id: 'cmk-title',
					class: 'cmk__title',
					tabindex: '-1',
					text: text(banner.title, FALLBACK.title)
				}),
				el('p', { id: 'cmk-body', class: 'cmk__body' }, lines(banner.body)),
				href && banner.policyLinkText
					? el('a', {
							class: 'cmk__link',
							href: href,
							rel: 'noopener noreferrer',
							text: banner.policyLinkText
						})
					: null,
				el('div', { class: 'cmk__actions' }, [
					button(
						'cmk__btn cmk__btn--primary',
						text(labels.acceptAll, FALLBACK.acceptAll),
						function () {
							commit(
								categories.map(function (category) {
									return category.handle;
								})
							);
						}
					),
					button('cmk__btn', text(labels.rejectAll, FALLBACK.rejectAll), function () {
						commit([]);
					}),
					customise
				]),
				prefs
			])
		);

		return root;
	}

	function show(config, expanded, focus) {
		close(false);
		state.root = build(config, expanded);
		d.body.appendChild(state.root);

		if (focus) {
			var title = state.root.querySelector('#cmk-title');

			if (title) {
				title.focus();
			}
		}
	}

	function mountWidget(config) {
		var labels = (config.banner && config.banner.labels) || {};

		if (config.widget === false || state.widget) {
			return;
		}

		state.widget = el('button', {
			type: 'button',
			class: 'cmk-widget',
			'data-cmk-open': true,
			text: text(labels.widget, FALLBACK.widget)
		});
		applyStyle(state.widget, config.style || {});
		d.body.appendChild(state.widget);
	}

	function onClick(event) {
		var node =
			event.target && event.target.closest ? event.target.closest('[data-cmk-open]') : null;

		if (node) {
			event.preventDefault();
			runtime.open();
		}
	}

	function trap(event) {
		var root = state.root;

		if (!root || root.getAttribute('data-position') !== 'center-modal') {
			return;
		}

		var items = Array.prototype.filter.call(
			root.querySelectorAll('button, a[href], input, [tabindex]:not([tabindex="-1"])'),
			function (node) {
				return !node.disabled && !node.closest('[hidden]');
			}
		);

		if (!items.length) {
			event.preventDefault();

			return;
		}

		var first = items[0];
		var last = items[items.length - 1];
		var active = d.activeElement;

		if (!active || !root.contains(active)) {
			event.preventDefault();
			first.focus();
		} else if (event.shiftKey && active === first) {
			event.preventDefault();
			last.focus();
		} else if (!event.shiftKey && active === last) {
			event.preventDefault();
			first.focus();
		}
	}

	function onKey(event) {
		if (event.key === 'Tab') {
			trap(event);

			return;
		}

		if (event.key === 'Escape' && state.root && cmk && cmk.get()) {
			close(true);
		}
	}

	runtime.start = function (config) {
		cmk = w.cmk;

		if (!cmk || !config || typeof config !== 'object' || state.started) {
			return;
		}

		state.started = true;
		state.config = config;
		d.addEventListener('click', onClick);
		d.addEventListener('keydown', onKey);
		mountWidget(config);

		if (!cmk.get()) {
			trackShown();
			// The centred modal traps Tab (aria-modal), so it holds focus from the start; other positions do not.
			show(config, false, (config.style && config.style.position) === 'center-modal');
		}
	};

	runtime.open = function () {
		if (state.config && cmk) {
			if (!state.root || !state.root.contains(d.activeElement)) {
				state.opener =
					d.activeElement && d.activeElement !== d.body ? d.activeElement : null;
			}

			show(state.config, true, true);
		}
	};

	runtime.stop = function () {
		d.removeEventListener('click', onClick);
		d.removeEventListener('keydown', onKey);
		close(false);
		state.opener = null;

		if (state.widget && state.widget.parentNode) {
			state.widget.parentNode.removeChild(state.widget);
		}

		state.widget = null;
		state.config = null;
		state.started = false;
	};

	runtime.startBeacon = function () {
		var url = boot.scan;

		// Only a same-origin path is ever loaded; the marker cookie exists only in an admin's browser in scan mode.
		if (
			typeof url !== 'string' ||
			!/^\/(?!\/)/.test(url) ||
			d.cookie.indexOf('cmk_scan=1') === -1 ||
			d.querySelector('script[data-cmk-beacon]')
		) {
			return;
		}

		var script = d.createElement('script');

		script.src = url;
		script.async = true;
		script.setAttribute('data-cmk-beacon', '');
		(d.head || d.documentElement).appendChild(script);
	};

	w.cmkRuntime = runtime;

	function ready(fn) {
		if (d.readyState === 'loading') {
			d.addEventListener('DOMContentLoaded', fn);
		} else {
			fn();
		}
	}

	if (!boot.manual) {
		ready(runtime.startBeacon);
	}

	if (!boot.manual && boot.cfg && typeof w.fetch === 'function') {
		ready(function () {
			w.fetch(boot.cfg, { credentials: 'omit' })
				.then(function (response) {
					return response.ok ? response.json() : null;
				})
				.then(function (config) {
					if (config) {
						runtime.start(config);
					}
				})
				.catch(function () {
					/* without a config there is no banner; scripts simply stay blocked */
				});
		});
	}
})(window, document);
