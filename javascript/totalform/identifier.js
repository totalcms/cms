import TotalField from './totalfield';
import Autogen from './autogen';
const slugify = require('slugify')

//-----------------------------------------------
// Total CMS ID Field Automation
//-----------------------------------------------
export default class Identifier extends TotalField {

    constructor(container, settings) {
        // Define option defaults
        const defaults = {
            autogen : false,
        };
        settings = Object.assign({}, defaults, settings);

		super(container, settings);

		this.valid = false;

		if (this.settings.autogen) {
			this.autogen = new Autogen(this);
		}

		// Only the object-identity field (property "id") locks when editing an
		// existing record. Other id-type fields on the same form (e.g. user_id)
		// share this class but are ordinary, always-editable properties — they
		// must never be disabled just because the object has an id, or because
		// the id field autogenned one (autogen also sets form.id).
		//
		// "Editing an existing object" = the FORM has an id (data-id). That is
		// blank for new objects AND duplicates — even with keepIdOnDuplicate,
		// where the id field is pre-filled but the form id stays blank — so a
		// duplicate's id remains editable. Deck items have no form id, so an
		// existing deck item is detected by its own saved value (new items start
		// empty / autogen, duplicates are cleared before init). Locking stops
		// autogen from moving the record's saved image/file storage when a
		// referenced field changes; readonly is deliberately NOT the trigger so a
		// readonly-but-autogen id still updates.
		const isExistingObject   = this.form.id && this.form.id.length > 0 && !this.isInDeck && !this.form.isTemplateForm();
		const isExistingDeckItem = this.isInDeck && this.getValue() !== "";

		if (this.property === "id" && (isExistingObject || isExistingDeckItem)) {
			// The ID cannot be changed when editing (except templates which support rename/move)
			this.disable();
			this.valid = true; // ID is valid in edit mode since it can't be changed
		}
		if (this.getValue() !== "" && !this.isLocked() && !this.form.isTemplateForm()) {
			this.validateIdExists();
		}
		if (this.settings.autogen && !this.isLocked() && this.getValue() === "") {
			this.setValue(this.autogenId());
			this.validateIdExists();
		}
    }

	changed() {
		// Only the identity field (property "id") drives the form's id. Deck
		// items have their own IDs, and other id-type fields (user_id, …) are
		// ordinary properties that must not overwrite the form id.
		if (!this.isInDeck && this.property === "id") {
			this.form.setId(this.getValue());
		}
		// don't trigger change events for ID field
		// turning this on will cause infinite event loops
		return;
	}

	// Override TotalField.changeListener
	changeListener() {
		if (this.settings.autogen && !this.isLocked()) {
			this.autogen = this.autogen || new Autogen(this);
			this.autogen.attachListeners(() => {
				if (this.isLocked()) return;
				this.setValue(this.autogenId());
				this.validateIdExists();
			});
		}
        // Check ID changes directly
        this.input.addEventListener("input",  e => this.lock(), {once: true});
		this.input.addEventListener("change", e => this.validateIdExists());

		// Re-validate when category changes (template forms only)
		if (this.form.isTemplateForm()) {
			const categoryField = this.form.form.querySelector('select[name="category"]');
			if (categoryField) {
				categoryField.addEventListener("change", () => this.validateIdExists());
			}
		}
	}

	autogenId() {
		const raw = this.autogen.generate();
		const slugified = this.slugify(raw);

		// For deck context OR schemas opting into snake_case (e.g. mcp-prompt),
		// replace hyphens with underscores so the client-side preview matches the
		// server-side save (ObjectFactory honours id.settings.snakeCase too).
		return (this.isInDeck || this.settings.snakeCase) ? slugified.replace(/-/g, '_') : slugified;
	}

	getCollectionCount() {
		// Get the collection count from the form's data-collection-count attribute
		// This represents the next OID that should be used for new objects
		const count = this.form.form.getAttribute('data-collection-count');
		return count ? parseInt(count, 10) + 1 : 1;
	}

