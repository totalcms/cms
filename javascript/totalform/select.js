import TotalField from './totalfield';

//-----------------------------------------------
// Total CMS Select Field
//-----------------------------------------------
export default class SelectField extends TotalField {

    constructor(container, options) {
        super(...arguments);

		this.input.addEventListener("change", e => {
			this.input.querySelector("[disabled]")?.removeAttribute("selected");
		}, {once: true});
    }

    setValue(value) {
        this.input.value = value;
        // Select Options
        const options = Array.from(this.input.getElementsByTagName("option"));
        for (const option of options) {
            if (option.value.trim() === value.trim()) {
                option.selected = true;
                break;
            }
        }
        this.changed();
    }

    schema() {
        return {
            "type":"string",
            "field":"select"
        };
    }
}
