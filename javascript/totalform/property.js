import Dialog from "./dialog";
import Details from "./details";

//-----------------------------------------------
// Total CMS Properties
//-----------------------------------------------
export default class PropertyField {

    constructor(container) {
		this.container = container;
		this.name      = this.container.querySelector('[name=property]').value;
		this.fields    = Array.from(this.container.getElementsByClassName("form-field"));
		this.dialog    = this.setupDialog();
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
		const properties = {};
		this.fields.forEach(field => {
			let value = field.totalfield.getValue();
			if (value) {
				if (field.totalfield.property === "options" || field.totalfield.property === "settings") {
					// trim trailing commas for users from JSON string.
					value = value.replaceAll("\n", "")
						.replaceAll(/,\s*\}/g, "}")
						.replaceAll(/,\s*\]/g, "]");
					value = JSON.parse(value);
				}
				properties[field.totalfield.property] = value;
			}
		});
		return properties;
	}
}
