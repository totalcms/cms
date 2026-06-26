import MultiSelectField from '../../javascript/totalform/multiselect.js';
import SelectField from '../../javascript/totalform/select.js';
import ChecklistField from '../../javascript/totalform/checklist.js';

//-----------------------------------------------
// Select-backed fields read/write the <select>'s option selection. We drive the
// real getValue/setValue via Object.create + a built <select>, stubbing the
// dirty-tracking side-effects (changed/updateClearButton).
//-----------------------------------------------

function multiselect(options, selected = []) {
	const select = document.createElement('select');
	select.multiple = true;
	for (const o of options) {
		const opt = document.createElement('option');
		opt.value = o;
		opt.selected = selected.includes(o);
		select.appendChild(opt);
	}
	const field = Object.create(MultiSelectField.prototype);
	field.input = select;
	field.changed = () => {};
	return field;
}

describe('MultiSelectField', () => {
	test('getValue returns the selected option values', () => {
		expect(multiselect(['a', 'b', 'c'], ['a', 'c']).getValue()).toEqual(['a', 'c']);
	});

	test('getValue returns [] when nothing is selected', () => {
		expect(multiselect(['a', 'b']).getValue()).toEqual([]);
	});

	test('setValue selects exactly the given values and deselects the rest', () => {
		const field = multiselect(['a', 'b', 'c'], ['a', 'b', 'c']);
		field.setValue(['b']);
		expect(field.getValue()).toEqual(['b']);
	});

	test('clearValue deselects everything', () => {
		const field = multiselect(['a', 'b'], ['a', 'b']);
		field.clearValue();
		expect(field.getValue()).toEqual([]);
	});
});

function selectField(options) {
	const select = document.createElement('select');
	for (const o of options) {
		const opt = document.createElement('option');
		opt.value = o;
		select.appendChild(opt);
	}
	const field = Object.create(SelectField.prototype);
	field.input = select;
	field.changed = () => {};
	field.updateClearButton = () => {};
	return field;
}

describe('SelectField', () => {
	test('setValue sets the input value and selects the matching option', () => {
		const field = selectField(['red', 'green', 'blue']);
		field.setValue('green');

		expect(field.input.value).toBe('green');
		const selected = Array.from(field.input.options).filter((o) => o.selected).map((o) => o.value);
		expect(selected).toEqual(['green']);
	});
});

function multicheckbox(options, checked = []) {
	const container = document.createElement('div');
	for (const o of options) {
		const input = document.createElement('input');
		input.type = 'checkbox';
		input.value = o;
		input.checked = checked.includes(o);
		container.appendChild(input);
	}
	const field = Object.create(ChecklistField.prototype);
	field.container = container;
	field.changed = () => {};
	return field;
}

describe('ChecklistField', () => {
	test('getValue returns the checked values', () => {
		expect(multicheckbox(['a', 'b', 'c'], ['a', 'c']).getValue()).toEqual(['a', 'c']);
	});

	test('setValue checks exactly the given values', () => {
		const field = multicheckbox(['a', 'b', 'c'], ['a']);
		field.setValue(['b', 'c']);
		expect(field.getValue()).toEqual(['b', 'c']);
	});

	test('clearValue unchecks everything', () => {
		const field = multicheckbox(['a', 'b'], ['a', 'b']);
		field.clearValue();
		expect(field.getValue()).toEqual([]);
	});
});

