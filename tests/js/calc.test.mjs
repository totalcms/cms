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

//-----------------------------------------------
// Masked fields (price) render a locale-formatted string in input.value —
// "5,000.00" — which parseFloat truncates at the group separator (→ 5). Calc
// must read the field's typed value, never the raw DOM value.
// Regression: a deck column of 5,000 + 6,000 totalled 11.
//-----------------------------------------------

/** A `.form-field` wrapper whose TotalField.getValue() returns the typed number. */
function priceCell(name, display, typed) {
	const wrap = document.createElement('div');
	wrap.className = 'form-field';
	wrap.dataset.type = 'price';
	wrap.innerHTML = `<input type="text" name="${name}" value="${display}">`;
	wrap.totalfield = { input: wrap.querySelector('input'), getValue: () => typed };
	return wrap;
}

describe('Calc with display-formatted (masked) field values', () => {
	test('aggregates a deck column by typed value, not the formatted DOM value', () => {
		const form = document.createElement('form');
		const deck = document.createElement('div');
		deck.className = 'form-field';
		deck.dataset.type = 'deckTable';
		deck.innerHTML = '<input type="hidden" name="ausgabe">';
		form.append(deck);

		[['5,000.00', 5000], ['6,000.00', 6000]].forEach(([display, typed]) => {
			const row = document.createElement('div');
			row.className = 'deck-table-row';
			row.append(priceCell('nettobetrag', display, typed));
			deck.append(row);
		});

		const c = Object.create(Calc.prototype);
		c.expression = 'sum(${ausgabe.nettobetrag})';
		c.field = { settings: {}, isInDeck: false, form: { form, generateData: () => ({}) } };

		expect(c.collectDeckFieldValues('ausgabe', 'nettobetrag')).toEqual([5000, 6000]);
		expect(c.evaluate()).toBe(11000);
	});

	test('reads a sibling field inside a deck item by typed value', () => {
		const item = document.createElement('div');
		item.className = 'deck-item';
		item.append(priceCell('price', '5,000.00', 5000));

		const qty = document.createElement('div');
		qty.className = 'form-field';
		qty.innerHTML = '<input type="number" name="qty" value="2">';
		qty.totalfield = { input: qty.querySelector('input'), getValue: () => '2' };
		item.append(qty);

		const c = Object.create(Calc.prototype);
		c.expression = '${qty} * ${price}';
		c.field = { settings: {}, isInDeck: true, deckItem: item, form: null };

		expect(c.evaluate()).toBe(10000);
	});
});