	generateUuid() {
		// Generate UUID v4 (random) - RFC 4122 compliant
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
			const r = Math.random() * 16 | 0;
			const v = c == 'x' ? r : (r & 0x3 | 0x8);
			return v.toString(16);
		});
	}

	disable() {
		this.lock();
		return this.input.setAttribute("disabled", true);
	}

	lock() {
		return this.container.classList.add("locked");
	}

	isLocked() {
		return this.container.classList.contains("locked") || this.input.hasAttribute("disabled");
	}

    slugify(id) {
		// Schemas may opt into underscore separators via settings.snakeCase
		// (used by mcp-prompt, parallels server-side ObjectFactory behaviour).
		const sep = this.settings.snakeCase ? '_' : '-';
		id = id.replace('@', `${sep}at${sep}`).replace(/\./g, sep);

		// Build the remove regex, allowing custom characters if specified
		let removeRegex = /[*+~.,()?'"!:@{}\[\]\/\\]/g;
		if (this.settings.allowCharacters) {
			// Escape special regex characters in the allowed string
			const escaped = this.settings.allowCharacters.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
			// Remove allowed characters from the default remove pattern
			const pattern = `[*+~.()'"!:@{}\\[\\]\\\\${escaped.includes('/') ? '' : '/'}]`;
			removeRegex = new RegExp(pattern, 'g');
		}

        return slugify(id, {
			replacement : sep, // replace spaces with replacement character
			remove      : removeRegex, // remove characters that match regex, defaults to `undefined`
			lower       : true, // convert to lower case, defaults to `false`
			strict      : false, // strip special characters except replacement, defaults to `false`
			trim        : true, // trim leading and trailing replacement chars, defaults to `true`
			// locale      : 'vi', // language code of the locale to use
		});
    }

    idExists() {
		this.valid = false;
		this.form.setId("");
		console.warn("ID already exists: "+this.getValue());
        this.container.classList.remove("unsaved");
        this.container.classList.add("error");
		this.input.setCustomValidity("ID already exists");
    }

    idAvailable() {
		this.valid = true;
		this.form.setId(this.getValue());
		this.form.unsaved();
        this.container.classList.remove("error");
        this.container.classList.add("unsaved");
		this.input.setCustomValidity("");
    }

	updateNonIDProperty() {
		this.valid = true;
		this.form.unsaved();
    }

	validate() {
		if (!this.isVisible()) return true;

		// For deck items with empty IDs, this might be acceptable if they're new
		if (this.isInDeck && this.getValue() !== "") {
			this.valid = true;
		}

		// If ID has a value and no custom validity error, assume valid
		// (handles race condition where validateIdExists API call hasn't responded yet)
		if (this.getValue() !== "" && this.input.validationMessage === "") {
			this.valid = true;
		}

		if (this.valid && this.input.checkValidity()) {
			return true;
		}

		this.error(this.input.validationMessage);
		return false;
	}

    validateIdExists() {
		// slugify the value to ensure it's a valid ID
		const id = this.slugify(this.getValue());
		if (!id) return;

		// Set the slugified value
		this.setValue(id);

		// If the property is not ID, do not check for existence
		// This is for when you use and ID field on a non-ID property
		if (this.property !== "id") {
			this.updateNonIDProperty();
			return;
		}

		// If we are in a deck item, we don't need to check for ID existence
		if (this.isInDeck) return;

		let api = `/collections/${this.form.collection}/${id}`;

		if (this.form.isCollectionForm()) {
			api = `/collections/${id}`;
		}
		if (this.form.isSchemaForm()) {
			api = `/schemas/${id}`;
		}
		if (this.form.isTemplateForm()) {
			const categoryField = this.form.form.querySelector('select[name="category"]');
			const category = categoryField ? categoryField.value : '';
			api = category ? `/templates/${category}/${id}` : `/templates/${id}`;
		}

        this.api.existsAPI(api).then(response => {
			// Handle network errors or undefined response gracefully
			if (!response) {
				this.idAvailable(); // Assume available on network error
				return;
			}

			// If editing a template and the path matches the original, it's the same file
			if (response.ok && this.form.isTemplateEditMode() && this.form.route === api) {
				this.idAvailable();
				return;
			}

			response.ok ? this.idExists() : this.idAvailable();
		});
    }
}
