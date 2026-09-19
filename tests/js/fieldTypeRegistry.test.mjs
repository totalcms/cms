import TotalForm from '../../javascript/totalform/totalform.js';
import TotalField from '../../javascript/totalform/totalfield.js';

//-----------------------------------------------
// The JavaScript half of an extension field type. addFieldType() on the PHP
// side renders the field; registerFieldType() here is how the admin bundle
// learns which class to build for its data-type, so the field takes part in
// dirty tracking and saving like a core field instead of falling back to a
// bare TotalField with a console warning.
//-----------------------------------------------

function fieldElement(type) {
	document.body.innerHTML = '';
	const container = document.createElement('div');
	container.className = 'form-field';
	container.dataset.type = type;
	container.innerHTML = '<input name="x" value="Hello">';
	document.body.appendChild(container);
	return container;
}

// generateFieldObject() only needs `this` as the settings.form reference.
const factory = (el) => TotalForm.prototype.generateFieldObject.call(Object.create(TotalForm.prototype), el);

class ShoutField extends TotalField {
	getValue() {
		return this.input.value.toUpperCase();
	}
}

describe('TotalForm.registerFieldType', () => {
	beforeEach(() => {
		for (const key of Object.keys(TotalForm.fieldTypes)) delete TotalForm.fieldTypes[key];
		TotalForm.registerBuiltInFieldTypes({ text: TotalField });
		vi.restoreAllMocks();
	});

	test('the factory builds a registered class for its type', () => {
		TotalForm.registerFieldType('shout', ShoutField);

		const field = factory(fieldElement('shout'));

		expect(field).toBeInstanceOf(ShoutField);
		expect(field.getValue()).toBe('HELLO');
	});

	test('an unregistered type still falls back to TotalField with a warning', () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

		const field = factory(fieldElement('mystery'));

		expect(field).toBeInstanceOf(TotalField);
		expect(field).not.toBeInstanceOf(ShoutField);
		expect(warn).toHaveBeenCalledWith('Unknown field', expect.anything());
	});

	test('a core type name is never overridden: the built-in class wins', () => {
		TotalForm.registerFieldType('text', ShoutField);

		const field = factory(fieldElement('text'));

		expect(field).not.toBeInstanceOf(ShoutField);
		expect(field.getValue()).toBe('Hello');
	});

	test('registering the same type twice warns and keeps the later class', () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
		class Later extends TotalField {}

		TotalForm.registerFieldType('shout', ShoutField);
		TotalForm.registerFieldType('shout', Later);

		expect(warn).toHaveBeenCalledTimes(1);
		expect(factory(fieldElement('shout'))).toBeInstanceOf(Later);
	});

	test('a bad registration is a TypeError, not a later "not a constructor" deep in a save', () => {
		expect(() => TotalForm.registerFieldType('', ShoutField)).toThrow(TypeError);
		expect(() => TotalForm.registerFieldType('shout', 'ShoutField')).toThrow(TypeError);
	});
});
