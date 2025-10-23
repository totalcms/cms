import TotalField from './totalfield';

//-----------------------------------------------
// Total CMS MultiCheckbox Field
//-----------------------------------------------
export default class MultiCheckboxField extends TotalField {

    constructor(container, options) {
        super(...arguments);
    }

	validate() {
		if (!this.isVisible()) return true;

		// Check if field is required
		const isRequired = this.container.dataset.required === 'true';

		if (!isRequired) {
			return true; // Not required, always valid
		}

		// Get all checkboxes in this field
		const checkboxes = this.container.querySelectorAll('input[type="checkbox"]');
		const isAnyChecked = Array.from(checkboxes).some(cb => cb.checked);

		if (isAnyChecked) {
			// Clear any previous custom validity
			this.input.setCustomValidity('');
			return true;
		}

		// None are checked but field is required
		const message = 'Please select at least one option.';
		this.input.setCustomValidity(message);
		this.input.reportValidity();
		this.error(message);
		return false;
	}

    getValue() {
        const data = [];
        // We have to grab each selected option and put them into an array.
        const options = this.container.querySelectorAll("input[type=checkbox]");
        for (const option of options) {
            if (option.checked) data.push(option.value);
        }
        // return array of data
        return data;
    }

    setValue(value) {
        if (typeof value !== "object") {
            console.error(`Unable to set value for multicheckbox: ${this.form.id}`);
        }
        // Select Options
        const options = Array.from(this.container.querySelectorAll("input[type=checkbox]"));
        for (const option of options) {
			option.checked = (value.indexOf(option.value)>=0);
        }
        this.changed();
    }

	clearValue() {
		this.setValue([]);
	}

    schema() {
        return {
            "type"     : "array",
            "fieldset" : "multicheckbox"
        };
    }
}
