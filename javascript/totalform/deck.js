import TotalField from "./totalfield";
import DeckItem from "./deckItem";
import TotalSortable from "./total-sortable";
const slugify = require('slugify');

//-----------------------------------------------
// Total CMS Deck Field
//-----------------------------------------------
export default class DeckField extends TotalField {

    constructor(container, settings) {
        super(container, settings);

        this.fieldClass = "deck-item";
        this.schemaref = container.dataset.schemaref || container.dataset.deckref || '';
        this.minItems = parseInt(this.settings.minItems) || 0;
        this.maxItems = parseInt(this.settings.maxItems) || -1;

		this.items = [];
		this.valid = true;
		this.sortable = null; // Store sortable instance

        // Initialize existing deck items
        const deckItems = this.container.getElementsByClassName(this.fieldClass);
        for (const item of deckItems) {
            this.newItem(item);
        }
        this.initSortable();

        // Initialize oid counter based on existing items (never decreases)
        this.initOidCounter();

        // Get template for new items
        this.template = this.container.querySelector("template.deck-template");

        // Setup add button
        this.addButton = this.container.querySelector(".cms-add");
        this.addButton?.addEventListener("click", this.addItem.bind(this));

        this.updateAddButton();

        // Propagate subfield changes to the deck's own changed handler
        this.container.addEventListener("subfield-change", () => this.changed());
    }

    initOidCounter() {
        // Initialize the counter based on the number of existing items
        // This counter will only increase, never decrease (even when items are deleted)
        const existingCount = this.container.querySelectorAll('.deck-item').length;
        this.oidCounter = existingCount;
    }

    getNextOid() {
        // Increment and return the next oid
        this.oidCounter++;
        return this.oidCounter;
    }


    initSortable() {
        // Destroy existing sortable instance if it exists
        if (this.sortable) {
            this.sortable.destroy();
        }

        // Create sortable instance on the form-group div that contains deck items
        // The form-group is the direct parent of deck items
        const formGroup = this.container.querySelector('.form-group');
        if (!formGroup) return;

        // Create sortable instance once - it will automatically handle new items
        this.sortable = new TotalSortable(formGroup, {
			handle : '.sort-handle',
			onEnd  : () => this.changed(),
        });
    }

    /**
     * Regenerate unique IDs in a cloned element to prevent duplicate id/for collisions.
     * Replaces field-{uuid}, help-{uuid}, and datalist-{uuid} with fresh unique values.
     */
    regenerateIds(element) {
        const idMap = {};

        // Find all elements with an id that matches our UUID patterns
        element.querySelectorAll('[id]').forEach(el => {
            const oldId = el.id;
            const match = oldId.match(/^(field|help|datalist)-(.+)$/);
            if (!match) return;

            const prefix = match[1];
            const oldUuid = match[2];

            // Reuse the same new UUID for all prefixes sharing the same old UUID
            if (!idMap[oldUuid]) {
                idMap[oldUuid] = Math.random().toString(36).substring(2, 15);
            }

            el.id = `${prefix}-${idMap[oldUuid]}`;
        });

        // Update corresponding for, aria-describedby, and list attributes
        for (const [oldUuid, newUuid] of Object.entries(idMap)) {
            element.querySelectorAll(`[for="field-${oldUuid}"]`).forEach(el => {
                el.setAttribute('for', `field-${newUuid}`);
            });
            element.querySelectorAll(`[aria-describedby="help-${oldUuid}"]`).forEach(el => {
                el.setAttribute('aria-describedby', `help-${newUuid}`);
            });
            element.querySelectorAll(`[list="datalist-${oldUuid}"]`).forEach(el => {
                el.setAttribute('list', `datalist-${newUuid}`);
            });
        }
    }

    updateAddButton() {
        if (!this.addButton) return;
        const atMax = this.maxItems > -1 && this.items.length >= this.maxItems;
        this.addButton.disabled = atMax;
    }

    addItem() {
        if (!this.template) {
            console.error('No deck template found');
            return;
        }

        if (this.maxItems > -1 && this.items.length >= this.maxItems) return;

        // Clone the template
        const clone = this.template.content.cloneNode(true);
        this.regenerateIds(clone);

        // Insert before add button
        const parent = this.addButton.parentNode;
        parent.insertBefore(clone, this.addButton);

        // Get the newly added item (last deck-item before the add button)
        const deckItems = parent.querySelectorAll(`.${this.fieldClass}`);
        const itemElement = deckItems[deckItems.length - 1];

        // Focus on the edit button to open the dialog
        const editButton = itemElement.querySelector("button.edit");
        if (editButton) {
            // Small delay to allow DOM to settle
            setTimeout(() => editButton.click(), 100);
        }

        // Initialize the new item
        this.newItem(itemElement);
		this.changed();
        this.updateAddButton();
    }



    newItem(itemElement) {
        const newItem = new DeckItem(itemElement, this.fieldClass, this);
		this.items.push(newItem);
        this.initActionbar(itemElement);
        this.form?.processFields();

		return newItem;
    }

