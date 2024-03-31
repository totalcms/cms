//-----------------------------------------------
// Total CMS Generic Field
//-----------------------------------------------
export default class TotalField {

    constructor(container, options) {
		this.container = container;
		this.input     = this.container.querySelector("input,textarea,select");

		container.totalfield = this;

		this.type = container.dataset.type;
		this.name = this.input.name;

        // Define option defaults
        const defaults = {
            form : null
        };
        this.options = Object.assign({}, defaults, options);
        this.form = this.options.form;

        // Delele the form from the options in case its used in JSON
        delete this.options.form;

        if (this.form) {
            this.api = this.form.api;
        }

		this.changeListener();
    }

	changeListener() {
		// the change event happens more than once so the ID field can be updated for every change
		this.input.addEventListener("change", () => this.changed());
		// the input event happens once since the point is to mark the form as unsaved ASAP
		this.input.addEventListener("input", () => this.changed(), {once: true});
	}

	isDroplet() {
		const droplets = ['image', 'file', 'gallery', 'depot'];
		return droplets.includes(this.type);
	}

	isFroala() {
		const froalaTypes = ['styledtext', 'svg'];
		return froalaTypes.includes(this.type);
	}

    getValue() {
        return this.input.value;
    }

    setValue(value) {
        this.input.value = value;
		this.changed();
    }

    changed() {
		this.container.classList.add("unsaved");
        this.container.dispatchEvent(new Event("field-change"));
    }

	saved() {
		this.container.classList.remove("unsaved");
		this.changeListener();
	}

    schema() {
        return {
            "type"  : this.type,
            "field" : "text"
        };
    }
}

// Radio Logic
// if (field.nodeName === "INPUT" && field.type === "radio" ) {
//     if (field.checked) {
//         return this.data[key] = field.value;
//     }
// }

// Checkboxes are a special case. We have to grab each checked values and put them into an array.
// else if (field.nodeName === "INPUT" && field.type === "checkbox") {
//     if (field.checked){
//         if (!this.data[key]){
//             this.data[key] = [];
//         }
//         return this.data[key].push(field.value);
//     }
// }
