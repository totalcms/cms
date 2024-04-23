import TotalField from './totalfield';

//-----------------------------------------------
// Total CMS Date Field
//-----------------------------------------------
export default class DateField extends TotalField {

	getValue() {
		if (this.input.value) {
			return new Date(this.input.value).toISOString();
		}
		return "";
	}

	setValue(value) {
		value = new Date(value||this.value).toISOString();
		if (this.type === 'date') {
			value = value.split('T')[0];
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
            "type"  : "date",
            "field" : "date"
        };
    }
}
