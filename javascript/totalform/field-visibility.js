//-----------------------------------------------
// Field Visibility Manager
// Handles conditional field visibility for forms
//-----------------------------------------------
export default class FieldVisibility {

	constructor(formElement, fields) {
		this.form = formElement;
		this.fields = fields;
		this.controller = null;
	}

	//-------------------------
	// Initialize Visibility
	//
	// Abort + recreate the controller so re-calling initialize() (e.g. from
	// TotalForm.refreshFields() after a DOM swap) does not stack duplicate
	// listeners on watched fields.
	//-------------------------
	initialize() {
		this.controller?.abort();
		this.controller = new AbortController();
		this.initializeScope(this.form, this.fields, this.controller.signal);
	}

	//-------------------------
	// Initialize Scoped Visibility
	// Works within a specific container and field set (e.g., deck item dialogs)
	//-------------------------
	initializeScope(container, fields, signal = undefined) {
		const fieldsWithSettings = Array.from(container.querySelectorAll('[data-settings]'));
		const listenerOpts = signal ? { signal } : undefined;

		fieldsWithSettings.forEach(fieldElement => {
			const settings = JSON.parse(fieldElement.dataset.settings || '{}');
			const visibility = settings.visibility;

			// Skip fields without visibility settings
			if (!visibility || !visibility.watch) return;

			// A field inside a deck-table row belongs to that row's own scoped init
			// (DeckTableField.initRowVisibility). Skip it in any other scope — e.g.
			// the top-level form scan — otherwise, with multiple rows sharing field
			// names, its `watch` would resolve against the first row's field and
			// every row would react to row one.
			const ownerRow = fieldElement.closest('.deck-table-row');
			if (ownerRow && ownerRow !== container) return;

			const watchField = visibility.watch;

			// Find the watched field element within the scoped container
			const watchedFieldElement = container.querySelector(`[style*="--grid-area: ${watchField}"]`);
			if (!watchedFieldElement) return;

			// Re-evaluate when the watched field's value changes (native `change`)
			// or when its own visibility flips (`visibility-change` from a parent
			// cascade). The latter must NOT be a `change` event — that would bubble
			// to TotalField's change listener and falsely mark the field unsaved
			// (cards and other composite fields whose getValue() returns a fresh
			// object can't rely on the `===` equality guard in changed()).
			const reevaluate = () => this.updateScopedVisibility(fieldElement, visibility, fields);
			watchedFieldElement.addEventListener('change', reevaluate, listenerOpts);
			watchedFieldElement.addEventListener('visibility-change', reevaluate, listenerOpts);

			// Initial visibility evaluation
			this.updateScopedVisibility(fieldElement, visibility, fields);
		});

		// A divider or header with nothing visible under it goes too. Every
		// show/hide dispatches a bubbling `visibility-change`, so one listener
		// on the container covers all of its fields.
		const tidy = () => {
			this.updateSections(container);
			this.collapseTrailingRows(container);
		};
		container.addEventListener('visibility-change', tidy, listenerOpts);
		tidy();
	}

	//-------------------------
	// Hide section dividers and headers whose fields are all hidden,
	// then fieldsets and accordions (updateGroups)
	//
	// FormGridBuilder lists the fields each marker introduces in
	// `data-section-fields`. The fields are looked up among the marker's own
	// siblings — its grid — so a card's dividers answer to the card's fields,
	// not to a same-named field elsewhere in the form.
	//-------------------------
	updateSections(container) {
		container.querySelectorAll('[data-section-fields]').forEach(marker => {
			const areas = marker.dataset.sectionFields.split(' ').filter(Boolean);
			const grid  = marker.parentElement;
			if (areas.length === 0 || !grid) return;

			const siblings = Array.from(grid.children);
			const anyVisible = areas.some(area => {
				const field = siblings.find(el => el.style.getPropertyValue('--grid-area').trim() === area);
				// A listed field that was never rendered counts as hidden.
				return field && !field.classList.contains('field-hidden') && !field.classList.contains('hidden-field');
			});

			marker.classList.toggle('section-hidden', !anyVisible);
		});

		this.updateGroups(container);
	}

