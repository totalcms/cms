import PropertyField from "./property";
import TotalField from "./totalfield";
import Sortable from 'sortablejs';

//-----------------------------------------------
// Total CMS Properties
//-----------------------------------------------
export default class PropertiesField extends TotalField {


    constructor(container, options) {
        super(container, options);

		this.fieldClass = options?.fieldClass || "property-field";

		// not storing this as an array so that it can be updated simply through the DOM
		const propertyFields = this.container.getElementsByClassName(this.fieldClass);
		for (const field of propertyFields) {
			new PropertyField(field, this.fieldClass);
			this.initActionbar(field);
		}
		this.sortableProperties(propertyFields);

		this.template  = this.container.querySelector("template");
		this.addSelect = this.container.querySelector("select[name='addProperty']");

		this.initAddProperty();
    }

	sortableProperties(propertyFields) {
		if (propertyFields.length === 0) return;
		// Make the fields sortable
		Sortable.create(propertyFields[0].parentNode, {
			animation  : 150,
			ghostClass : 'drag-ghost',
		});
	}

	initAddProperty() {
		this.addSelect.addEventListener("change", () => {
			const selectedOption = this.addSelect.selectedOptions[0];
			const selectedValue  = this.addSelect.value;
    		if (!selectedValue) return;

			// Add the selected property to the list
			const property = JSON.parse(selectedValue);
			property.property = selectedOption.textContent;
			this.addProperty(property);

			// Remove the selected option from the select
			selectedOption.remove();

			// Set the placeholder option as selected
			this.addSelect.value = "";
			this.addSelect.childNodes[0].selected = true;
		});
	}

	addProperty(property) {
		console.log("Adding property", property);

		const clone = this.template.content.cloneNode(true);
		const parent = this.addSelect.parentNode;
		parent.insertBefore(clone, this.addSelect);

		const propertyField = Array.from(parent.getElementsByClassName(this.fieldClass)).pop();
		console.log(propertyField, property);

		propertyField.classList.remove('-field');
		propertyField.classList.add(`${property.field}-field`);

		propertyField.querySelector("label").innerHTML = property.property;
		propertyField.querySelector("button.edit").setAttribute("title", `Edit ${property.property} property`);
		propertyField.querySelector("button.trash").setAttribute("title", `Remove ${property.property} property`);

		for (const key in property) {
			const input = propertyField.querySelector(`[name='${key}']`);
			const value = typeof property[key] === "object" ? JSON.stringify(property[key]) : property[key];
			if (input) input.value = value;
		}

		new PropertyField(propertyField, this.fieldClass);
		this.initActionbar(propertyField);
		this.form.processFields();
	}

	initActionbar(field) {
		const trash = field.querySelector("button.trash");
		trash?.addEventListener("click", () => this.removeField(field));
	}

	removeField(field) {
		field.remove();
	}

	isUnsaved() {
		const unsavedChildren = this.container.querySelectorAll(".unsaved");
		return this.container.classList.contains("unsaved") || unsavedChildren.length > 0;
	}

	saved() {
		super.saved();
		const unsavedChildren = this.container.querySelectorAll(".unsaved");
		unsavedChildren.forEach(unsavedChild => unsavedChild.classList.remove("unsaved"));
	}

    getValue() {
		const propertyFields = this.container.getElementsByClassName(this.fieldClass);

		const properties = {};
		for (const field of propertyFields) {
			const property = field.totalfield;
			properties[property.getName()] = property.getValue()
		}
		return properties;
	}

	clearValue() {
	}

    setValue() {
    }

	schema() {
        return {
            type     : "properties",
            fieldset : this.type
        };
    }
}
