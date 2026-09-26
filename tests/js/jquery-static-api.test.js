// Every `$.<name>()` the plugin calls must exist on the jQuery it runs against.
//
// `$.trim` was removed in jQuery 4. Two files in `assets/js/` already carried a
// comment saying so, in so many words -- and #1462 shipped two fresh `$.trim`
// calls into `ffc-identity-search.js` anyway, on the shared-mailbox path where
// they are the first thing the barring touches. Under WordPress's bundled 3.7.1
// they work; under the jQuery 4 this suite binds they throw, so the whole
// inverted-barring path died at its first statement and nothing was red,
// because no test reached that branch until #1464 needed it.
//
// So the lesson was written down twice, in the right files, and recurred. That
// is the case `CLAUDE.md` describes for promoting a comment into a guard.
//
// THE REGISTER IS THE RUNTIME, NOT A LIST OF REMOVED NAMES. A list of what
// jQuery 4 dropped would be my memory of their changelog, frozen -- exactly the
// shape that goes stale in silence. Instead every name the sources CALL is
// looked up on the real bundled jQuery: a member that is gone fails whether or
// not anybody knew it was going, and a member that comes back stops failing
// without anybody editing this file.
//
// It reads `$.<name>(` -- a call, with the parenthesis. A comment MENTIONING a
// removed member (`\`$.trim\` was removed in jQuery 4`) carries no parenthesis
// and is not matched, which is what lets this scan skip comment-stripping
// altogether rather than hand-roll the quote-parity trap `CssSelectors` exists
// to avoid. A comment that writes the call out in full would be a false
// positive; rewording it is the fix, and the failure names the file.
import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync, globSync } from 'node:fs';
import { resolve } from 'node:path';

const DIR = resolve(process.cwd(), 'assets/js');

/** @returns {string[]} The non-minified sources, by directory listing. */
function sources() {
	return readdirSync(DIR)
		.filter((name) => name.endsWith('.js') && !name.endsWith('.min.js'))
		.sort();
}

// A SECOND TRAVERSAL, NOT A FLOOR (`CLAUDE.md`, the guards' self-check rule).
//
// `assertGreaterThan(0, …)` over a growing directory is a floor that loosens on
// its own: it was tight at 20 files and says nothing at 60. A recount by a
// different mechanism moves with the population, so adding a script keeps both
// sides equal and losing one to a broken glob fails here instead of reading as
// a clean scan.
function recount() {
	return globSync('assets/js/*.js')
		.filter((path) => !path.endsWith('.min.js'))
		.length;
}

describe('the jQuery statics the plugin calls', () => {
	it('is scanning the whole directory, by two independent readings', () => {
		expect(sources().length).toBe(recount());
	});

	it('names only members the bundled jQuery actually has', () => {
		const missing = [];

		for (const name of sources()) {
			const code = readFileSync(resolve(DIR, name), 'utf8');
			const calls = code.matchAll(/(?:\$|jQuery)\.([A-Za-z_$][\w$]*)\s*\(/g);

			for (const call of calls) {
				const member = call[1];

				if ('function' !== typeof window.$[member]) {
					missing.push(`${name}: $.${member}()`);
				}
			}
		}

		expect([...new Set(missing)].sort()).toEqual([]);
	});
});
