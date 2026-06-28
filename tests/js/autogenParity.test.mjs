import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import Autogen from '../../javascript/totalform/autogen.js';

//-----------------------------------------------
// Cross-engine parity: the JS autogen engine must satisfy the shared feature
// set declared in tests/fixtures/autogen-feature-set.json. The PHP engine
// asserts the SAME file (tests/Domain/Object/Service/AutogenFeatureSetTest.php),
// so a token added to one engine but not the other fails this suite on the
// engine that's missing it.
//-----------------------------------------------

const here = dirname(fileURLToPath(import.meta.url));
const manifest = JSON.parse(
	readFileSync(join(here, '../fixtures/autogen-feature-set.json'), 'utf8'),
);

// Drive Autogen.generate() with a stub field: pattern via settings.autogen,
// field data via form.generateData(), and the oid count via the form element's
// data-collection-count attribute (next oid = count + 1).
function generate(pattern, fields, oid) {
	const formEl = document.createElement('div');
	formEl.setAttribute('data-collection-count', String(oid));
	const field = {
		settings: { autogen: pattern },
		isInDeck: false,
		form: { form: formEl, generateData: () => fields },
	};
	return new Autogen(field).generate();
}

describe('autogen JS engine matches the shared feature set', () => {
	for (const c of manifest.cases) {
		test(c.name, () => {
			const out = generate(c.pattern, c.fields, c.oid);
			if (Object.prototype.hasOwnProperty.call(c, 'equals')) {
				expect(out).toBe(c.equals);
			} else {
				expect(out).toMatch(new RegExp(c.matches));
			}
		});
	}
});
