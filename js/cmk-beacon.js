/*
 * Cookie Manager scan-mode beacon. The server sends this file only to a browser that holds a valid scan-mode token
 * (an admin who started scan mode in the dashboard), and the runtime loads it only while the marker cookie is set.
 * It reports cookie NAMES, storage KEYS and the HOSTS of loaded resources. It never reads a cookie or storage value
 * and never changes the page. The token itself is an HttpOnly cookie the browser attaches; this script cannot see it.
 */
(function (w, d) {
	'use strict';

	var MAX_ITEMS = 100;
	var MAX_NAME = 128;
	var MAX_PATH = 512;
	// The server refuses a body over 32768 bytes; stay safely below it.
	var MAX_BYTES = 32000;
	var MAX_FAILURES = 3;
	var SETTLE_MS = 2500;

	function markerSet() {
		return /(^|;\s*)cmk_scan=1(;|$)/.test(d.cookie);
	}

	if (!markerSet() || w.__cmkBeacon) {
		return;
	}

	w.__cmkBeacon = true;

	var endpoint;

	try {
		var current = d.currentScript;
		endpoint = new URL('report', current && current.src ? current.src : w.location.href).href;

		if (new URL(endpoint).origin !== w.location.origin) {
			return;
		}
	} catch (error) {
		return;
	}

	var lastSent = '';
	var failures = 0;
	var stopped = false;

	// Lone surrogates would not survive a strict JSON decode on the server, so they are dropped.
	function wellFormed(text) {
		var out = '';

		for (var i = 0; i < text.length; i++) {
			var code = text.charCodeAt(i);

			if (code >= 0xd800 && code <= 0xdbff) {
				var next = text.charCodeAt(i + 1);

				if (next >= 0xdc00 && next <= 0xdfff) {
					out += text.charAt(i) + text.charAt(i + 1);
					i++;
				}
			} else if (code < 0xdc00 || code > 0xdfff) {
				out += text.charAt(i);
			}
		}

		return out;
	}

	function clip(name) {
		return wellFormed(wellFormed(String(name)).slice(0, MAX_NAME));
	}

	function cap(list) {
		var seen = new Set();
		var out = [];

		for (var i = 0; i < list.length && out.length < MAX_ITEMS; i++) {
			var name = clip(list[i]);

			if (name !== '' && !seen.has(name)) {
				seen.add(name);
				out.push(name);
			}
		}

		return out;
	}

	function utf8Length(text) {
		var total = 0;

		for (var i = 0; i < text.length; i++) {
			var code = text.charCodeAt(i);

			if (code < 0x80) {
				total += 1;
			} else if (code < 0x800) {
				total += 2;
			} else if (code >= 0xd800 && code <= 0xdbff && i + 1 < text.length) {
				total += 4;
				i++;
			} else {
				total += 3;
			}
		}

		return total;
	}

	function cookieNames() {
		var names = [];

		d.cookie.split(';').forEach(function (part) {
			var at = part.indexOf('=');

			// a part without "=" is a nameless cookie whose text is its value, which must never be reported
			if (at > 0) {
				names.push(part.slice(0, at).trim());
			}
		});

		return names;
	}

	function storageKeys(read) {
		var keys = [];

		try {
			var store = read();

			for (var i = 0; i < store.length; i++) {
				keys.push(store.key(i));
			}
		} catch (error) {
			/* blocked or unavailable storage simply reports nothing */
		}

		return keys.filter(function (key) {
			return typeof key === 'string';
		});
	}

	function resourceHosts() {
		var hosts = [];

		try {
			(w.performance.getEntriesByType('resource') || []).forEach(function (entry) {
				try {
					var url = new URL(entry.name);

					if (
						(url.protocol === 'http:' || url.protocol === 'https:') &&
						url.hostname &&
						url.hostname !== w.location.hostname
					) {
						hosts.push(url.hostname);
					}
				} catch (error) {
					/* a name that is not a URL is not a host */
				}
			});
		} catch (error) {
			/* no Performance API */
		}

		return hosts;
	}

	// Drop items from the longest list (ties: s, then c, then h) until the whole body fits, so the result is deterministic.
	function build() {
		var report = {
			c: cap(cookieNames()),
			s: cap(
				storageKeys(function () {
					return w.localStorage;
				}).concat(
					storageKeys(function () {
						return w.sessionStorage;
					})
				)
			),
			h: cap(resourceHosts()),
			p: String(w.location.pathname || '/')
				.replace(/^\/+/, '/')
				.slice(0, MAX_PATH)
		};
		var body = JSON.stringify(report);

		while (utf8Length(body) > MAX_BYTES) {
			var key = ['s', 'c', 'h'].reduce(function (best, name) {
				return report[name].length > report[best].length ? name : best;
			}, 's');

			if (report[key].length === 0) {
				report.p = '/';
				body = JSON.stringify(report);
				break;
			}

			report[key].pop();
			body = JSON.stringify(report);
		}

		return body;
	}

	function stop() {
		stopped = true;
		d.cookie = 'cmk_scan=; Max-Age=0; Path=/; SameSite=Strict';
	}

	function failed() {
		failures++;

		if (failures >= MAX_FAILURES) {
			stopped = true;
		}
	}

	function report() {
		if (stopped) {
			return;
		}

		if (!markerSet()) {
			stopped = true;
			return;
		}

		if (typeof w.fetch !== 'function') {
			return;
		}

		var body = build();

		if (body === lastSent) {
			return;
		}

		lastSent = body;

		w.fetch(endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			keepalive: true,
			headers: { 'Content-Type': 'application/json' },
			body: body
		})
			.then(function (response) {
				var status = response ? response.status : 0;

				if (status === 401 || status === 403) {
					stop();
				} else if (status !== 200) {
					lastSent = '';
					failed();
				}
			})
			.catch(function () {
				/* scanning is best effort and never affects the page */
				lastSent = '';
				failed();
			});
	}

	function later() {
		w.setTimeout(report, SETTLE_MS);
	}

	if (d.readyState === 'complete') {
		later();
	} else {
		w.addEventListener('load', later);
	}

	w.addEventListener('pagehide', report);
})(window, document);
