import TotalField from './totalfield';

//-----------------------------------------------
// Total CMS Color Field
//-----------------------------------------------
export default class ColorField extends TotalField {

	getValue() {
		return {
			"hex" : this.input.value,
		}
	}

    schema() {
        return {
            "type"  : "color",
            "field" : "color"
        };
    }
}
