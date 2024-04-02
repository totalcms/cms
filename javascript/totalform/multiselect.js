import TotalField from './totalfield';

//-----------------------------------------------
// Total CMS MultiSelect Field
//-----------------------------------------------
export default class MultiSelectField extends TotalField {

    constructor(container, options) {
        super(...arguments);
		this.select = container.querySelector("select");
    }

    getValue() {
        const data = [];
        // We have to grab each selected option and put them into an array.
        const options = this.input.querySelectorAll("option");
        for (const option of options) {
            if (option.selected) data.push(option.value);
        }
        // return array of data
        return data;
    }

    setValue(value) {
        if (typeof value !== "object") {
            console.error(`Unable to set value for multiselect: ${this.input.id}`);
        }
        // Select Options
        const options = Array.from(this.input.getElementsByTagName("option"));
        for (const option of options) {
            if (value.indexOf(option.value)>=0) {
                option.selected = true;
            }
        }
        this.changed();
    }

    schema() {
        return {
            "type":"array",
            "fieldset":"multiselect"
        };
    }
}
