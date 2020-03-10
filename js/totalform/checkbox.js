//-----------------------------------------------
// Total CMS Checkbox Field
//-----------------------------------------------
class Checkbox extends Fieldset {

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
