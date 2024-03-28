//-----------------------------------------------
// Total CMS Checkbox Field
//-----------------------------------------------
class NumberField extends TotalField {

    getValue() {
        return parseFloat(this.input.value);
    }

    schema() {
        return {
            "type":"number",
            "fieldset":"number"
        };
    }

}
