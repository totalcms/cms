import TotalField from './totalfield';

//-----------------------------------------------
// Total CMS JSON Field
//-----------------------------------------------
export default class JSONField extends TotalField {

    setValue(value) {
        if (typeof value === 'object') {
            value = JSON.stringify(value);
        }
        this.input.innerHTML = value;
        this.changed();
    }

    getValue() {
        let value = this.input.value;
        // trim trailing commas for users from JSON string.
        value = value.replaceAll("\n", "")
            .replaceAll(/,\s*\}/g, "}")
            .replaceAll(/,\s*\]/g, "]");

        return value.length > 0 ? JSON.parse(value) : "";
    }

    validate() {
        try {
            this.getValue();
            return true;
        } catch (e) {
            this.error("Invalid JSON format.");
            return false;
        }
	}
}
