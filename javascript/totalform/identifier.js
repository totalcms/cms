import TotalField from './totalfield';
const slugify = require('slugify')

//-----------------------------------------------
// Total CMS ID Field Automation
//-----------------------------------------------
export default class Identifier extends TotalField {

    constructor(container, options) {
        // Define option defaults
        const defaults = {
            autogen : false,
        };
        options = Object.assign({}, defaults, options);

		super(container, options);

		this.valid = false;

		// Check if we're editing an existing item (form has an ID)
		if (
			(this.form.id && this.form.id.length > 0 && !this.isInDeck) ||
			(this.getValue().length > 0 && this.isInDeck)
		) {
			// The ID cannot be changed when editing
			this.disable();
			this.valid = true; // ID is valid in edit mode since it can't be changed
		}
		if (this.getValue() === "" && this.options.autogen) {
			this.setValue(this.autogenId());
		}
    }

	changed() {
		this.form.setId(this.getValue());
		// don't trigger change events for ID field
		// turning this on will cause infinite event loops
		return;
	}

	// Override TotalField.changeListener
	changeListener() {
		if (this.options.autogen && !this.isLocked()) {
			// autogen example: ${title}-${timestamp}
			const autogenNames = this.options.autogen.match(/\${(.*?)}/g).map(v => v.slice(2, -1));
			const reservedNames = ["now", "timestamp", "uuid", "uid", "id", "oid"];
			autogenNames.forEach(name => {
				// Skip reserved names and oid patterns (oid, oid-00000, etc.)
				if (reservedNames.includes(name) || name.startsWith('oid-')) return;

				// Determine the scope to search for fields
				const searchScope = this.isInDeck ? this.deckItem : this.form.form;

				// Only listen to the fields that are used in the autogen string
				const field = searchScope.querySelector(`[name="${name}"]`);
				if (!field) return; // Skip if the field does not exist

				field.addEventListener("change", e => {
					if (this.isLocked()) return;
					this.setValue(this.autogenId());
					this.validateIdExists();
				});
			});
		}
        // Check ID changes directly
        this.input.addEventListener("input",  e => this.lock(), {once: true});
		this.input.addEventListener("change", e => this.validateIdExists());
	}

	autogenId() {
		let data = {};

		if (this.isInDeck) {
			// Get field data from within the deck-item scope
			const fields = this.deckItem.querySelectorAll('input, textarea, select');
			fields.forEach(field => data[field.name] = field.value);
		} else {
			// Get the field data from the form (original behavior)
			data = this.form.generateData();
			// Filter out non-string values from data
			data = Object.entries(data).reduce((acc, [key, value]) => {
				if (typeof value === 'string') {
					acc[key] = value;
				}
				return acc;
			}, {});
		}

		// Add some default data
		data.now       = Date.now();
		data.timestamp = new Date().toISOString().slice(0, -5).replace(/-|:/g, '');
		data.uuid      = this.generateUuid();
		data.uid       = Math.random().toString(36).substring(2,9);
		data.oid       = this.getCollectionCount();

		// Examples: ${title}-${timestamp}, ${title}-${now}, ${title}-${uuid}, ${title}-${oid}, ${title}-${oid-00000}

		// Magic happens here - enhanced to handle oid zero-padding
		const autogen = this.options.autogen.replace(/\${(.*?)}/g, (match, key) => {
			// Check if this is an oid with zero-padding format: oid-00000
			if (key.startsWith('oid-') && /^oid-0+$/.test(key)) {
				const zeros = key.substring(4); // Get the zero pattern (e.g., "00000")
				const paddingLength = zeros.length;
				const oidValue = this.getCollectionCount();
				return oidValue.toString().padStart(paddingLength, '0');
			}
			
			// Standard replacement for all other placeholders
			return data[key] || "";
		});
		return this.slugify(autogen);
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
		id = id.replace('@', '-at-').replace('.', '-');
        return slugify(id, {
			replacement : '-', // replace spaces with replacement character, defaults to `-`
			remove      : /[*+~.()'"!:@]/g, // remove characters that match regex, defaults to `undefined`
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
		// For deck items with empty IDs, this might be acceptable if they're new
		if (this.isInDeck && this.getValue() !== "") {
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

        this.api.existsAPI(api).then(response => {
			response.ok ? this.idExists() : this.idAvailable();
		});
    }
}

