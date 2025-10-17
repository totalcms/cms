import TotalField from './totalfield';

//-----------------------------------------------
// Total CMS MultiCheckbox Field
//-----------------------------------------------
export default class MultiCheckboxField extends TotalField {

    constructor(container, options) {
        super(...arguments);
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
