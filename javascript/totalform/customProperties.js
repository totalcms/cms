import PropertiesField from "./properties";
import TotalField from "./totalfield";
import Sortable from 'sortablejs';

//-----------------------------------------------
// Total CMS Properties
//-----------------------------------------------
export default class CustomPropertiesField extends TotalField {

    constructor(container, options) {
        super(container, options);

		// not storing this as an array so that it can be updated simply through the DOM
		this.objectFields = this.container.getElementsByClassName("customProperties-object");
		for (const field of this.objectFields) {
			this.newField(field)
		}

		this.template = this.container.querySelector("template");
		this.addButton = this.container.querySelector(".cms-add");
		this.addButton.addEventListener("click", this.addTemplate.bind(this));

		this.sortableObjects();
    }

	sortableObjects() {
		// Make the object fields sortable
		const objects = this.container.querySelector(".form-group");
		Sortable.create(objects, {
			animation  : 150,
			ghostClass : 'drag-ghost',
			filter     : 'button',
		});
	}

	initActionbar(field) {
		const trash = field.querySelector("button.trash");
		const duplicate = field.querySelector("button.duplicate");

		trash.addEventListener("click", () => this.removeField(field));
		duplicate.addEventListener("click", () => this.duplicateField(field));
	}

	removeField(field) {
		field.remove();
	}

	duplicateField(field) {
		const clone = field.cloneNode(true);
		const parent = field.parentNode;
		parent.insertBefore(clone, field.nextSibling);

		const newField = field.nextSibling;
		newField.querySelector("input").focus();
		this.newField(newField);
	}

	newField(field) {
		new PropertiesField(field);
		this.initActionbar(field);
		this.form.processFields();
	}

	addTemplate() {
		const clone = this.template.content.cloneNode(true);
		const parent = this.addButton.parentNode;
		parent.insertBefore(clone, this.addButton);

		const field = Array.from(parent.querySelectorAll(".customProperties-object")).pop();
		field.querySelector("input").focus();
		this.newField(field);
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
		const objects = {};

		for (const field of this.objectFields) {
			const object = field.totalfield;
			const properties = object.getValue();
			if (Object.keys(properties).length > 0) {
				const objectId = object.container.querySelector('[name=object]').value;
				objects[objectId] = properties;
			}
		}

		return objects;
	}

	clearValue() {
	}

    setValue() {
    }

	schema() {
        return {
            type     : "customProperties",
            fieldset : this.type
        };
    }
}
