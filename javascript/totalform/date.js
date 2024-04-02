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

	setValue() {
		let value = new Date(this.value).toISOString();
		if (this.type === 'date') {
			value = value.split('T')[0];
		}
		return this.input.value = value;
	}

    schema() {
        return {
            "type"  : "date",
            "field" : "date"
        };
    }
}
