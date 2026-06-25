import ColorField from '../../javascript/totalform/color.js';

describe('ColorField', () => {
	test('getValue returns the hex wrapper object', () => {
		const field = Object.create(ColorField.prototype);
		field.input = Object.assign(document.createElement('input'), { value: '#ff0000' });
		expect(field.getValue()).toEqual({ hex: '#ff0000' });
	});
});
