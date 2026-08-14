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

	test('true inside a .cms-modal that is not itself a .form-field', () => {
		// The depot browser dialog sits at form level, so its fields have no
		// .form-field ancestor — but they are still not top-level fields.
		document.body.innerHTML = `
			<dialog class="cms-modal">
				<div class="form-field" data-type="text"><input name="alt"></div>
			</dialog>`;
		const child = new TotalField(document.querySelector('.form-field'), {});
		expect(child.isSubField()).toBe(true);
	});

	test('stays true when the parent .form-field has no TotalField instance', () => {
		// generateFieldObject() can return null or throw, leaving a .form-field
		// element with no .totalfield. Nesting is a DOM fact regardless — if this
		// regressed to false the child would be promoted into this.fields and its
		// value would leak into the root object on save.
		document.body.innerHTML = `
			<div class="form-field" data-type="card">
				<div class="form-field" data-type="text"><input name="street"></div>
			</div>`;
		const child = new TotalField(document.querySelector('.form-field .form-field'), {});
		expect(child.isSubField()).toBe(true);
		expect(child.getParent()).toBeNull();
	});
});

describe('TotalField.getParent / rootField', () => {
	test('getParent returns the enclosing field instance', () => {
		document.body.innerHTML = `
			<div class="form-field" data-type="card"><input type="hidden" name="address">
				<div class="card-fields">
					<div class="form-field" data-type="text"><input name="street"></div>
				</div>
			</div>`;
		const card = new TotalField(document.querySelector('.form-field'), {});
		const child = new TotalField(document.querySelector('.card-fields .form-field'), {});

		expect(child.getParent()).toBe(card);
		expect(card.getParent()).toBeNull();
	});

	test('rootField walks all the way up a card-in-card nest', () => {
		document.body.innerHTML = `
			<div class="form-field" data-type="card"><input type="hidden" name="outer">
				<div class="card-fields">
					<div class="form-field" data-type="card"><input type="hidden" name="inner">
						<div class="card-fields">
							<div class="form-field" data-type="text"><input name="street"></div>
						</div>
					</div>
				</div>
			</div>`;
		const outer = new TotalField(document.querySelector('.form-field'), {});
		const inner = new TotalField(document.querySelector('.card-fields .form-field'), {});
		const leaf = new TotalField(document.querySelector('.card-fields .card-fields .form-field'), {});

		expect(leaf.getParent()).toBe(inner);
		expect(leaf.rootField()).toBe(outer);
		expect(outer.rootField()).toBe(outer);
	});

	test('rootField falls back to the field itself when the chain breaks', () => {
		document.body.innerHTML = `
			<div class="form-field" data-type="card">
				<div class="form-field" data-type="text"><input name="street"></div>
			</div>`;
		const child = new TotalField(document.querySelector('.form-field .form-field'), {});
		expect(child.rootField()).toBe(child);
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

describe('TotalField visibility', () => {
	test('hide() marks the field hidden and show() reveals it', () => {
		const f = makeField();
		expect(f.isVisible()).toBe(true);

		f.hide();
		expect(f.isHidden()).toBe(true);
		expect(f.isVisible()).toBe(false);
		expect(f.container.style.display).toBe('none');
		expect(f.container.classList.contains('field-hidden')).toBe(true);

		f.show();
		expect(f.isVisible()).toBe(true);
		expect(f.container.classList.contains('field-hidden')).toBe(false);
		expect(f.container.classList.contains('field-visible')).toBe(true);
		expect(f.container.style.display).toBe('');
	});
});

describe('TotalField value', () => {
	test('setValue writes the input and marks the field unsaved on a real change', () => {
		const f = makeField({ value: 'one' });
		f.setValue('two');
		expect(f.input.value).toBe('two');
		expect(f.container.classList.contains('unsaved')).toBe(true);
	});

	test('clearValue empties the input', () => {
		const f = makeField({ value: 'something' });
		f.clearValue();
		expect(f.input.value).toBe('');
	});
});
