import TotalField from './totalfield';

//-----------------------------------------------
// Total CMS Date Field
//-----------------------------------------------
export default class DateField extends TotalField {

    constructor(container, settings) {
        super(container, settings);

		// Set the value and do not mark as changed
        this.setValue(this.input.getAttribute('value'), false);
    }

	getValue() {
		if (this.input.value) {
			return this.formatDate(this.input.value);
		}
		return "";
	}

	setValue(value = "", markAsChanged = true) {
		if (value) {
			value = this.formatDate(value);
		}
		this.input.value = value;
		if (markAsChanged) this.changed();
	}

	formatDate(date) {
		if (date) {
			// Convert to ISO format and remove milliseconds
			date = date.slice(0, 16);
			if (this.type === 'date') {
				date = date.split('T')[0];
			}
		}
		return date;
	}

	clearValue() {
		this.input.value = "";
		this.changed();
	}

    schema() {
        return {
            "type"  : this.type,
            "field" : "date"
        };
    }
}
