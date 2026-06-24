import TotalField from '../../javascript/totalform/totalfield.js';

//-----------------------------------------------
// TotalField is the base every field type inherits. These cover the dirty-state
// and validation lifecycle that the whole form-save flow depends on.
//-----------------------------------------------

function makeField({ type = 'text', name = 'title', value = '', attrs = '', classes = '' } = {}) {
	document.body.innerHTML = '';
	const container = document.createElement('div');
	container.className = ('form-field ' + classes).trim();
	container.dataset.type = type;
	container.innerHTML = `<input name="${name}" value="${value}" ${attrs}>`;
	document.body.appendChild(container);
	return new TotalField(container, {});
}

describe('TotalField.valuesEqual', () => {
	test('primitives compare strictly', () => {
		const f = makeField();
		expect(f.valuesEqual('a', 'a')).toBe(true);
		expect(f.valuesEqual('a', 'b')).toBe(false);
		expect(f.valuesEqual(0, 0)).toBe(true);
	});

	test('objects/arrays compare structurally', () => {
		const f = makeField();
		expect(f.valuesEqual({ x: 1, y: 2 }, { x: 1, y: 2 })).toBe(true);
		expect(f.valuesEqual({ x: 1 }, { x: 2 })).toBe(false);
		expect(f.valuesEqual(['a', 'b'], ['a', 'b'])).toBe(true);
	});

	test('null/undefined are not equal', () => {
		const f = makeField();
		expect(f.valuesEqual(null, undefined)).toBe(false);
		expect(f.valuesEqual(null, null)).toBe(true);
	});
});

describe('TotalField dirty state', () => {
	test('changed() marks unsaved only when the value actually changed', () => {
		const f = makeField({ value: 'one' });

		f.changed(); // input still 'one' (== storedValue from construction)
		expect(f.container.classList.contains('unsaved')).toBe(false);

		f.input.value = 'two';
		f.changed();
		expect(f.container.classList.contains('unsaved')).toBe(true);
	});

	test('changed() dispatches field-change for a top-level field (debounced)', () => {
		vi.useFakeTimers();
		const f = makeField({ value: 'one' });
		let fired = false;
		f.container.addEventListener('field-change', () => { fired = true; });

		f.input.value = 'changed';
		f.changed();

		// TotalDispatcher debounces by 300ms — nothing has fired synchronously yet.
		expect(fired).toBe(false);
		vi.advanceTimersByTime(300);
		expect(fired).toBe(true);
		vi.useRealTimers();
	});

	test('saved() clears the unsaved class', () => {
		const f = makeField();
		f.container.classList.add('unsaved');
		f.saved();
		expect(f.container.classList.contains('unsaved')).toBe(false);
	});

	test('isUnsaved() reflects the class but ignores hidden fields', () => {
		const f = makeField();
		expect(f.isUnsaved()).toBe(false);

		f.container.classList.add('unsaved');
		expect(f.isUnsaved()).toBe(true);

		f.container.classList.add('field-hidden'); // hidden fields never count as dirty
		expect(f.isUnsaved()).toBe(false);
	});
});

describe('TotalField.isSubField', () => {
	test('false for a top-level field', () => {
		expect(makeField().isSubField()).toBe(false);
	});

	test('true when nested inside another .form-field', () => {
		document.body.innerHTML = `
			<div class="form-field" data-type="image">
				<div class="form-field" data-type="text"><input name="alt"></div>
			</div>`;
		const child = new TotalField(document.querySelector('.form-field .form-field'), {});
		expect(child.isSubField()).toBe(true);
	});
});

describe('TotalField.validate', () => {
	test('passes for a valid value', () => {
		expect(makeField({ value: 'ok' }).validate()).toBe(true);
	});

	test('fails for a required empty input', () => {
		const f = makeField({ value: '', attrs: 'required' });
		expect(f.validate()).toBe(false);
	});

	test('hidden fields skip validation (always valid)', () => {
		const f = makeField({ value: '', attrs: 'required', classes: 'field-hidden' });
		expect(f.validate()).toBe(true);
	});
});
