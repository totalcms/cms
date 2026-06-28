import FieldVisibility from '../../javascript/totalform/field-visibility.js';

//-----------------------------------------------
// evaluateCondition() is the pure heart of conditional field visibility. It
// handles scalar comparisons, numeric coercion, array-of-expected-values (any
// match), and array current values (checkbox/multiselect membership + empty).
//-----------------------------------------------

const evaluate = (current, expected, operator) =>
	Object.create(FieldVisibility.prototype).evaluateCondition(current, expected, operator);

describe('FieldVisibility.evaluateCondition', () => {
	test('scalar equality and inequality', () => {
		expect(evaluate('a', 'a', '==')).toBe(true);
		expect(evaluate('a', 'b', '==')).toBe(false);
		expect(evaluate('a', 'b', '!=')).toBe(true);
	});

	test('numeric comparisons coerce string values to numbers', () => {
		expect(evaluate('5', '3', '>')).toBe(true);
		expect(evaluate('5', '10', '<')).toBe(true);
		expect(evaluate('5', '5', '>=')).toBe(true);
		expect(evaluate('4', '5', '<=')).toBe(true);
	});

	test('in / not_in on scalar values', () => {
		expect(evaluate('a', 'a', 'in')).toBe(true);
		expect(evaluate('a', 'b', 'not_in')).toBe(true);
	});

	test('an array of expected values matches when any of them matches', () => {
		expect(evaluate('b', ['a', 'b', 'c'], '==')).toBe(true);
		expect(evaluate('z', ['a', 'b'], '==')).toBe(false);
	});

	test('array current value (checkbox/multiselect) uses membership', () => {
		expect(evaluate(['a', 'b'], 'a', '==')).toBe(true);
		expect(evaluate(['a', 'b'], 'c', '==')).toBe(false);
		expect(evaluate(['a', 'b'], 'a', '!=')).toBe(false);
		expect(evaluate(['a', 'b'], 'c', 'not_in')).toBe(true);
	});

	test('empty / not_empty on array current values', () => {
		expect(evaluate([], 'x', 'empty')).toBe(true);
		expect(evaluate(['a'], 'x', 'empty')).toBe(false);
		expect(evaluate(['a'], 'x', 'not_empty')).toBe(true);
	});
});
