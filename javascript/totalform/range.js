import NumberField  from "./number.js";

//-----------------------------------------------
// Total CMS Range Slider Field
//-----------------------------------------------
export default class RangeSlider extends NumberField {

    constructor(container, options) {
        super(container, options);

		this.rangeValue = this.container.querySelector('.range-value');
		this.updateRangeValue();
		this.setupListeners();
    }

	setupListeners() {
		this.input.addEventListener('change', this.updateRangeValue.bind(this));
		this.input.addEventListener('drag', this.updateRangeValue.bind(this));
		this.input.addEventListener('mousemove', this.updateRangeValue.bind(this));
	}

	updateRangeValue() {
		this.rangeValue.innerHTML = this.getValue();
	}

	schema() {
        return {
            "type"  : "number",
            "field" : "number"
        };
    }
}

