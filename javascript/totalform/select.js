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

		if (window.navigator.userAgent.indexOf("MSIE") > 0 || window.navigator.userAgent.indexOf("Edge") > 0) {
			// IE Hack - select does not trigger input events. https://connect.microsoft.com/IE/feedback/details/1816207
			this.input.addEventListener("click", e => { this.changed() }, {once: true});
		}
    }

    setValue(value) {
        this.input.value = value;
        // Select Options
        const options = Array.from(this.input.getElementsByTagName("option"));
        for (const option of options) {
			option.selected = (option.value.trim() === value.trim());
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
