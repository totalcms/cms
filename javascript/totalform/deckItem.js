import Dialog from "./dialog";

//-----------------------------------------------
// Total CMS Deck Item
//-----------------------------------------------
export default class DeckItem {

    constructor(container, fieldClass, deck) {
		this.container          = container;
		this.container.deckitem = this;
		this.fieldClass         = fieldClass;
		this.deck               = deck;

        this.dialog = this.setupDialog();
        this.deck.form.processFields();
		setTimeout(() => {
			this.initVisibility();
			this.setupLabelUpdate();
		}, 0);
    }

    initVisibility() {
		const dialogEl = this.dialog.dialog;
		const fieldContainers = dialogEl.querySelectorAll('.form-field');
		const fields = [];
		for (const container of fieldContainers) {
			if (container.totalfield) {
				fields.push(container.totalfield);
			}
		}
		if (fields.length > 0) {
			this.deck.form.visibility.initializeScope(dialogEl, fields);
		}
	}

    setupDialog() {
		// Get both the label button and edit button as dialog triggers
		const labelButton = this.container.querySelector('.deck-item-label');
		const editButton = this.container.querySelector("button.edit");

        return new Dialog(this.container.querySelector("dialog"), {
            open: [labelButton, editButton].filter(Boolean), // Open dialog when either button is clicked
            close: ".close",
            onOpen: () => {
                if (this.dialogOpened) return;
                this.dialogOpened = true;
            },
            onClose: () => {
                this.dialogOpened = false;
				// Update label when dialog closes
				this.updateLabel();
            }
        });
    }

	error(message) {
		const idField = this.container.querySelector(".id-field");
		idField?.totalfield?.error(message);
	}

	setupLabelUpdate() {
		// Update the label initially (in case it was auto-generated)
		this.updateLabel();
	}

	updateLabel() {
		const labelElement = this.container.querySelector('.deck-item-label');
		if (!labelElement) return;

		const pattern = this.container.getAttribute('data-deck-label-pattern') || '${id}';
		const labelText = this.generateLabel(pattern);

		labelElement.innerHTML = labelText;
	}

	generateLabel(pattern) {
		// Get all field data from the dialog
		const fieldData = this.getValue() || {};

		// Check if this is a new item (no existing ID)
		const isNewItem = !this.container.getAttribute('data-item-id') ||
		                  this.container.getAttribute('data-item-id') === '';

		// Only generate dynamic values for new items
		if (isNewItem) {
			const now = new Date();
			fieldData.now = Date.now();
			fieldData.timestamp = now.toISOString().slice(0, -5).replace(/-|:/g, '');
			fieldData.uuid = this.generateUuid();
			fieldData.uid = Math.random().toString(36).substring(2, 9);
			fieldData.oid = this.deck.getNextOid();
			fieldData.currentyear = now.getFullYear().toString();
			fieldData.currentyear2 = now.getFullYear().toString().slice(-2);
			fieldData.currentmonth = String(now.getMonth() + 1).padStart(2, '0');
			fieldData.currentday = String(now.getDate()).padStart(2, '0');
		}

		// Replace placeholders in the pattern
		let label = pattern.replace(/\${(.*?)}/g, (match, key) => {
			// Check if this is an oid with zero-padding format: oid-00000
			if (key.startsWith('oid-') && /^oid-0+$/.test(key)) {
				const zeros = key.substring(4);
				const paddingLength = zeros.length;
				const oidValue = this.deck.getNextOid();
				return oidValue.toString().padStart(paddingLength, '0');
			}

			// Get value from field data
			const value = fieldData[key] || '';

			// Check if value looks like SVG
			if (typeof value === 'string' && value.trim().startsWith('<svg')) {
				return `<span class="deck-label-svg">${value}</span>`;
			}

			return value;
		});

		// Trim whitespace
		label = label.trim();

		// Fall back to the item's id when the pattern resolves to an empty string
		if (label === '' || label === 'null' || label === 'undefined') {
			return fieldData.id || '';
		}

		return label;
	}

