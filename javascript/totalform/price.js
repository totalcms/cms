import NumberField from './number';

//-----------------------------------------------
// Total CMS Price Field
// Extends NumberField with price-specific formatting and step 0.01
//-----------------------------------------------
export default class PriceField extends NumberField {

    init() {
        super.init();
        
        // Ensure step is always 0.01 for price fields
        if (this.input) {
            this.input.step = 0.01;
        }
    }

    getValue() {
        // Return as float with exactly 2 decimal places
        const value = parseFloat(this.input.value);
        if (isNaN(value)) {
            return 0.00;
        }
        // Round to 2 decimal places and return as float
        return parseFloat((Math.round(value * 100) / 100).toFixed(2));
    }

    setValue(value) {
        // Ensure value is formatted to 2 decimal places
        const numValue = parseFloat(value);
        if (!isNaN(numValue)) {
            this.input.value = numValue.toFixed(2);
        } else {
            this.input.value = '';
        }
        this.changed();
    }

    schema() {
        return {
            "type": "number",
            "field": "price"
        };
    }
}