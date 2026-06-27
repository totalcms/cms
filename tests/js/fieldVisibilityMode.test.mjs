import TotalField from '../../javascript/totalform/totalfield.js';
import FieldVisibility from '../../javascript/totalform/field-visibility.js';

function makeField({ value = 'keepme' } = {}) {
	document.body.innerHTML = '';
	const container = document.createElement('div');
	container.className = 'form-field field-visible';
	container.dataset.type = 'text';
	container.innerHTML = `<input name="title" value="${value}">`;
	document.body.appendChild(container);
	return new TotalField(container, {});
}

// Minimal field stub matching what FieldVisibility uses.
function stubField(property, container, { mode = 'hide', value = '' } = {}) {
	return {
		property,
		container,
		_value: value,
		getValue() { return this._value; },
		isVisible() { return !container.classList.contains('field-hidden'); },
		show() { container.classList.remove('field-hidden'); container.classList.add('field-visible'); },
		hide() { container.classList.add('field-hidden'); container.classList.remove('field-visible'); },
		disable() { container.classList.add('field-disabled'); container.classList.remove('field-hidden'); },
		enable() { container.classList.remove('field-disabled'); },
	};
}

describe('FieldVisibility mode', () => {
	test('setVisibility uses disable/enable for disable-mode fields', () => {
		const fv = new FieldVisibility(document.createElement('form'), []);
		const c = document.createElement('div');
		const field = stubField('dependent', c);

		fv.setVisibility(field, false, 'disable');
		expect(c.classList.contains('field-disabled')).toBe(true);
		expect(c.classList.contains('field-hidden')).toBe(false);

		fv.setVisibility(field, true, 'disable');
		expect(c.classList.contains('field-disabled')).toBe(false);
	});

	test('setVisibility still hides for hide-mode fields', () => {
		const fv = new FieldVisibility(document.createElement('form'), []);
		const c = document.createElement('div');
		const field = stubField('dependent', c);

		fv.setVisibility(field, false, 'hide');
		expect(c.classList.contains('field-hidden')).toBe(true);
	});
});

describe('TotalField disable/enable', () => {
	test('disable() greys + locks the field but keeps it visible and valued', () => {
		const f = makeField();
		f.disable();
		expect(f.container.classList.contains('field-disabled')).toBe(true);
		expect(f.container.classList.contains('field-hidden')).toBe(false);
		expect(f.isVisible()).toBe(true);
		expect(f.input.disabled).toBe(true);
		expect(f.getValue()).toBe('keepme');
	});

	test('enable() restores interactivity', () => {
		const f = makeField();
		f.disable();
		f.enable();
		expect(f.container.classList.contains('field-disabled')).toBe(false);
		expect(f.input.disabled).toBe(false);
	});
});

// ---------------------------------------------------------------------------
// SimpleForm field wrapper — disable/enable required save-restore
// Tests the fix to simpleform.js createFieldWrapper().disable()/enable():
// a required field that gets disabled must have `required` restored on enable.
// The wrapper object is replicated inline to avoid pulling in the full
// SimpleForm → TotalCMS import chain (same strategy used by totalForm.test.mjs).
// ---------------------------------------------------------------------------

function makeSimpleWrapper({ required = false } = {}) {
	document.body.innerHTML = '';
	const container = document.createElement('div');
	container.className = 'form-field field-visible';
	const input = document.createElement('input');
	input.name = 'title';
	input.required = required;
	container.appendChild(input);
	document.body.appendChild(container);

	// Mirror createFieldWrapper() return value from simpleform.js exactly.
	return {
		container,
		property: input.name,
		input,
		getValue() { return input.value; },
		show() {
			container.style.display = '';
			container.classList.remove('field-hidden');
			container.classList.add('field-visible');
			if (input && input.hasAttribute('data-original-required')) {
				input.required = true;
			}
		},
		hide() {
			container.style.display = 'none';
			container.classList.remove('field-visible');
			container.classList.add('field-hidden');
			if (input && input.required) {
				input.setAttribute('data-original-required', 'true');
				input.required = false;
			}
			if (input) { input.setCustomValidity(''); }
			container.classList.remove('error');
		},
		disable() {
			container.classList.remove('field-hidden');
			container.classList.remove('error');
			container.classList.add('field-disabled');
			container.querySelectorAll('input, select, textarea, button').forEach(el => {
				if (el.required) {
					el.setAttribute('data-original-required', 'true');
					el.required = false;
				}
				el.disabled = true;
				el.setAttribute('aria-disabled', 'true');
			});
		},
		enable() {
			container.classList.remove('field-disabled');
			container.querySelectorAll('input, select, textarea, button').forEach(el => {
				el.disabled = false;
				el.removeAttribute('aria-disabled');
				if (el.hasAttribute('data-original-required')) {
					el.required = true;
					el.removeAttribute('data-original-required');
				}
			});
		},
		isVisible() { return !container.classList.contains('field-hidden'); },
	};
}

describe('SimpleForm field wrapper disable/enable required save-restore', () => {
	test('disable() clears required and enable() restores it', () => {
		const w = makeSimpleWrapper({ required: true });
		expect(w.input.required).toBe(true);
		w.disable();
		expect(w.input.required).toBe(false);
		expect(w.input.getAttribute('data-original-required')).toBe('true');
		w.enable();
		expect(w.input.required).toBe(true);
		expect(w.input.hasAttribute('data-original-required')).toBe(false);
	});

	test('disable() does not set data-original-required for non-required inputs', () => {
		const w = makeSimpleWrapper({ required: false });
		w.disable();
		expect(w.input.hasAttribute('data-original-required')).toBe(false);
		expect(w.input.required).toBe(false);
	});

	test('enable() after disable() of a non-required field leaves required false', () => {
		const w = makeSimpleWrapper({ required: false });
		w.disable();
		w.enable();
		expect(w.input.required).toBe(false);
	});
});

describe('FieldVisibility deck-table scoping', () => {
	function deckTableDom() {
		document.body.innerHTML = '';
		const form = document.createElement('form');
		const row = document.createElement('div');
		row.className = 'deck-table-row';

		const watchedC = document.createElement('div');
		watchedC.className = 'form-field';
		watchedC.setAttribute('style', '--grid-area: plan');

		const depC = document.createElement('div');
		depC.className = 'form-field';
		depC.setAttribute('style', '--grid-area: extra');
		depC.dataset.settings = JSON.stringify({ visibility: { watch: 'plan', value: 'premium', operator: '==' } });

		row.append(watchedC, depC);
		form.append(row);
		document.body.append(form);
		return { form, row, watchedC, depC };
	}

	test('a deck-table-row field is skipped by a top-level (non-row) scope', () => {
		const { form, watchedC, depC } = deckTableDom();
		const watched = stubField('plan', watchedC, { value: 'free' });
		const dep = stubField('extra', depC);
		dep.hide = vi.fn();
		new FieldVisibility(form, [watched, dep]).initializeScope(form, [watched, dep]);
		expect(dep.hide).not.toHaveBeenCalled();
	});

	test('a deck-table-row field IS processed when scoped to its own row', () => {
		const { form, row, watchedC, depC } = deckTableDom();
		const watched = stubField('plan', watchedC, { value: 'free' });
		const dep = stubField('extra', depC);
		dep.hide = vi.fn();
		new FieldVisibility(form, [watched, dep]).initializeScope(row, [watched, dep]);
		// condition plan('free') == 'premium' is false → dependent is hidden.
		expect(dep.hide).toHaveBeenCalled();
	});
});
