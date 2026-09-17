import ColorField from '../../javascript/totalform/color.js';

// A native <input type="color"> always holds a value (#000000 by default), so
// an optional colour had no way to be empty. The `clearable` setting adds one:
// a clear button marks the field empty, getValue() sends '' instead of a hex,
// and picking a colour again un-marks it. Off by default — a field that never
// asked for it behaves exactly as before.
function mount(settings = {}, { empty = false, value = '#ff0000' } = {}) {
	document.body.innerHTML = '';
	const container = document.createElement('div');
	container.className = 'form-field color-field' + (settings.clearable ? ' color-clearable' : '') + (empty ? ' color-empty' : '');
	container.dataset.type = 'color';
	container.dataset.settings = JSON.stringify(settings);
	container.innerHTML = `<div class="form-group"><input type="color" name="accent" value="${value}">${settings.clearable ? '<button type="button" class="color-clear">×</button>' : ''}</div>`;
	document.body.appendChild(container);
	return new ColorField(container, {});
}

describe('ColorField', () => {
	test('getValue returns the hex wrapper object', () => {
		expect(mount().getValue()).toEqual({ hex: '#ff0000' });
	});

	test('a clearable field that is empty sends an empty string', () => {
		expect(mount({ clearable: true }, { empty: true }).getValue()).toBe('');
	});

	test('the clear button empties a clearable field and picking a colour fills it again', () => {
		const field = mount({ clearable: true });
		expect(field.getValue()).toEqual({ hex: '#ff0000' });

		field.container.querySelector('.color-clear').click();
		expect(field.getValue()).toBe('');
		expect(field.container.classList.contains('color-empty')).toBe(true);

		field.input.value = '#00ff00';
		field.input.dispatchEvent(new Event('input', { bubbles: true }));
		expect(field.getValue()).toEqual({ hex: '#00ff00' });
		expect(field.container.classList.contains('color-empty')).toBe(false);
	});

	test('setValue with an empty value empties a clearable field, and a hex fills it', () => {
		const field = mount({ clearable: true });
		field.setValue('');
		expect(field.getValue()).toBe('');
		field.setValue({ hex: '#0000ff' });
		expect(field.getValue()).toEqual({ hex: '#0000ff' });
		expect(field.input.value).toBe('#0000ff');
	});

	test('without the setting clearValue keeps sending a hex', () => {
		const field = mount();
		field.clearValue();
		expect(field.getValue()).toEqual({ hex: expect.stringMatching(/^#/) });
	});
});