    initActionbar(itemElement) {
        const trash = itemElement.querySelector("button.trash");
        const duplicate = itemElement.querySelector("button.duplicate");

        trash?.addEventListener("click", () => this.removeItem(itemElement));
        duplicate?.addEventListener("click", () => this.duplicateItem(itemElement));
    }

    removeItem(itemElement) {
        // Call destroy method to clean up observers before removing
        if (itemElement.deckitem && typeof itemElement.deckitem.destroy === 'function') {
            itemElement.deckitem.destroy();
        }
        itemElement.remove();
		this.items = this.items.filter(item => item.container !== itemElement);
		this.changed();
        this.updateAddButton();
    }

    duplicateItem(itemElement) {
        if (this.maxItems > -1 && this.items.length >= this.maxItems) return;

        // Clone the item element
        const clone = itemElement.cloneNode(true);
        this.regenerateIds(clone);

        // Clear the dialog ID field
        const dialogIdInput = clone.querySelector("dialog input[name='id']");
        if (dialogIdInput) {
            dialogIdInput.value = '';
            dialogIdInput.removeAttribute('readonly');
            dialogIdInput.removeAttribute('disabled');

            // Remove any locked/disabled state from the field container
            const dialogIdContainer = dialogIdInput.closest('.form-field');
            if (dialogIdContainer) {
                dialogIdContainer.classList.remove('locked');
            }
        }

		// Remove cloned Tiptap editor containers so they reinitialize fresh
		const clonedEditors = clone.querySelectorAll('.ste-editor-container');
		clonedEditors.forEach(editor => editor.remove());
		// Clear initialization flags so fields re-init
		clone.querySelectorAll('[data-ste-initialized]').forEach(el => {
			delete el.dataset.steInitialized;
		});

		// Insert after the original item
        const parent = itemElement.parentNode;
        parent.insertBefore(clone, itemElement.nextSibling);

        // Initialize the duplicated item (this will properly initialize all form fields)
        this.newItem(clone);

        // Open the dialog to edit the duplicated item
        const editButton = clone.querySelector("button.edit");
        if (editButton) {
            setTimeout(() => editButton.click(), 100);
        }

		this.changed();
        this.updateAddButton();
    }

    getValue() {
        const deckData = {};

		// not using this.items so we can maintain the order of items in the DOM
		const deckItems = this.container.getElementsByClassName(this.fieldClass);
        for (const item of deckItems) {
			if (!item.deckitem) continue;
			// Ensure deck IDs are always strings, even if they're numeric
			const itemId = String(item.deckitem.getItemId());
			deckData[itemId] = item.deckitem.getValue();
        }

        // Ensure we always return an object, never an empty array
        // This prevents PHP JSON decoding from converting {} to []
        return Object.keys(deckData).length === 0 ? {} : deckData;
    }

    setValue(value) {
		console.warn("DeckField.setValue() is not implemented", value);
    }

    clearValue() {
        this.items.forEach(item => item.destroy());
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

	error(message) {
		this.input.setCustomValidity(message);
		super.error(message);
	}

	changed() {
		// Clear custom validity when deck content changes
		this.input.setCustomValidity("");
		super.changed();
	}

    validate() {
		this.input.setCustomValidity(""); // Clear previous custom validity message

		if (!this.isVisible()) return true;

		let isValid = true;

        // Check if deck is required and empty
        if (this.input.required) {
            const deckItems = this.container.getElementsByClassName(this.fieldClass);
            if (deckItems.length === 0) {
                const errorMessage = "Please add at least one.";
                this.input.setCustomValidity(errorMessage);
                this.input.reportValidity();
                this.error(errorMessage);
                isValid = false;
                this.valid = isValid;
                return this.valid;
            }
        }

        // Check minimum item count
        if (this.minItems > 0 && this.items.length < this.minItems) {
            const errorMessage = `Please add at least ${this.minItems} items`;
            this.input.setCustomValidity(errorMessage);
            this.input.reportValidity();
            this.error(errorMessage);
            this.valid = false;
            return this.valid;
        }

        // Check maximum item count
        if (this.maxItems > -1 && this.items.length > this.maxItems) {
            const errorMessage = `Maximum ${this.maxItems} items allowed`;
            this.input.setCustomValidity(errorMessage);
            this.input.reportValidity();
            this.error(errorMessage);
            this.valid = false;
            return this.valid;
        }

        const itemIds = [];

        // Count occurrences of each ID and track items
        this.items.forEach(item => {
            const itemId = item.getItemId();

			if (itemId.length == 0) {
				const errorMessage = "Item ID cannot be empty";
				this.error(errorMessage);
				isValid = false;
				return; // Skip this item for duplicate checking
			}

			if (itemIds.includes(itemId)) {
				const errorMessage = `Duplicate ID found: ${itemId}`;
				this.error(errorMessage);
				item.error(errorMessage);
				isValid = false;
				return; // Skip this item for duplicate checking
			}

            itemIds.push(itemId);

			// Validate fields inside this deck item
			if (!item.validate()) {
				isValid = false;
			}
        });

		this.valid = isValid;

        return this.valid;
    }

    schema() {
        return {
            type: "deck",
            fieldset: this.type,
            schemaref: this.schemaref
        };
    }
}