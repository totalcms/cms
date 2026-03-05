//-----------------------------------------------
// Generic Autogen Helper
// Provides pattern-based value generation for any field.
// Used by TotalField for generic autogen and by Identifier for ID-specific autogen.
//-----------------------------------------------
export default class Autogen {

	constructor(field) {
		this.field = field;
		this.pattern = field.settings.autogen;
	}

	/**
	 * Parse the autogen pattern and return the referenced field names
	 * (excluding reserved names).
	 */
	getFieldNames() {
		const reservedNames = [
			"now", "timestamp", "uuid", "uid", "id", "oid",
			"currentyear", "currentyear2", "currentmonth", "currentday"
		];
		return (this.pattern.match(/\${(.*?)}/g) || [])
			.map(v => v.slice(2, -1))
			.filter(name => !reservedNames.includes(name) && !name.startsWith('oid-'));
	}

	/**
	 * Attach change listeners to referenced fields so autogen
	 * re-runs when dependencies change.
	 */
	attachListeners(callback) {
		const fieldNames = this.getFieldNames();
		const searchScope = this.field.isInDeck ? this.field.deckItem : this.field.form.form;

		fieldNames.forEach(name => {
			const input = searchScope.querySelector(`[name="${name}"]`);
			if (!input) return;
			input.addEventListener("change", () => callback());
		});
	}

	/**
	 * Generate a value from the autogen pattern using current form data.
	 * Does NOT slugify - returns the raw interpolated string.
	 */
	generate() {
		let data = this.collectFormData();
		data = this.addSpecialVariables(data);

		return this.pattern.replace(/\${(.*?)}/g, (match, key) => {
			// Handle oid with zero-padding: oid-00000
			if (key.startsWith('oid-') && /^oid-0+$/.test(key)) {
				const paddingLength = key.substring(4).length;
				const oidValue = this.getCollectionCount();
				return oidValue.toString().padStart(paddingLength, '0');
			}
			return data[key] ?? "";
		});
	}

	/**
	 * Collect current form data from the appropriate scope.
	 */
	collectFormData() {
		if (this.field.isInDeck) {
			const data = {};
			const fields = this.field.deckItem.querySelectorAll('input, textarea, select');
			fields.forEach(field => data[field.name] = field.value);
			return data;
		}

		// Get the field data from the form
		let data = this.field.form.generateData();
		// Filter to only string and number values
		return Object.entries(data).reduce((acc, [key, value]) => {
			if (typeof value === 'string') {
				acc[key] = value;
			} else if (typeof value === 'number') {
				acc[key] = String(value);
			}
			return acc;
		}, {});
	}

	/**
	 * Add special autogen variables (timestamps, uuid, etc.).
	 */
	addSpecialVariables(data) {
		const now = new Date();
		data.now       = Date.now();
		data.timestamp = now.toISOString().slice(0, -5).replace(/-|:/g, '');
		data.uuid      = this.generateUuid();
		data.uid       = Math.random().toString(36).substring(2, 9);
		data.oid       = this.getCollectionCount();

		data.currentyear  = now.getFullYear().toString();
		data.currentyear2 = now.getFullYear().toString().slice(-2);
		data.currentmonth = String(now.getMonth() + 1).padStart(2, '0');
		data.currentday   = String(now.getDate()).padStart(2, '0');

		return data;
	}

	getCollectionCount() {
		const count = this.field.form.form.getAttribute('data-collection-count');
		return count ? parseInt(count, 10) + 1 : 1;
	}

	generateUuid() {
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
			const r = Math.random() * 16 | 0;
			const v = c == 'x' ? r : (r & 0x3 | 0x8);
			return v.toString(16);
		});
	}
}
