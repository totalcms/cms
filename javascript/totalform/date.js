import TotalField from './totalfield';

//-----------------------------------------------
// Total CMS Date Field
//-----------------------------------------------
export default class DateField extends TotalField {

	getValue() {
		if (this.input.value) {
			if (this.type === 'date') {
				// Convert to ISO format
				return new Date(this.input.value).toISOString().split('T')[0];
			}
			// Convert to ISO format and remove milliseconds
			return new Date(this.input.value).toISOString().replace(/\.\d{3}/, '');
		}
		return "";
	}

	setValue(value = "") {
		if (value.length > 0) {
			// Convert to ISO format and remove milliseconds
			value = new Date(value||this.value).toISOString().slice(0, -5);
			if (this.type === 'date') {
				value = value.split('T')[0];
			}
		}
		this.input.value = value;
		this.changed();
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
