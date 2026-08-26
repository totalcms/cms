import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import Calc from '../../javascript/totalform/calc.js';

//-----------------------------------------------
// Cross-engine parity: the JS calc engine must coerce field values exactly as
// declared in tests/fixtures/calc-number-coercion.json. The PHP engine asserts
// the SAME file (tests/Unit/Domain/Object/Service/CalcCoercionParityTest.php),
// so a coercion changed in one engine but not the other fails this suite on the
// engine that's missing it.
//
// This matters because calc runs twice: live in the browser as the operator
// types, and again in PHP on save. If the engines disagree, the total on screen
// is not the total that gets stored.
//-----------------------------------------------

const here = dirname(fileURLToPath(import.meta.url));
const manifest = JSON.parse(
	readFileSync(join(here, '../fixtures/calc-number-coercion.json'), 'utf8'),
);

// Drive Calc.evaluate() with a stub field: the expression references a single
// key `v`, bound to the case's value via the form-data collector.
function evaluate(value) {
	const c = Object.create(Calc.prototype);
	c.expression = '${v}';
	c.field = { settings: {}, isInDeck: false, form: {} };
	c.collectFormData = () => ({ v: value });
	c.collectDeckFieldValues = () => [];
	return c.evaluate();
}

describe('calc JS engine matches the shared coercion feature set', () => {
	for (const c of manifest.cases) {
		test(c.name, () => {
			expect(evaluate(c.value)).toBe(c.equals);
		});
	}
});