	generateUuid() {
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
			const r = Math.random() * 16 | 0;
			const v = c == 'x' ? r : (r & 0x3 | 0x8);
			return v.toString(16);
		});
	}


    getItemId() {
		// Get ID directly from the dialog's ID field
		const dialogIdField = this.dialog.dialog.querySelector("input[name='id']");
		return dialogIdField ? String(dialogIdField.value) : '';
    }

    getValue() {
        const data = {};

        // Collect all form field containers within this item's dialog
        const formFieldContainers = this.dialog.dialog.querySelectorAll('.form-field');

        for (const container of formFieldContainers) {
            // Check if the container has a TotalField instance
            if (container.totalfield && container.totalfield.input && container.totalfield.input.name) {
                const fieldName = container.totalfield.input.name;
                try {
                    // Use the TotalField's getValue method to get the proper value
                    data[fieldName] = container.totalfield.getValue();
                } catch (error) {
                    // If getValue fails (e.g., field not fully initialized), fall back to input value
                    console.warn(`Failed to get value for field ${fieldName} in deck item:`, error);
                    if (container.totalfield.input) {
                        data[fieldName] = container.totalfield.input.value || '';
                    }
                }
            }
        }

        // Fallback: collect any remaining input fields that don't have TotalField instances
        const formFields = this.dialog.dialog.querySelectorAll('input, textarea, select');

        for (const field of formFields) {
            if (field.name && !data.hasOwnProperty(field.name)) {
                // Handle different field types for fields without TotalField instances
                if (field.type === 'checkbox') {
                    data[field.name] = field.checked;
                } else if (field.type === 'radio') {
                    if (field.checked) {
                        data[field.name] = field.value;
                    }
                } else if (field.tagName === 'SELECT' && field.multiple) {
                    data[field.name] = Array.from(field.selectedOptions).map(option => option.value);
                } else {
                    data[field.name] = field.value;
                }
            }
        }

        return Object.keys(data).length > 0 ? data : null;
    }

    setValue(value) {
        if (!value || typeof value !== 'object') return;

        // Set values for all fields in the dialog
        for (const [fieldName, fieldValue] of Object.entries(value)) {
            const field = this.dialog.dialog.querySelector(`[name="${fieldName}"]`);

            if (field) {
                if (field.type === 'checkbox') {
                    field.checked = Boolean(fieldValue);
                } else if (field.type === 'radio') {
                    if (field.value === fieldValue) {
                        field.checked = true;
                    }
                } else if (field.tagName === 'SELECT' && field.multiple && Array.isArray(fieldValue)) {
                    Array.from(field.options).forEach(option => {
                        option.selected = fieldValue.includes(option.value);
                    });
                } else {
                    field.value = fieldValue;
                }
            }
        }

    }

    clearValue() {
        const formFields = this.dialog.dialog.querySelectorAll('input, textarea, select');

        for (const field of formFields) {
            if (field.name) {
                if (field.type === 'checkbox' || field.type === 'radio') {
                    field.checked = false;
                } else if (field.tagName === 'SELECT' && field.multiple) {
                    Array.from(field.options).forEach(option => {
                        option.selected = false;
                    });
                } else {
                    field.value = '';
                }
            }
        }

    }

    // Cleanup method when deck item is destroyed
    destroy() {
		this.container.remove();
    }

    isUnsaved() {
        return this.container.classList.contains("unsaved");
    }

    saved() {
        this.container.classList.remove("unsaved");
    }

	validate() {
		let isValid = true;

		// Validate all form fields within this deck item's dialog
		const formFieldContainers = this.dialog.dialog.querySelectorAll('.form-field');

		for (const container of formFieldContainers) {
			if (container.totalfield && typeof container.totalfield.validate === 'function') {
				if (!container.totalfield.validate()) {
					isValid = false;
				}
			}
		}

		return isValid;
	}
}