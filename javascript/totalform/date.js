import TotalField from './totalfield';

//-----------------------------------------------
// Total CMS Date Field
//-----------------------------------------------
export default class DateField extends TotalField {

    schema() {
        return {
            "type"  : "date",
            "field" : "date"
        };
    }
}