	//-------------------------
	// Hide fieldsets and accordions whose fields are all hidden
	//
	// A group holds its own fields, so there is nothing to look up: it goes
	// when it has fields and none of them shows. A group with no fields at
	// all (a cms.form.fieldset() around plain markup) is left alone. Panels
	// are settled before their accordion, which goes when every panel has.
	//-------------------------
	updateGroups(container) {
		const shows = field => !field.closest('.field-hidden, .hidden-field');

		container.querySelectorAll('.form-grid-fieldset, .formgrid-panel').forEach(group => {
			const fields = Array.from(group.querySelectorAll('.form-field'));
			group.classList.toggle('section-hidden', fields.length > 0 && !fields.some(shows));
		});

		container.querySelectorAll('.formgrid-accordion').forEach(accordion => {
			const panels = Array.from(accordion.querySelectorAll(':scope > .formgrid-panel'));
			accordion.classList.toggle('section-hidden', panels.length > 0 && panels.every(panel => panel.classList.contains('section-hidden')));
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
			if (field) this.setVisibility(field, false, visibility.mode);
			return;
		}

		// If the watched field is hidden, this field should also be hidden
		if (!watchedField.isVisible()) {
			const field = fields.find(f => f.container === fieldElement);
			if (field) this.setVisibility(field, false, visibility.mode);
			return;
		}

		const currentValue = watchedField.getValue();

		// Evaluate the condition
		const isVisible = this.evaluateCondition(currentValue, expectedValue, operator);

		// Get the field object and toggle visibility
		const field = fields.find(f => f.container === fieldElement);
		if (field) {
			this.setVisibility(field, isVisible, visibility.mode);
		}
	}

	//-------------------------
	// Set field visibility and dispatch a visibility-change event so dependent
	// fields can re-evaluate. This intentionally does NOT use a `change` event:
	// `change` bubbles to TotalField listeners and marks the field unsaved
	// (cards in particular can't disambiguate a synthetic cascade from a real
	// edit because their getValue() returns a fresh object each call, defeating
	// the storedValue === getValue() guard).
	//-------------------------
	setVisibility(field, isActive, mode = 'hide') {
		if (mode === 'disable') {
			const wasActive = !field.container.classList.contains('field-disabled');
			isActive ? field.enable() : field.disable();
			if (wasActive !== isActive) {
				field.container.dispatchEvent(new Event('visibility-change', { bubbles: true }));
			}
			return;
		}

		// Ask the field directly — both TotalField and SimpleForm wrappers
		// toggle `field-hidden` (the legacy `cms-hide` class was never set by
		// either path, so the previous read here always returned `true`).
		const wasVisible = field.isVisible();
		isActive ? field.show() : field.hide();

		if (wasVisible !== isActive) {
			field.container.dispatchEvent(new Event('visibility-change', { bubbles: true }));
		}
	}

	//-------------------------
	// Close up the space left by hidden rows at the end of a grid
	//
	// A row whose fields are all hidden collapses to 0px, but the grid still
	// puts a row-gap before it. One or two go unnoticed; a card that hides a
	// dozen rows behind a toggle is left with a tall blank block. Only the
	// trailing run is closed up — a negative margin can take space off the
	// end of the grid, not out of its middle.
	//-------------------------
	collapseTrailingRows(container) {
		const grids = Array.from(container.querySelectorAll('.formgrid'));
		if (container.matches?.('.formgrid')) grids.push(container);

		grids.forEach(grid => {
			const style = getComputedStyle(grid);
			const rows  = (style.gridTemplateRows || '').split(' ').filter(Boolean);
			const gap   = parseFloat(style.rowGap) || 0;

			let empty = 0;
			while (empty < rows.length - 1 && parseFloat(rows[rows.length - 1 - empty]) === 0) empty++;

			if (empty > 0 && gap > 0) {
				grid.style.setProperty('--collapsed-row-gap', `${empty * gap}px`);
			} else {
				grid.style.removeProperty('--collapsed-row-gap');
			}
		});
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
