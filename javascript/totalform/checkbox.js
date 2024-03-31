import TotalField from './totalfield';

//-----------------------------------------------
// Total CMS Checkbox Field
//-----------------------------------------------
export default class Checkbox extends TotalField {

    setValue(value) {
        this.input.checked = (value === true||value === "true"||value === 1);
        this.changed();
    }

    getValue() {
        return this.input.checked;
    }

    schema() {
        return {
            "type":"boolean",
            "fieldset":"checkbox"
        };
    }
}
