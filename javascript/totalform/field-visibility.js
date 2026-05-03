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
		this.initializeScope(this.form, this.fields);
	}

	//-------------------------
	// Initialize Scoped Visibility
	// Works within a specific container and field set (e.g., deck item dialogs)
	//-------------------------
	initializeScope(container, fields) {
		const fieldsWithSettings = Array.from(container.querySelectorAll('[data-settings]'));

		fieldsWithSettings.forEach(fieldElement => {
			const settings = JSON.parse(fieldElement.dataset.settings || '{}');
			const visibility = settings.visibility;

			// Skip fields without visibility settings
			if (!visibility || !visibility.watch) return;

			const watchField = visibility.watch;

			// Find the watched field element within the scoped container
			const watchedFieldElement = container.querySelector(`[style*="--grid-area: ${watchField}"]`);
			if (!watchedFieldElement) return;

			// Set up change listener on the watched field
			watchedFieldElement.addEventListener('change', () => {
				this.updateScopedVisibility(fieldElement, visibility, fields);
			});

			// Initial visibility evaluation
			this.updateScopedVisibility(fieldElement, visibility, fields);
		});
	}

	//-------------------------
	// Update Field Visibility (scoped)
	//-------------------------
	updateScopedVisibility(fieldElement, visibility, fields) {
		const watchField = visibility.watch;
		const expectedValue = visibility.value;
		const operator = visibility.operator || '==';

		// Get the watched field object from the scoped fields
		const watchedField = fields.find(f => f.property === watchField);
		if (!watchedField) {
			// If watched field not found, hide by default
			const field = fields.find(f => f.container === fieldElement);
			if (field) this.setVisibility(field, false);
			return;
		}

		// If the watched field is hidden, this field should also be hidden
		if (!watchedField.isVisible()) {
			const field = fields.find(f => f.container === fieldElement);
			if (field) this.setVisibility(field, false);
			return;
		}

		const currentValue = watchedField.getValue();

		// Evaluate the condition
		const isVisible = this.evaluateCondition(currentValue, expectedValue, operator);

		// Get the field object and toggle visibility
		const field = fields.find(f => f.container === fieldElement);
		if (field) {
			this.setVisibility(field, isVisible);
		}
	}

	//-------------------------
	// Set field visibility and dispatch change event to cascade to dependents
	//-------------------------
	setVisibility(field, isVisible) {
		const wasVisible = !field.container.classList.contains('cms-hide');
		isVisible ? field.show() : field.hide();

		// If visibility changed, dispatch a change event so dependent fields re-evaluate
		if (wasVisible !== isVisible) {
			field.container.dispatchEvent(new Event('change', { bubbles: true }));
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

		// Evaluate based on operator. By the time we reach this switch,
		// expectedValue is a single (non-array) value — array values were
		// already split and recursed at the top of this method, so each
		// recursive call lands here with one value.
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
				// At this point expectedValue is a single value (the array
				// case is handled by the early return + recursion above).
				// `in` should match if the current value equals it.
				return currentValue == expectedValue;
			case 'not_in':
				return currentValue != expectedValue;
			default:
				return currentValue == expectedValue;
		}
	}
}
