import TotalField from './totalfield';

//-----------------------------------------------
// Total CMS Radio Field
// Radio buttons with same name form a group - only one can be selected (like select)
//-----------------------------------------------
export default class RadioField extends TotalField {

    constructor(container, options) {
        super(container, options);

        // Get all radio inputs in this field
        this.radioInputs = this.container.querySelectorAll('input[type="radio"]');

        // Keep first radio as main input for compatibility
        this.input = this.radioInputs[0];
    }

    changeListener() {
        // Use event delegation - listen on container for radio change events
        // Radio buttons only fire 'change' events, not 'input' events
        this.container.addEventListener("change", (e) => {
            if (e.target.type === "radio") {
                this.changed();
            }
        });
    }

    getValue() {
        // Find the checked radio button and return its value
        // Only one can be checked in a radio group
        for (const radioInput of this.radioInputs) {
            if (radioInput.checked) {
                return radioInput.value;
            }
        }
        // If no radio is checked, return empty string
        return '';
    }

    setValue(value) {
        // Find and check the radio button with the matching value
        // HTML will automatically uncheck others in the same group
        for (const radioInput of this.radioInputs) {
			if (radioInput.value === value) {
                radioInput.checked = true;
				break;
            }
        }
        this.changed();
    }

    clearValue() {
        // Uncheck all radio buttons in the group
        this.radioInputs.forEach(radioInput => {
            radioInput.checked = false;
        });
        this.changed();
    }

    validate() {
        // For required radio groups, check if any radio is selected
        if (this.input.required && this.getValue() === '') {
			this.input.setCustomValidity("Please select an option.");
			this.input.reportValidity();
			this.error(this.input.validationMessage);
			return false;
        }

        // Clear any previous validation messages
        this.input.setCustomValidity("");
        return true;
    }

    schema() {
        return {
            "type"  : "string",
            "field" : "radio"
        };
    }
}