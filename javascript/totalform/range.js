import NumberField  from "./number.js";

//-----------------------------------------------
// Total CMS Range Slider Field
//-----------------------------------------------
export default class RangeSlider extends NumberField {

    constructor(container, settings) {
        super(container, settings);

		this.rangeValue = this.container.querySelector('.range-value');
		this.updateRangeValue();
		this.setupListeners();
    }

	setupListeners() {
		this.input.addEventListener('change', this.updateRangeValue.bind(this));
		this.input.addEventListener('drag', this.updateRangeValue.bind(this));
		this.input.addEventListener('mousemove', this.updateRangeValue.bind(this));
		this.input.addEventListener('touchmove', this.updateRangeValue.bind(this), { passive: true });
	}

	watch(callback) {
		if (typeof callback === 'function') {
			this.input.addEventListener('mousemove', () => callback());
			this.input.addEventListener('touchmove', () => callback(), { passive: true });
		}
	}

	updateRangeValue() {
		this.rangeValue.innerHTML = this.getValue();
	}

	setValue(value) {
        this.input.value = value;
		this.updateRangeValue();
		this.changed();
    }

	schema() {
        return {
            "type"  : "number",
            "field" : "number"
        };
    }
}

