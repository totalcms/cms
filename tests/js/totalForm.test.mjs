import TotalForm from '../../javascript/totalform/totalform.js';

//-----------------------------------------------
// generateData() is the single point where the form collects values to save,
// and isUnsaved() is the dirty signal. Both iterate this.fields, so we exercise
// the real methods against stub fields (TotalForm's constructor takes 25+
// dependencies — not worth building here).
//-----------------------------------------------

function formWithFields(fields) {
	const form = Object.create(TotalForm.prototype);
	form.fields = fields;
	return form;
}

const field = ({ property, value, sub = false, unsaved = false }) => ({
	property,
	getValue: () => value,
	isSubField: () => sub,
	isUnsaved: () => unsaved,
});

describe('TotalForm.generateData', () => {
	test('collects each top-level field value keyed by its property', () => {
		const form = formWithFields([
			field({ property: 'title', value: 'Hello' }),
			field({ property: 'tags', value: ['a', 'b'] }),
		]);

		expect(form.generateData()).toEqual({ title: 'Hello', tags: ['a', 'b'] });
	});

	test('skips sub-fields — they belong to their composite parent, not the root', () => {
		const form = formWithFields([
			field({ property: 'mycard', value: { name: 'Star' } }),
			field({ property: 'name', value: 'leaked-from-card', sub: true }),
		]);

		// The card's own value is kept; its `name` sub-field does NOT leak to the root.
		expect(form.generateData()).toEqual({ mycard: { name: 'Star' } });
	});
});

describe('TotalForm.isUnsaved', () => {
	test('true when any field is unsaved', () => {
		const form = formWithFields([
			field({ property: 'a', unsaved: false }),
			field({ property: 'b', unsaved: true }),
		]);
		expect(form.isUnsaved()).toBe(true);
	});

	test('false when every field is saved', () => {
		const form = formWithFields([
			field({ property: 'a', unsaved: false }),
			field({ property: 'b', unsaved: false }),
		]);
		expect(form.isUnsaved()).toBe(false);
	});
});
