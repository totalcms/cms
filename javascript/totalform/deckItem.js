import Dialog from "./dialog";

//-----------------------------------------------
// Total CMS Deck Item
//-----------------------------------------------
export default class DeckItem {

    constructor(container, fieldClass) {
        this.container = container;
        this.container.totalfield = this;
        this.fieldClass = fieldClass;
        this.deckref = '';

        this.dialog = this.setupDialog();
    }

    setupDialog() {
        return new Dialog(this.container.querySelector("dialog"), {
            open: this.container.querySelector("button.edit"),
            close: ".close",
            onOpen: () => {
                if (this.dialogOpened) return;
                this.dialogOpened = true;
                // Process any form fields in the dialog
                if (this.form) {
                    this.form.processFields();
                }
            },
            onClose: () => {
                this.dialogOpened = false;
                this.updateItemData();
            }
        });
    }

    updateItemData() {
        // Update any visual indicators based on dialog content
        // This could show if the item has content, validation status, etc.
        const itemId = this.getItemId();
        const titleField = this.dialog.dialog.querySelector(`input[name="deck-${itemId}-title"]`);
        
        if (titleField && titleField.value.trim()) {
            this.container.classList.add('has-content');
        } else {
            this.container.classList.remove('has-content');
        }
    }

    getItemId() {
        const idInput = this.container.querySelector("input[name='deck-item-id']");
        return idInput ? idInput.value : this.container.getAttribute('data-item-id') || '';
    }

    getName() {
        return this.getItemId();
    }

    getValue() {
        const itemId = this.getItemId();
        if (!itemId) return null;

        const data = {};
        
        // Collect all form fields within this item's dialog
        const formFields = this.dialog.dialog.querySelectorAll('input, textarea, select');
        
        for (const field of formFields) {
            if (field.name && field.name.startsWith(`deck-${itemId}-`)) {
                const fieldName = field.name.replace(`deck-${itemId}-`, '');
                
                // Handle different field types
                if (field.type === 'checkbox') {
                    data[fieldName] = field.checked;
                } else if (field.type === 'radio') {
                    if (field.checked) {
                        data[fieldName] = field.value;
                    }
                } else if (field.tagName === 'SELECT' && field.multiple) {
                    data[fieldName] = Array.from(field.selectedOptions).map(option => option.value);
                } else {
                    data[fieldName] = field.value;
                }
            }
        }

        return Object.keys(data).length > 0 ? data : null;
    }

    setValue(value) {
        if (!value || typeof value !== 'object') return;

        const itemId = this.getItemId();
        
        // Set values for all fields in the dialog
        for (const [fieldName, fieldValue] of Object.entries(value)) {
            const fieldSelector = `[name="deck-${itemId}-${fieldName}"]`;
            const field = this.dialog.dialog.querySelector(fieldSelector);
            
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

        // Update visual indicators
        this.updateItemData();
    }

    clearValue() {
        const itemId = this.getItemId();
        const formFields = this.dialog.dialog.querySelectorAll('input, textarea, select');
        
        for (const field of formFields) {
            if (field.name && field.name.startsWith(`deck-${itemId}-`)) {
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

        this.container.classList.remove('has-content');
    }

    isUnsaved() {
        return this.container.classList.contains("unsaved");
    }

    saved() {
        this.container.classList.remove("unsaved");
    }
}