//-----------------------------------------------
// Numeric coercion for calc expressions — the JS half of a two-engine contract.
//-----------------------------------------------
// Calc expressions are evaluated twice: live in the browser (calc.js) and again
// on save (PHP CalcService). The two must agree on what a referenced field
// contributes, or the total on screen differs from the total that gets stored.
// The shared cases live in tests/fixtures/calc-number-coercion.json and are
// asserted by BOTH engines — see tests/js/calcParity.test.mjs.
//
// Three tiers, in order:
//   1. Already a number, or a string PHP's is_numeric() accepts → use it.
//   2. A display-formatted price ("$5,000.00", "100.000,50 €") → normalize.
//      Masked price inputs render these, and raw API posts / imports carry them.
//   3. Anything else → NaN, which callers turn into 0.
//
// Tier 3 is why the shape guard exists. The normalizer strips every character
// that isn't a digit or separator, so unguarded it would read a time field
// ("12:30" → 1230), a date ("2026-08-25" → 2026) or a phone ("555-1234" → 555)
// as a plausible-looking number. A visible 0 beats a wrong total that nobody
// notices. Mirrors PriceData::normalize() + CalcService's guard in PHP.

/** PHP is_numeric() for decimal strings: optional sign, digits, exponent. */
const NUMERIC = /^\s*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?\s*$/;

/** Digits and separators only, with at most a leading/trailing currency symbol. */
const FORMATTED = /^\s*\p{Sc}?\s*-?[\d.,]+\s*\p{Sc}?\s*$/u;

/**
 * A single separator type is ambiguous (thousands vs decimal). Resolve it:
 *  - appears more than once → thousands grouping (strip all)
 *  - appears once with exactly 3 trailing digits → thousands ("100,000")
 *  - otherwise → decimal point ("100,50" / "100.5")
 */
function resolveSingleSeparator(s, sep) {
	if (s.split(sep).length - 1 > 1) return s.split(sep).join('');

	const pos = s.indexOf(sep);
	const after = pos !== -1 ? s.length - pos - 1 : 0;
	if (after === 3) return s.split(sep).join('');

	return s.split(sep).join('.');
}

/**
 * Coerce a possibly-formatted price into a numeric string parseFloat() accepts.
 * Locale-free: separator roles are inferred from the string. Deliberately
 * permissive — only call it on values already known to look like a number.
 */
export function normalizePrice(value) {
	if (typeof value === 'number') return String(value);

	// Keep only digits, separators, and a sign.
	let s = String(value).replace(/[^0-9,.\-]/g, '');
	if (s === '' || s === '-') return '0';

	const hasComma = s.includes(',');
	const hasDot = s.includes('.');

	if (hasComma && hasDot) {
		// Both present → the RIGHTMOST separator is the decimal point.
		const decimal = s.lastIndexOf(',') > s.lastIndexOf('.') ? ',' : '.';
		const thousands = decimal === ',' ? '.' : ',';
		s = s.split(thousands).join('');
		s = s.split(decimal).join('.');
	} else if (hasComma) {
		s = resolveSingleSeparator(s, ',');
	} else if (hasDot) {
		s = resolveSingleSeparator(s, '.');
	}

	return s;
}

/**
 * The numeric contribution of a field value to a calc expression.
 * Returns NaN when the value is not a number the two engines agree on.
 */
export function toCalcNumber(value) {
	if (typeof value === 'number') return value;
	if (value === null || value === undefined) return NaN;

	const s = String(value);
	if (NUMERIC.test(s)) return parseFloat(s);
	if (FORMATTED.test(s)) return parseFloat(normalizePrice(s));

	return NaN;
}
