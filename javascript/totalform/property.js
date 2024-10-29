import Dialog from "./dialog";
import Details from "./details";

//-----------------------------------------------
// Total CMS Properties
//-----------------------------------------------
export default class PropertyField {

    constructor(container, fieldClass) {
		this.container = container;
		this.container.totalfield = this;
		this.fieldClass = fieldClass;

		this.dialog = this.setupDialog();
    }

	setupDialog() {
		return new Dialog(this.container.querySelector("dialog"), {
			open  : this.container.querySelector("button"),
			close : ".close",
			onOpen : () => {
				if (this.dialogOpened) return;
				this.dialogOpened = true;
				this.setupAccordion();
			},
			onClose : () => {
				this.dialogOpened = false;
				this.updateIcons();
			}
		});
	}

	setupAccordion() {
		// Close other details when one is opened
		const details = Array.from(this.dialog.dialog.querySelectorAll("details"));
		this.accordion = new Details(details);
	}

	updateIcons() {
		const fieldIcon = this.container.querySelector("[name=field]");
		const typeIcon  = this.container.querySelector("[name=type]");

		let newclass = `${this.fieldClass} ${fieldIcon.value}-field`;
		if (typeIcon) {
			newclass += ` ${typeIcon.value}-type`;
		}
		this.container.className = newclass;
	}

	getName(){
		return this.container.querySelector('[name=property]').value;
	}

	getValue() {
		const fields = this.container.getElementsByClassName("form-field");
		const properties = {};

		for (const field of fields) {
			let value = field.totalfield.getValue();
			if (value) {
				properties[field.totalfield.property] = value;
			}
		}
		if (properties.extra && typeof properties.extra === "object") {
			// Merge properties.extra into properties
			Object.assign(properties, properties.extra);
			delete properties.extra;
		}
		return properties;
	}
}
