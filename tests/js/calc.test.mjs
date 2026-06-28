import Calc from '../../javascript/totalform/calc.js';

//-----------------------------------------------
// Calc evaluates number-field expressions. parse() is a hand-written safe math
// evaluator (no eval) — the most bug-prone piece — and evaluate() wires field/
// deck values into it and clamps to min/max.
//-----------------------------------------------

const parse = (expr) => Object.create(Calc.prototype).parse(expr);

describe('Calc.parse (safe math evaluator)', () => {
	test('respects operator precedence and parentheses', () => {
		expect(parse('2 + 3 * 4')).toBe(14);
		expect(parse('(2 + 3) * 4')).toBe(20);
		expect(parse('10 / 4')).toBe(2.5);
		expect(parse('10 % 3')).toBe(1);
		expect(parse('-5 + 3')).toBe(-2); // unary minus
	});

	test('supports the built-in functions', () => {
		expect(parse('round(3.7)')).toBe(4);
		expect(parse('floor(3.7)')).toBe(3);
		expect(parse('ceil(3.2)')).toBe(4);
		expect(parse('abs(-5)')).toBe(5);
		expect(parse('min(3, 1, 2)')).toBe(1);
		expect(parse('max(3, 1, 2)')).toBe(3);
		expect(parse('sum(1, 2, 3)')).toBe(6);
		expect(parse('avg(2, 4, 6)')).toBe(4);
		expect(parse('count(1, 2, 3)')).toBe(3);
	});
});

describe('Calc.getReferences', () => {
	test('splits plain field references from deck (deck.field) references', () => {
		const calc = new Calc({ settings: { calc: '${price} * ${qty} + sum(${items.total})' } });
		expect(calc.getReferences()).toEqual({
			fields: ['price', 'qty'],
			deckRefs: [{ deck: 'items', field: 'total' }],
		});
	});
});

describe('Calc.evaluate', () => {
	function calc(expression, { data = {}, deck = {}, settings = {} } = {}) {
		const c = Object.create(Calc.prototype);
		c.expression = expression;
		c.field = { settings, isInDeck: false, form: {} };
		c.collectFormData = () => data;
		c.collectDeckFieldValues = (deckProp, field) => deck[`${deckProp}.${field}`] ?? [];
		return c;
	}

	test('interpolates field values and computes the result', () => {
		expect(calc('${price} * ${qty}', { data: { price: '10', qty: '3' } }).evaluate()).toBe(30);
	});

	test('treats missing/non-numeric fields as 0', () => {
		expect(calc('${price} + ${missing}', { data: { price: '5' } }).evaluate()).toBe(5);
	});

	test('aggregates a deck reference into the function call', () => {
		expect(calc('sum(${items.total})', { deck: { 'items.total': [10, 20, 30] } }).evaluate()).toBe(60);
	});

	test('clamps the result to the min/max settings', () => {
		expect(calc('${x}', { data: { x: '200' }, settings: { max: 100 } }).evaluate()).toBe(100);
		expect(calc('${x}', { data: { x: '-5' }, settings: { min: 0 } }).evaluate()).toBe(0);
	});

	test('returns null when the expression cannot be parsed', () => {
		expect(calc('${x} +', { data: { x: '5' } }).evaluate()).toBeNull();
	});
});
