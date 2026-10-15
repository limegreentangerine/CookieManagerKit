import { describe, expect, it } from 'vitest';
import { resolve } from 'node:path';
import { readdirSync, readFileSync } from 'node:fs';

const dir = resolve(__dirname, '../../js');

/** Anything that turns a string into markup or code. Comments are stripped first so documentation can mention them. */
const BANNED = [
	/\binnerHTML\b/,
	/\bouterHTML\b/,
	/insertAdjacentHTML/,
	/document\s*\.\s*write/,
	/\beval\s*\(/,
	/new\s+Function\b/,
	/\bsetTimeout\s*\(\s*['"`]/,
	/\bsetInterval\s*\(\s*['"`]/,
	/\.srcdoc\b/,
	/createContextualFragment/,
	/\.insertAdjacentElement\s*\(\s*[^,]+,\s*['"`]</,
];

const strip = (source) => source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');

describe('browser code never turns strings into markup or code', () => {
	const files = readdirSync(dir).filter((name) => name.endsWith('.js'));

	it('finds the package scripts', () => {
		expect(files).toContain('cmk-loader.js');
		expect(files).toContain('cmk-runtime.js');
	});

	files.forEach((file) => {
		it(file, () => {
			const source = strip(readFileSync(resolve(dir, file), 'utf8'));

			BANNED.forEach((pattern) => {
				expect(source, String(pattern)).not.toMatch(pattern);
			});
		});
	});

	it('the loader can be inlined: it never contains a closing script tag', () => {
		const loader = readFileSync(resolve(dir, 'cmk-loader.js'), 'utf8').toLowerCase();
		expect(loader).not.toContain('</script');
		expect(loader).not.toContain('<!--');
	});
});
