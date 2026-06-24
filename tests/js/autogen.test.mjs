import Autogen from '../../javascript/totalform/autogen.js';

//-----------------------------------------------
// Autogen interpolates a pattern like "${title}-${oid-0000}" from form data plus
// special variables (uuid, timestamps, current date, padded oid). It reads only
// `this.field`, so we drive it with a stub field.
//-----------------------------------------------

function makeAutogen(pattern, { fields = {}, count = null } = {}) {
	const formEl = document.createElement('div');
	if (count !== null) formEl.setAttribute('data-collection-count', String(count));
	const field = {
		settings: { autogen: pattern },
		isInDeck: false,
		form: { form: formEl, generateData: () => fields },
	};
	return new Autogen(field);
}

describe('Autogen.getFieldNames', () => {
	test('returns referenced field names, excluding reserved + oid- tokens', () => {
		expect(makeAutogen('${title}-${oid-00000}').getFieldNames()).toEqual(['title']);
		expect(makeAutogen('${first}-${last}').getFieldNames()).toEqual(['first', 'last']);
	});

	test('returns nothing when the pattern is only reserved variables', () => {
		expect(makeAutogen('${currentyear}-${uuid}-${oid}').getFieldNames()).toEqual([]);
	});
});

describe('Autogen.generate', () => {
	test('interpolates referenced field values', () => {
		expect(makeAutogen('${name}', { fields: { name: 'Hello World' } }).generate()).toBe('Hello World');
		expect(makeAutogen('${a}-${b}', { fields: { a: 'x', b: 'y' } }).generate()).toBe('x-y');
	});

	test('coerces numbers and ignores non-string/number field values', () => {
		const out = makeAutogen('${title}-${count}-${obj}', {
			fields: { title: 'post', count: 5, obj: { nested: true } },
		}).generate();
		// obj is filtered out by collectFormData → interpolates to ''
		expect(out).toBe('post-5-');
	});

	test('pads an oid token with leading zeros from the collection count', () => {
		// count 6 → next oid 7 → padded to width 3
		expect(makeAutogen('${oid-000}', { count: 6 }).generate()).toBe('007');
	});

	test('resolves the current-year special variable', () => {
		const year = String(new Date().getFullYear());
		expect(makeAutogen('${currentyear}', { count: 0 }).generate()).toBe(year);
	});

	test('produces a v4-shaped uuid for the uuid special variable', () => {
		expect(makeAutogen('${uuid}').generate()).toMatch(
			/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
		);
	});
});
