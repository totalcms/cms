import Dialog from "./dialog";
import Details from "./details";

//-----------------------------------------------
// Total CMS Properties
//-----------------------------------------------
export default class PropertyField {

    constructor(container) {
		this.container = container;
		this.container.totalfield = this;

		this.name   = this.container.querySelector('[name=property]').value;
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
			}
		});
	}

	setupAccordion() {
		// Close other details when one is opened
		const details = Array.from(this.dialog.dialog.querySelectorAll("details"));
		this.accordion = new Details(details);
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
		return properties;
	}
}
