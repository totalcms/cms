import TotalField from './totalfield';

//-----------------------------------------------
// Total CMS Textarea Field
//-----------------------------------------------
export default class Textarea extends TotalField {

    setValue(value) {
        this.input.innerHTML = value;
        this.changed();
    }

}
