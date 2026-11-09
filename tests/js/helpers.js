import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/** The package scripts are plain browser scripts, so run them in the jsdom window rather than import them. */
export function loadScript(name) {
	const source = readFileSync(resolve(__dirname, '../../js', name), 'utf8');
	(0, eval)(source);
}
