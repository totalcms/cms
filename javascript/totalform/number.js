import TotalField from './totalfield';

//-----------------------------------------------
// Total CMS Number Field
//-----------------------------------------------
export default class NumberField extends TotalField {

    getValue() {
        return parseFloat(this.input.value);
    }

    schema() {
        return {
            "type"  : "number",
            "field" : "number"
        };
    }
}
