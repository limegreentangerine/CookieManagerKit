/*
 * Cookie Manager loader. Inlined into <head> on every page, so it has no dependencies and stays small.
 * It reads the consent cookie, activates inert scripts and blocked embeds (iframes the server left without a src)
 * for the categories the visitor consented to, and is the only code that reads or writes the cookie.
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
	var GUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;
	var version = typeof boot.v === 'number' ? boot.v : 0;
	var days = typeof boot.days === 'number' && boot.days > 0 ? boot.days : 180;
	var known = [];
	var handlers = Object.create(null);
	var nonceMode = boot.nonce === true;
	var current = null;
	var prior = null;

	(Array.isArray(boot.cats) ? boot.cats : []).forEach(function (handle) {
		if (typeof handle === 'string' && HANDLE.test(handle) && known.indexOf(handle) === -1) {
			known.push(handle);
		}
	});

	if (known.indexOf(NECESSARY) === -1) {
		known.unshift(NECESSARY); /* save() always records it, so parse() must accept it */
	}

	/* Google Consent Mode v2: the fixed category-to-signal mapping, in one place. */
	var SIGNALS = {
		ad_storage: ['marketing'],
		ad_user_data: ['marketing'],
		ad_personalization: ['marketing'],
		analytics_storage: ['analytics'],
		functionality_storage: ['preferences'],
		personalization_storage: ['preferences'],
		security_storage: [NECESSARY]
	};
	var consentMode = boot.cm === true;
	var optOut = boot.model === 'opt_out';

	function signalsFor(granted) {
		var out = {};

		granted = granted.concat([NECESSARY]);

		Object.keys(SIGNALS).forEach(function (name) {
			out[name] = SIGNALS[name].some(function (handle) {
				return granted.indexOf(handle) !== -1;
			})
				? 'granted'
				: 'denied';
		});

		return out;
	}

	function gtagConsent(mode, state) {
		/* Google's tag only recognises an `arguments` object in the dataLayer, so use the standard shim. */
		w.dataLayer = w.dataLayer || [];
		w.gtag =
			w.gtag ||
			function () {
				w.dataLayer.push(arguments);
			};
		w.gtag('consent', mode, state);
	}

	function consentModeDefault() {
		var state = signalsFor([]);

		state.wait_for_update = 500;
		gtagConsent('default', state);
	}

	function gpc() {
		try {
			return w.navigator.globalPrivacyControl === true;
		} catch (e) {
			return false;
		}
	}

	/* What is on before the visitor has chosen anything. A stored choice always replaces it. */
	function initial() {
		var list = optOut ? known.slice() : [NECESSARY];

		/* A stale choice is not consent, but it is still a refusal: what they did not accept earlier stays off. */
		if (prior) {
			list = list.filter(function (handle) {
				return handle === NECESSARY || prior.c.indexOf(handle) !== -1;
			});
		}

		if (gpc()) {
			list = list.filter(function (handle) {
				return handle !== 'marketing';
			});
		}

		return list;
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

			var parsed = { v: data.v, c: categories, t: data.t };

			if (typeof data.i === 'string' && GUID.test(data.i)) {
				parsed.i = data.i;
			}

			return parsed;
		} catch (e) {
			return null;
		}
	}

	/* A cookie from another policy version is not consent, but its id is how the visitor stays one record. */
	function readPrior(raw) {
		if (!raw) {
			return null;
		}

		try {
			var data = JSON.parse(decodeURIComponent(raw));

			if (
				!data ||
				typeof data !== 'object' ||
				Array.isArray(data) ||
				typeof data.v !== 'number' ||
				data.v === version
			) {
				return null;
			}

			var earlier = [];

			if (Array.isArray(data.c)) {
				for (var i = 0; i < data.c.length; i++) {
					if (
						typeof data.c[i] === 'string' &&
						known.indexOf(data.c[i]) !== -1 &&
						earlier.indexOf(data.c[i]) === -1
					) {
						earlier.push(data.c[i]);
					}
				}
			}

			return {
				id: typeof data.i === 'string' && GUID.test(data.i) ? data.i : '',
				c: earlier
			};
		} catch (e) {
			return null;
		}
	}

	function write() {
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
	}

	function currentId() {
		return current && current.i ? current.i : (prior && prior.id) || '';
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
		var granted = current ? current.c : initial();
		var nodes = d.querySelectorAll('script[type="text/plain"][data-cmk-category]');

		for (var i = 0; i < nodes.length; i++) {
			var inert = nodes[i];

			if (
				inert.hasAttribute('data-cmk-done') ||
				granted.indexOf(inert.getAttribute('data-cmk-category')) === -1
			) {
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

		/*
		 * Blocked embeds: the server leaves the iframe without a src, with data-cmk-embed (category) and
		 * data-cmk-src. `granted` only holds known handles, so an unknown category never matches. A fail-closed
		 * srcdoc iframe carries no data-cmk-* and is never touched here.
		 */
		var frames = d.querySelectorAll('iframe[data-cmk-embed][data-cmk-src]');

		for (var f = 0; f < frames.length; f++) {
			var frame = frames[f];
			var source = frame.getAttribute('data-cmk-src');

			if (
				frame.hasAttribute('data-cmk-done') ||
				granted.indexOf(frame.getAttribute('data-cmk-embed')) === -1 ||
				!/^https?:\/\//i.test(source)
			) {
				continue;
			}

			frame.setAttribute('data-cmk-done', '1');
			frame.setAttribute('src', source);
			frame.removeAttribute('data-cmk-src');

			var wrapper = frame.parentNode;

			if (wrapper && wrapper.classList && wrapper.classList.contains('cmk-embed')) {
				for (var c = wrapper.firstChild; c; c = c.nextSibling) {
					if (c.nodeType === 1 && c.hasAttribute('data-cmk-embed-placeholder')) {
						wrapper.removeChild(c);
						break;
					}
				}
			}
		}
	}

	function save(categories) {
		var list = [NECESSARY];

		(Array.isArray(categories) ? categories : []).forEach(function (handle) {
			if (
				typeof handle === 'string' &&
				known.indexOf(handle) !== -1 &&
				list.indexOf(handle) === -1
			) {
				list.push(handle);
			}
		});

		var previous = current;
		var id = currentId();

		current = { v: version, c: list, t: Math.floor(Date.now() / 1000) };

		if (id) {
			current.i = id;
		}

		write();

		if (consentMode) {
			gtagConsent('update', signalsFor(list));
		}

		activate();
		emit('consent:changed', {
			categories: list.slice(),
			previous: previous ? previous.c.slice() : null
		});

		return list.slice();
	}

	function ready(fn) {
		if (d.readyState === 'loading') {
			d.addEventListener('DOMContentLoaded', fn);
		} else {
			fn();
		}
	}

	var raw = readRaw();

	current = parse(raw);
	prior = current ? null : readPrior(raw);

	if (consentMode) {
		consentModeDefault();

		if (current) {
			gtagConsent('update', signalsFor(current.c));
		} else if (initial().length > 1) {
			gtagConsent('update', signalsFor(initial()));
		}
	}

	w.cmk = {
		version: version,
		known: known.slice(),
		get: function () {
			if (!current) {
				return null;
			}

			var copy = { v: current.v, c: current.c.slice(), t: current.t };

			if (current.i) {
				copy.i = current.i;
			}

			return copy;
		},
		id: currentId,
		reconsent: function () {
			return !current && prior !== null;
		},
		setId: function (id) {
			if (!current || typeof id !== 'string' || !GUID.test(id)) {
				return false;
			}

			current.i = id;
			write();

			return true;
		},
		has: function (handle) {
			return (current ? current.c : initial()).indexOf(handle) !== -1;
		},
		gpc: gpc,
		initial: initial,
		save: save,
		activate: activate,
		on: function (name, fn) {
			if (typeof fn === 'function') {
				(handlers[name] = handlers[name] || []).push(fn);
			}
		}
	};

	ready(activate);
})(window, document);
