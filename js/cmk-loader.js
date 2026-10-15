/*
 * Cookie Manager loader. Inlined into <head> on every page, so it has no dependencies and stays small.
 * It reads the consent cookie, activates inert scripts for the categories the visitor consented to, and is the
 * only code that reads or writes the cookie.
 *
 * Reads window.cmkBoot: { v: policy version, cats: [known category handles], days: cookie lifetime }.
 */
(function (w, d) {
	'use strict';

	if (w.cmk) {
		return;
	}

	/* Must be read now: currentScript is only set while this tag runs. */
	var ownNonce = '';

	try {
		var self = d.currentScript;

		if (self) {
			ownNonce = self.nonce || self.getAttribute('nonce') || '';
		}
	} catch (e) {
		ownNonce = '';
	}

	var boot = w.cmkBoot || {};
	var COOKIE = 'cmk_consent';
	var NECESSARY = 'necessary';
	var HANDLE = /^[a-z0-9_-]{1,32}$/;
	var version = typeof boot.v === 'number' ? boot.v : 0;
	var days = typeof boot.days === 'number' && boot.days > 0 ? boot.days : 180;
	var known = [];
	var handlers = Object.create(null);
	var nonceMode = boot.nonce === true;
	var current = null;

	(Array.isArray(boot.cats) ? boot.cats : []).forEach(function (handle) {
		if (typeof handle === 'string' && HANDLE.test(handle) && known.indexOf(handle) === -1) {
			known.push(handle);
		}
	});

	if (known.indexOf(NECESSARY) === -1) {
		known.unshift(NECESSARY); /* save() always records it, so parse() must accept it */
	}

	function readRaw() {
		var parts;

		try {
			parts = d.cookie ? d.cookie.split('; ') : [];
		} catch (e) {
			return null; /* cookie jar unreadable (sandboxed iframe): treat as no consent */
		}

		for (var i = 0; i < parts.length; i++) {
			var eq = parts[i].indexOf('=');

			if (eq > 0 && parts[i].slice(0, eq) === COOKIE) {
				return parts[i].slice(eq + 1);
			}
		}

		return null;
	}

	/* Anything unexpected is "no consent": the visitor is asked again rather than trusted with a guess. */
	function parse(raw) {
		if (!raw) {
			return null;
		}

		try {
			var data = JSON.parse(decodeURIComponent(raw));

			if (!data || typeof data !== 'object' || Array.isArray(data)) {
				return null;
			}

			if (data.v !== version || !Array.isArray(data.c) || typeof data.t !== 'number') {
				return null;
			}

			var categories = [];

			for (var i = 0; i < data.c.length; i++) {
				if (typeof data.c[i] !== 'string' || known.indexOf(data.c[i]) === -1) {
					return null;
				}

				if (categories.indexOf(data.c[i]) === -1) {
					categories.push(data.c[i]);
				}
			}

			if (categories.indexOf(NECESSARY) === -1) {
				return null;
			}

			return { v: data.v, c: categories, t: data.t };
		} catch (e) {
			return null;
		}
	}

	function emit(name, payload) {
		(handlers[name] || []).slice().forEach(function (fn) {
			try {
				fn(payload);
			} catch (e) {
				/* one listener must not stop the others */
			}
		});
	}

	function activate() {
		var granted = current ? current.c : [NECESSARY];
		var nodes = d.querySelectorAll('script[type="text/plain"][data-cmk-category]');

		for (var i = 0; i < nodes.length; i++) {
			var inert = nodes[i];

			if (inert.hasAttribute('data-cmk-done') || granted.indexOf(inert.getAttribute('data-cmk-category')) === -1) {
				continue;
			}

			/*
			 * With a CSP nonce on the page, only activate inert scripts carrying this loader's own nonce, which an
			 * attacker cannot know. Otherwise injected markup that looks inert (text/plain) would become live script.
			 */
			var nonce = inert.nonce || inert.getAttribute('nonce');

			if (nonceMode && (!ownNonce || nonce !== ownNonce)) {
				continue;
			}

			inert.setAttribute('data-cmk-done', '1');

			var live = d.createElement('script');

			for (var a = 0; a < inert.attributes.length; a++) {
				var name = inert.attributes[a].name;

				if (name !== 'type' && name.indexOf('data-cmk-') !== 0) {
					live.setAttribute(name, inert.attributes[a].value);
				}
			}

			if (nonce) {
				live.nonce = nonce;
			}

			if (inert.getAttribute('data-cmk-type')) {
				live.type = inert.getAttribute('data-cmk-type');
			}

			if (inert.getAttribute('src')) {
				live.async = false; /* keep document order for external scripts */
			}

			live.text = inert.text;
			inert.parentNode.insertBefore(live, inert.nextSibling);
		}
	}

	function save(categories) {
		var list = [NECESSARY];

		(Array.isArray(categories) ? categories : []).forEach(function (handle) {
			if (typeof handle === 'string' && known.indexOf(handle) !== -1 && list.indexOf(handle) === -1) {
				list.push(handle);
			}
		});

		var previous = current;

		current = { v: version, c: list, t: Math.floor(Date.now() / 1000) };

		try {
			d.cookie =
				COOKIE +
				'=' +
				encodeURIComponent(JSON.stringify(current)) +
				'; Path=/; Max-Age=' +
				Math.round(days * 86400) +
				'; SameSite=Lax' +
				(w.location.protocol === 'https:' ? '; Secure' : '');
		} catch (e) {
			/* cookies blocked: the choice still applies to this page view */
		}

		activate();
		emit('consent:changed', { categories: list.slice(), previous: previous ? previous.c.slice() : null });

		return list.slice();
	}

	function ready(fn) {
		if (d.readyState === 'loading') {
			d.addEventListener('DOMContentLoaded', fn);
		} else {
			fn();
		}
	}

	current = parse(readRaw());

	w.cmk = {
		version: version,
		known: known.slice(),
		get: function () {
			return current ? { v: current.v, c: current.c.slice(), t: current.t } : null;
		},
		has: function (handle) {
			return current ? current.c.indexOf(handle) !== -1 : handle === NECESSARY;
		},
		save: save,
		activate: activate,
		on: function (name, fn) {
			if (typeof fn === 'function') {
				(handlers[name] = handlers[name] || []).push(fn);
			}
		},
	};

	ready(activate);
})(window, document);
