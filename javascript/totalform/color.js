import TotalField from './totalfield';

//-----------------------------------------------
// Total CMS Color Field
//
// A native <input type="color"> always holds a colour (#000000 by default),
// so an optional colour had no way to be empty. With the `clearable` setting
// the container carries a `color-empty` mark: while it is on, getValue()
// sends '' instead of a hex and CSS shows a checkerboard in place of the
// swatch; picking a colour lifts it. Off by default — a
// field that never asked for it behaves exactly as before.
//-----------------------------------------------
export default class ColorField extends TotalField {

	constructor(container, settings) {
		super(container, settings);

		if (this.clearable) {
			this.container.querySelector('.color-clear')?.addEventListener('click', () => this.clearValue());
			this.input.addEventListener('input', () => this.markEmpty(false));
		}
	}

	get clearable() {
		return this.settings?.clearable === true || this.settings?.clearable === 'true';
	}

	get isEmpty() {
		return this.clearable && this.container.classList.contains('color-empty');
	}

	markEmpty(empty) {
		this.container.classList.toggle('color-empty', empty);
	}

	getValue() {
		if (this.isEmpty) return '';
		return {
			"hex" : this.input.value,
		}
	}

	setValue(value) {
		const hex = (value && typeof value === 'object') ? (value.hex ?? '') : (value ?? '');
		if (this.clearable && hex === '') {
			this.markEmpty(true);
			this.changed();
			return;
		}
		this.markEmpty(false);
		super.setValue(hex);
	}

	clearValue() {
		if (this.clearable) {
			this.setValue('');
			return;
		}
		super.clearValue();
	}

    schema() {
        return {
            "type"  : "color",
            "field" : "color"
        };
    }
}
