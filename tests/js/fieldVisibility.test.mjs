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

//-----------------------------------------------
// updateSections() hides a formgrid divider or header while every field it
// introduces is hidden — FormGridBuilder lists them in data-section-fields.
//-----------------------------------------------

describe('FieldVisibility.updateSections', () => {
	const grid = (html) => {
		const el = document.createElement('div');
		el.innerHTML = html;
		return el;
	};
	const field = (area, cls = '') => `<div class="form-field ${cls}" style="--grid-area: ${area};"></div>`;
	const update = (container) => Object.create(FieldVisibility.prototype).updateSections(container);
	const hidden = (container, selector = 'hr') => container.querySelector(selector).classList.contains('section-hidden');

	test('hides a divider when all of its fields are hidden, and shows it again', () => {
		const container = grid(
			field('enabled') + '<hr data-section-fields="name language">' + field('name', 'field-hidden') + field('language', 'field-hidden'),
		);

		update(container);
		expect(hidden(container)).toBe(true);

		container.querySelector('[style*="language"]').classList.remove('field-hidden');
		update(container);
		expect(hidden(container)).toBe(false);
	});

	test('keeps a marker with no field list, and treats a missing field as hidden', () => {
		const plain = grid('<hr>' + field('name', 'field-hidden'));
		update(plain);
		expect(hidden(plain)).toBe(false);

		const missing = grid('<h3 data-section-fields="ghost"></h3>');
		update(missing);
		expect(hidden(missing, 'h3')).toBe(true);
	});

	test('a nested grid answers to its own fields, not a same-named field outside', () => {
		const container = grid(
			field('name') + '<div class="card"><hr data-section-fields="name">' + field('name', 'field-hidden') + '</div>',
		);

		update(container);
		expect(hidden(container)).toBe(true);
	});

	test('does not confuse a field whose name starts with another\'s', () => {
		const container = grid('<hr data-section-fields="name">' + field('namespace') + field('name', 'field-hidden'));

		update(container);
		expect(hidden(container)).toBe(true);
	});
});

//-----------------------------------------------
// updateGroups() does the same for fieldsets and accordions. They hold their
// own fields, so there is no list to consult.
//-----------------------------------------------

describe('FieldVisibility.updateGroups', () => {
	const build = (html) => {
		const el = document.createElement('div');
		el.innerHTML = html;
		return el;
	};
	const field = (cls = '') => `<div class="form-field ${cls}"></div>`;
	const update = (container) => Object.create(FieldVisibility.prototype).updateSections(container);
	const hidden = (container, selector) => container.querySelector(selector).classList.contains('section-hidden');

	test('hides a fieldset when all of its fields are hidden, and shows it again', () => {
		const container = build(`<fieldset class="form-grid-fieldset">${field('field-hidden')}${field('field-hidden')}</fieldset>`);

		update(container);
		expect(hidden(container, 'fieldset')).toBe(true);

		container.querySelector('.form-field').classList.remove('field-hidden');
		update(container);
		expect(hidden(container, 'fieldset')).toBe(false);
	});

	test('keeps a fieldset with a visible field, or with no fields at all', () => {
		const mixed = build(`<fieldset class="form-grid-fieldset">${field('field-hidden')}${field()}</fieldset>`);
		update(mixed);
		expect(hidden(mixed, 'fieldset')).toBe(false);

		const markupOnly = build('<fieldset class="form-grid-fieldset"><p>Just text</p></fieldset>');
		update(markupOnly);
		expect(hidden(markupOnly, 'fieldset')).toBe(false);
	});

	test('sub-fields of a hidden card do not keep a fieldset open', () => {
		const container = build(`<fieldset class="form-grid-fieldset"><div class="form-field field-hidden">${field()}${field()}</div></fieldset>`);

		update(container);
		expect(hidden(container, 'fieldset')).toBe(true);
	});

	test('hides an empty accordion panel, and the accordion once every panel is hidden', () => {
		const container = build(
			'<div class="formgrid-accordion">'
			+ `<details class="formgrid-panel" id="a">${field('field-hidden')}</details>`
			+ `<details class="formgrid-panel" id="b">${field()}</details>`
			+ '</div>',
		);

		update(container);
		expect(hidden(container, '#a')).toBe(true);
		expect(hidden(container, '#b')).toBe(false);
		expect(hidden(container, '.formgrid-accordion')).toBe(false);

		container.querySelector('#b .form-field').classList.add('field-hidden');
		update(container);
		expect(hidden(container, '#b')).toBe(true);
		expect(hidden(container, '.formgrid-accordion')).toBe(true);
	});
});
