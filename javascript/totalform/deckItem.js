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
		setTimeout(() => this.setupIdSync(), 0);
    }

    setupDialog() {
        return new Dialog(this.container.querySelector("dialog"), {
            open: this.container.querySelector("button.edit"),
            close: ".close",
            onOpen: () => {
                if (this.dialogOpened) return;
                this.dialogOpened = true;
            },
            onClose: () => {
                this.dialogOpened = false;
            }
        });
    }

    setupIdSync() {
        const deckItemIdField = this.container.querySelector("input[name='deck-item-id']");
        const dialogIdField = this.dialog.dialog.querySelector("input[name='id']");

        if (!deckItemIdField || !dialogIdField) return;

        // Skip sync if deck-item-id field is readonly (existing items)
        if (deckItemIdField.hasAttribute('readonly') || deckItemIdField.hasAttribute('disabled')) {
            return;
        }

        // Flag to prevent infinite loops during synchronization
        let syncing = false;

        // Helper function to sanitize ID values (replace hyphens with underscores)
        const sanitizeId = (value) => {
            return value.replace(/-/g, '_');
        };

        // Use MutationObserver to detect all value changes (programmatic and user input)
        const syncFromDeckItem = () => {
            const sanitizedValue = sanitizeId(deckItemIdField.value);
            if (syncing || dialogIdField.value === sanitizedValue) return;
            syncing = true;
            // Update the deck item field with sanitized value if needed
            if (deckItemIdField.value !== sanitizedValue) {
                deckItemIdField.value = sanitizedValue;
            }
            dialogIdField.value = sanitizedValue;
            syncing = false;
        };

        const syncFromDialog = () => {
            const sanitizedValue = sanitizeId(dialogIdField.value);
            if (syncing || deckItemIdField.value === sanitizedValue) return;
            syncing = true;
            // Update the dialog field with sanitized value if needed
            if (dialogIdField.value !== sanitizedValue) {
                dialogIdField.value = sanitizedValue;
            }
            deckItemIdField.value = sanitizedValue;
            syncing = false;
        };

        // Store current values to detect property changes
        let lastDeckItemValue = deckItemIdField.value;
        let lastDialogValue = dialogIdField.value;

        // Observe changes to the deck-item-id field
        const deckItemObserver = new MutationObserver(() => {
            // Check if the value property has changed (not just the attribute)
            if (deckItemIdField.value !== lastDeckItemValue) {
                lastDeckItemValue = deckItemIdField.value;
                syncFromDeckItem();
            }
        });

        // Observe changes to the dialog id field
        const dialogObserver = new MutationObserver(() => {
            // Check if the value property has changed (not just the attribute)
            if (dialogIdField.value !== lastDialogValue) {
                lastDialogValue = dialogIdField.value;
                syncFromDialog();
            }
        });

        // Configure observers to watch for any changes to the elements
        const observerConfig = {
            attributes: true,
            attributeOldValue: true,
            childList: true,
            subtree: true
        };

        deckItemObserver.observe(deckItemIdField, observerConfig);
        dialogObserver.observe(dialogIdField, observerConfig);

        // Also listen for various events that might indicate value changes
        const events = ['input', 'change', 'paste', 'keyup', 'focus', 'blur'];
        events.forEach(eventType => {
            deckItemIdField.addEventListener(eventType, () => {
                if (deckItemIdField.value !== lastDeckItemValue) {
                    lastDeckItemValue = deckItemIdField.value;
                    syncFromDeckItem();
                }
            });
            dialogIdField.addEventListener(eventType, () => {
                if (dialogIdField.value !== lastDialogValue) {
                    lastDialogValue = dialogIdField.value;
                    syncFromDialog();
                }
            });
        });

        // Periodic check for programmatic changes (fallback)
        const syncChecker = setInterval(() => {
            if (deckItemIdField.value !== lastDeckItemValue) {
                lastDeckItemValue = deckItemIdField.value;
                syncFromDeckItem();
            }
            if (dialogIdField.value !== lastDialogValue) {
                lastDialogValue = dialogIdField.value;
                syncFromDialog();
            }
        }, 100);

        // Store references for cleanup
        this.idSyncData = {
            lastDeckItemValue,
            lastDialogValue,
            syncChecker
        };

        // Store observers for cleanup if needed
        this.idSyncObservers = {
            deckItemObserver,
            dialogObserver
        };
    }


    getItemId() {
        const idInput = this.container.querySelector("input[name='deck-item-id']");
        return idInput ? idInput.value : this.container.getAttribute('data-item-id') || '';
    }

    getName() {
        return this.getItemId();
    }

    getValue() {
        const data = {};

        // Collect all form fields within this item's dialog
        const formFields = this.dialog.dialog.querySelectorAll('input, textarea, select');

        for (const field of formFields) {
            if (field.name) {
                // Handle different field types
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

    // Cleanup method to disconnect observers when deck item is destroyed
    destroy() {
        if (this.idSyncObservers) {
            this.idSyncObservers.deckItemObserver?.disconnect();
            this.idSyncObservers.dialogObserver?.disconnect();
            this.idSyncObservers = null;
        }

        if (this.idSyncData?.syncChecker) {
            clearInterval(this.idSyncData.syncChecker);
            this.idSyncData = null;
        }
    }

    isUnsaved() {
        return this.container.classList.contains("unsaved");
    }

    saved() {
        this.container.classList.remove("unsaved");
    }
}