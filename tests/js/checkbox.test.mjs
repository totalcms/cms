import CheckboxField from '../../javascript/totalform/checkbox.js';

//-----------------------------------------------
// CheckboxField is a boolean field — getValue is input.checked, and setValue
// accepts the various truthy encodings (true / "true" / 1).
//-----------------------------------------------

function checkbox(checked = false) {
	const input = document.createElement('input');
	input.type = 'checkbox';
	input.checked = checked;
	const field = Object.create(CheckboxField.prototype);
	field.input = input;
	field.changed = () => {};
	return field;
}

describe('CheckboxField', () => {
	test('getValue returns the checked state', () => {
		expect(checkbox(true).getValue()).toBe(true);
		expect(checkbox(false).getValue()).toBe(false);
	});

	test('setValue accepts true, "true", and 1 as checked', () => {
		const a = checkbox(false); a.setValue('true'); expect(a.getValue()).toBe(true);
		const b = checkbox(false); b.setValue(1); expect(b.getValue()).toBe(true);
		const c = checkbox(false); c.setValue(true); expect(c.getValue()).toBe(true);
	});

	test('setValue treats anything else as unchecked', () => {
		const a = checkbox(true); a.setValue('false'); expect(a.getValue()).toBe(false);
		const b = checkbox(true); b.setValue(0); expect(b.getValue()).toBe(false);
	});

	test('clearValue unchecks', () => {
		const field = checkbox(true);
		field.clearValue();
		expect(field.getValue()).toBe(false);
	});
});
