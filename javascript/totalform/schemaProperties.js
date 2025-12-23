import PropertiesField from "./properties";
import PropertyField from "./property";
const slugify = require('slugify')

//-----------------------------------------------
// Total CMS Properties
//-----------------------------------------------
export default class SchemaPropertiesField extends PropertiesField {

    constructor(container, options) {
		options.fieldClass = "schema-field";
        super(container, options);

        this.template  = this.container.querySelector(".schema-template");
        this.addButton = this.container.querySelector(".cms-add");
		this.addButton?.addEventListener("click", this.addTemplate.bind(this));

		// Listen for override button clicks from inherited properties
		this.initOverrideButtons();
    }

	initOverrideButtons() {
		document.querySelectorAll('.override').forEach(button => {
			button.addEventListener('click', (e) => {
				const name = button.dataset.propertyName;
				const definition = JSON.parse(button.dataset.propertyDefinition);
				this.overrideProperty(name, definition);
			});
		});
	}

	overrideProperty(name, definition) {
		const clone = this.template.content.cloneNode(true);
		const parent = this.addButton.parentNode;
		parent.insertBefore(clone, this.addButton);

		const field = Array.from(parent.querySelectorAll("." + this.fieldClass)).pop();

		// Mark as new property for styling
		field.classList.add('new-property');

		// Populate the property name
		const nameInput = field.querySelector('input[type="text"]');
		if (nameInput) {
			nameInput.value = name;
		}

		// Populate the field type dropdown (uses name="field" attribute)
		const fieldSelect = field.querySelector('[name=field]');
		if (fieldSelect && definition.field) {
			fieldSelect.value = definition.field;
			// Trigger change event to update UI
			fieldSelect.dispatchEvent(new Event('change', { bubbles: true }));
		}

		// Populate the type dropdown (uses name="type" attribute)
		const typeSelect = field.querySelector('[name=type]');
		if (typeSelect && definition.type) {
			typeSelect.value = definition.type;
			typeSelect.dispatchEvent(new Event('change', { bubbles: true }));
		}

		// Populate text fields
		this.populateFieldValue(field, 'label', definition.label);
		this.populateFieldValue(field, 'help', definition.help);
		this.populateFieldValue(field, 'placeholder', definition.placeholder);
		this.populateFieldValue(field, 'default', definition.default);
		this.populateFieldValue(field, 'factory', definition.factory);

		// Populate select fields
		this.populateSelectValue(field, 'deckref', definition.deckref);

		// Populate JSON fields (settings, options, extra)
		this.populateJsonField(field, 'settings', definition.settings);
		this.populateJsonField(field, 'options', definition.options);

		// Handle extra properties (anything not in standard schema fields)
		const extraProps = this.extractExtraProperties(definition);
		if (Object.keys(extraProps).length > 0) {
			this.populateJsonField(field, 'extra', extraProps);
		}

		// Initialize the new field
		this.newField(field);

		// Update the container's CSS classes to match the select values
		if (field.totalfield && typeof field.totalfield.updateIcons === 'function') {
			field.totalfield.updateIcons();
		}

		// Scroll to the new field if not in view
		this.scrollToField(field);
	}

	populateFieldValue(field, fieldName, value) {
		if (!value) return;

		const input = field.querySelector(`[name="${fieldName}"], [name*="${fieldName}"], [data-field="${fieldName}"]`);
		if (input) {
			input.value = value;
		}
	}

	populateSelectValue(field, fieldName, value) {
		if (!value) return;

		const select = field.querySelector(`select[name="${fieldName}"]`);
		if (select) {
			select.value = value;
			select.dispatchEvent(new Event('change', { bubbles: true }));
		}
	}

	populateJsonField(field, fieldName, value) {
		if (!value || (typeof value === 'object' && Object.keys(value).length === 0)) return;

		const input = field.querySelector(`[name="${fieldName}"], [data-field="${fieldName}"]`);
		if (input) {
			// Convert object/array to JSON string for textarea fields
			input.value = typeof value === 'object' ? JSON.stringify(value, null, 2) : value;
		}
	}

	extractExtraProperties(definition) {
		// Standard schema property fields that should not be in extra
		const standardFields = [
			'field', 'type', 'label', 'help', 'placeholder', 'default',
			'factory', 'deckref', 'settings', 'options', '$ref'
		];

		const extra = {};
		for (const key in definition) {
			if (!standardFields.includes(key)) {
				extra[key] = definition[key];
			}
		}
		return extra;
	}

	scrollToField(field) {
		// Small delay to ensure the field is fully rendered
		setTimeout(() => {
			const rect = field.getBoundingClientRect();
			const inView = rect.top >= 0 && rect.bottom <= window.innerHeight;

			if (!inView) {
				field.scrollIntoView({ behavior: 'smooth', block: 'center' });
			}
		}, 100);
	}

    addTemplate() {
		const clone = this.template.content.cloneNode(true);
		const parent = this.addButton.parentNode;
		parent.insertBefore(clone, this.addButton);

		const field = Array.from(parent.querySelectorAll("."+this.fieldClass)).pop();

		// Mark as new property for styling
		field.classList.add('new-property');

		field.querySelector("input").focus();
		this.newField(field);
	}

    newField(field) {
		const newField = new PropertyField(field, this.fieldClass);
		newField.form = this.form;
		this.initActionbar(field);
		this.form.processFields();
	}

	initActionbar(field) {
		const trash     = field.querySelector("button.trash");
		const duplicate = field.querySelector("button.duplicate");
		const property  = field.querySelector("input");

		// Ensure properties are all lowercase
		property?.addEventListener("change", () => {
			// Replace hyphens with underscores because it makes for nicer twig macros
			property.value = slugify(property.value, { lower: true }).replace(/-/g, "_");
		});

		trash?.addEventListener("click", () => this.removeField(field));
		duplicate?.addEventListener("click", () => this.duplicateField(field));
	}

	removeField(field) {
		field.remove();
	}

	duplicateField(field) {
		// Get all select values before cloning
		const selects = field.querySelectorAll('select');
		const selectValues = Array.from(selects).map(select => select.value);

		const clone = field.cloneNode(true);
		const parent = field.parentNode;
		parent.insertBefore(clone, field.nextSibling);

		const newField = field.nextSibling;

		// Restore select values after cloning
		const clonedSelects = newField.querySelectorAll('select');
		clonedSelects.forEach((select, index) => {
			if (selectValues[index]) {
				select.value = selectValues[index];
			}
		});

		newField.querySelector("input").focus();
		this.newField(newField);
	}

	schema() {
        return {
            type     : "schemaProperties",
            fieldset : this.type
        };
    }
}
