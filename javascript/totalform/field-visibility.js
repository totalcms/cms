//-----------------------------------------------
// Field Visibility Manager
// Handles conditional field visibility for forms
//-----------------------------------------------
export default class FieldVisibility {

	constructor(formElement, fields) {
		this.form = formElement;
		this.fields = fields;
	}

	//-------------------------
	// Initialize Visibility
	//-------------------------
	initialize() {
		const fieldsWithSettings = Array.from(this.form.querySelectorAll('[data-settings]'));

		fieldsWithSettings.forEach(fieldElement => {
			const settings = JSON.parse(fieldElement.dataset.settings || '{}');
			const visibility = settings.visibility;

			// Skip fields without visibility settings
			if (!visibility || !visibility.watch) return;

			const watchField = visibility.watch;

			// Find the watched field element
			const watchedFieldElement = this.form.querySelector(`[style*="grid-area: ${watchField}"]`);
			if (!watchedFieldElement) return;

			// Set up change listener on the watched field
			watchedFieldElement.addEventListener('change', () => {
				this.updateFieldVisibility(fieldElement, visibility);
			});

			// Initial visibility evaluation
			this.updateFieldVisibility(fieldElement, visibility);
		});
	}

	//-------------------------
	// Update Field Visibility
	//-------------------------
	updateFieldVisibility(fieldElement, visibility) {
		const watchField = visibility.watch;
		const expectedValue = visibility.value;
		const operator = visibility.operator || '==';

		// Get the watched field object
		const watchedField = this.fields.find(f => f.property === watchField);
		if (!watchedField) {
			// If watched field not found, hide by default
			const field = this.fields.find(f => f.container === fieldElement);
			if (field) field.hide();
			return;
		}

		const currentValue = watchedField.getValue();

		// Evaluate the condition
		const isVisible = this.evaluateCondition(currentValue, expectedValue, operator);

		// Get the field object and toggle visibility
		const field = this.fields.find(f => f.container === fieldElement);
		if (field) {
			isVisible ? field.show() : field.hide();
		}
	}

	//-------------------------
	// Evaluate Visibility Condition
	//-------------------------
	evaluateCondition(currentValue, expectedValue, operator) {
		// Handle array expected values (multiple possible values)
		if (Array.isArray(expectedValue)) {
			return expectedValue.some(value =>
				this.evaluateCondition(currentValue, value, operator)
			);
		}

		// Handle array current values (checkboxes, multiselect, etc.)
		if (Array.isArray(currentValue)) {
			// Support operators for array values
			switch (operator) {
				case 'in':
				case '==':
					return currentValue.includes(expectedValue);
				case 'not_in':
				case '!=':
					return !currentValue.includes(expectedValue);
				case 'empty':
					return currentValue.length === 0;
				case 'not_empty':
					return currentValue.length > 0;
				default:
					return currentValue.includes(expectedValue);
			}
		}

		// Evaluate based on operator
		switch (operator) {
			case '==':
				return currentValue == expectedValue;
			case '!=':
				return currentValue != expectedValue;
			case '>':
				return Number(currentValue) > Number(expectedValue);
			case '<':
				return Number(currentValue) < Number(expectedValue);
			case '>=':
				return Number(currentValue) >= Number(expectedValue);
			case '<=':
				return Number(currentValue) <= Number(expectedValue);
			case 'in':
				return Array.isArray(expectedValue) && expectedValue.includes(currentValue);
			case 'not_in':
				return Array.isArray(expectedValue) && !expectedValue.includes(currentValue);
			default:
				return currentValue == expectedValue;
		}
	}
}
