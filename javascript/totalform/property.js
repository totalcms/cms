import Dialog from "./dialog";
import Details from "./details";
import { isNestedField } from "./fieldCollection.mjs";

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
		const openButton = this.container.querySelector("button.edit");
		return new Dialog(this.container.querySelector("dialog"), {
			open   : openButton,
			close  : ".close",
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
		if (details.details) return; // Already initialized
		new Details(details);
	}

	updateIcons() {
		const fieldIcon = this.container.querySelector("[name=field]");
		const typeIcon  = this.container.querySelector("[name=type]");

		const newProperty = this.container.classList.contains('new-property');

		let newclass = `${this.fieldClass} ${fieldIcon.value}-field`;
		if (typeIcon) {
			newclass += ` ${typeIcon.value}-type`;
		}
		this.container.className = newclass;

		if (newProperty) {
			this.container.classList.add('new-property');
		}
	}

	getName(){
		return this.container.querySelector('[name=property]').value;
	}

	getValue() {
		const fields = this.container.getElementsByClassName("form-field");
		const properties = {};

		for (const field of fields) {
			// Only collect this property's OWN top-level fields. Composite
			// fields (e.g. the MCP `card`) render their sub-fields as nested
			// .form-field elements; those belong to the composite's own
			// getValue(), not the property root. Without this guard a card
			// sub-field like `expose` leaks to the top level of the property
			// alongside its correct `mcp.expose` home (empty sub-fields get
			// dropped by the truthiness check below, so the checkbox `expose`
			// was the one that slipped through). Shared with the deck paths via
			// fieldCollection.mjs.
			if (isNestedField(field, this.container)) continue;

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
