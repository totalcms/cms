import TotalField from "./totalfield";
import DeckItem from "./deckItem";
import Sortable from 'sortablejs';
const slugify = require('slugify');

//-----------------------------------------------
// Total CMS Deck Field
//-----------------------------------------------
export default class DeckField extends TotalField {

    constructor(container, options) {
        super(container, options);

        this.fieldClass = "deck-item";
        this.deckref = container.dataset.deckref || '';

		this.items = [];
		this.valid = true;

        // Initialize existing deck items
        const deckItems = this.container.getElementsByClassName(this.fieldClass);
        for (const item of deckItems) {
            this.newItem(item);
        }
        this.sortableDeckItems(deckItems);

        // Get template for new items
        this.template = this.container.querySelector("template.deck-template");

        // Setup add button
        this.addButton = this.container.querySelector(".cms-add");
        this.addButton?.addEventListener("click", this.addItem.bind(this));
    }

    sortableDeckItems(deckItems) {
        if (deckItems.length === 0) return;
        // Make the items sortable
        Sortable.create(deckItems[0].parentNode, {
            animation: 150,
            ghostClass: 'drag-ghost',
        });
    }

    addItem() {
        if (!this.template) {
            console.error('No deck template found');
            return;
        }

        // Clone the template
        const clone = this.template.content.cloneNode(true);

        // Insert before add button
        const parent = this.addButton.parentNode;
        parent.insertBefore(clone, this.addButton);

        // Get the newly added item (last deck-item before the add button)
        const deckItems = parent.querySelectorAll(`.${this.fieldClass}`);
        const itemElement = deckItems[deckItems.length - 1];

        // Clear the ID field and focus on it
        const idInput = itemElement.querySelector("input[name='deck-item-id']");
        if (idInput) {
            idInput.value = '';
            idInput.focus();
        }

        // Initialize the new item
        this.newItem(itemElement);
		this.changed();
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
        const idInput = itemElement.querySelector("input[name='deck-item-id']");

        // Ensure item IDs are properly formatted
        idInput?.addEventListener("change", () => {
            // Replace hyphens with underscores and ensure lowercase
            const oldValue = idInput.value;
            const newValue = slugify(oldValue, { lower: true }).replace(/-/g, "_");

            if (oldValue !== newValue) {
                idInput.value = newValue;

                // Update the item's data-item-id attribute
                itemElement.setAttribute('data-item-id', newValue);
                itemElement.className = itemElement.className.replace(/deck-item-\S+/, `deck-item-${newValue}`);

                // Update button titles
                const editBtn = itemElement.querySelector("button.edit");
                const duplicateBtn = itemElement.querySelector("button.duplicate");
                const trashBtn = itemElement.querySelector("button.trash");

                if (editBtn) editBtn.setAttribute("title", `Edit ${newValue} item`);
                if (duplicateBtn) duplicateBtn.setAttribute("title", `Duplicate ${newValue} item`);
                if (trashBtn) trashBtn.setAttribute("title", `Delete ${newValue} item`);
            }
        });

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
    }

    duplicateItem(itemElement) {
        // Clone the item element
        const clone = itemElement.cloneNode(true);

        // Update the ID input - clear it for new item and make it editable
        const idInput = clone.querySelector("input[name='deck-item-id']");
        if (idInput) {
            idInput.value = ''; // Clear the ID for new item
            idInput.removeAttribute('readonly'); // Make it editable again
            idInput.removeAttribute('disabled'); // Remove any disabled state
        }

        // Also clear the dialog ID field
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

		const clonedFroala = clone.querySelectorAll('.fr-box');
		clonedFroala.forEach(froala => froala.remove());

		// Insert after the original item
        const parent = itemElement.parentNode;
        parent.insertBefore(clone, itemElement.nextSibling);

        // Focus on the new item's ID field
        const newIdInput = clone.querySelector("input[name='deck-item-id']");
        newIdInput?.focus();

        // Initialize the duplicated item (this will properly initialize all form fields)
        this.newItem(clone);
		this.changed();
    }

    getValue() {
        const deckData = {};

		// not using this.items so we can maintain the order of items in the DOM
		const deckItems = this.container.getElementsByClassName(this.fieldClass);
        for (const item of deckItems) {
			deckData[item.deckitem.getItemId()] = item.deckitem.getValue();
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

    validate() {
		this.input.setCustomValidity(""); // Clear previous custom validity message

		let   isValid = true;
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
        });

		this.valid = isValid;

        return this.valid;
    }

    schema() {
        return {
            type: "deck",
            fieldset: this.type,
            deckref: this.deckref
        };
    }
}