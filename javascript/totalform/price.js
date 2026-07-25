import IMask from 'imask';
import TotalField from './totalfield';

//-----------------------------------------------
// Total CMS Price Field
// Text input + locale-aware currency mask (imask), value stored as a number.
// Currency, decimals, and the currency icon are resolved server-side
// (PriceField.php) and arrive via data-settings. This class only configures the
// imask Number mask: it derives the thousands/decimal separators from the
// browser-native Intl.NumberFormat and feeds them to imask.
//-----------------------------------------------
export default class PriceField extends TotalField {

	constructor(container, settings) {
		// super() calls getValue() (guarded below) and changeListener() before
		// the mask exists, so this.mask must be created first.
		super(container, settings);

		const s = this.settings || {};

		this.locale   = s.locale || navigator.language || 'en-US';
		this.currency = String(s.currency || 'USD').toUpperCase();
		this.decimals = (s.decimals === 0 || s.decimals)
			? Number(s.decimals)
			: PriceField.standardDecimals(this.locale, this.currency);

		const sep = PriceField.separators(this.locale, this.currency);

		// signed + no min: balances, credits, and adjustments can be negative.
		// An unsigned mask silently clamps a negative server value to positive
		// (display AND getValue()), which then persists the wrong sign on save.
		this.mask = IMask(this.input, {
			mask: Number,
			scale: this.decimals,
			signed: true,
			thousandsSeparator: sep.group,
			radix: sep.decimal,
			mapToRadix: ['.', ','],
			normalizeZeros: true,
			padFractionalZeros: this.decimals > 0,
		});

		// Seed from the existing server value (the raw number lives in the `value`
		// attribute; imask only mutates the .value property). Set it directly
		// rather than via setValue() so we don't fire changed() during load.
		const raw = this.input.getAttribute('value') || '';
		if (raw !== '') {
			const n = parseFloat(raw);
			this.mask.typedValue = isNaN(n) ? '' : n;
		}

		// Re-baseline change tracking: super() captured storedValue while
		// this.mask was undefined (so getValue() returned ''), and formatting the
		// initial value above would otherwise read as a user edit. Reset the
		// baseline to the formatted value and clear any unsaved flag a load-time
		// input/accept event may have set — only THEN wire change detection so the
		// page-load format never marks the field dirty.
		this.storedValue = this.getValue();
		this.saved();

		this.mask.on('accept', () => this.changed());
	}

	// Returns the stored value as a Number (or '' when empty).
	// NOTE: called by super() before this.mask is set — guard accordingly.
	getValue() {
		if (!this.mask || this.mask.unmaskedValue === '') return '';
		return this.mask.typedValue; // imask returns a Number for the Number mask
	}

	setValue(value) {
		const n = parseFloat(value);
		if (this.mask) {
			this.mask.typedValue = isNaN(n) ? '' : n;
		}
		this.changed();
	}

	// Thousands + decimal separators for a locale + currency, via Intl.
	static separators(locale, currency) {
		try {
			const parts = new Intl.NumberFormat(locale, { style: 'currency', currency })
				.formatToParts(11111.11);
			const get = (t) => (parts.find(p => p.type === t) || {}).value || '';
			return { group: get('group') || ',', decimal: get('decimal') || '.' };
		} catch {
			return { group: ',', decimal: '.' };
		}
	}

	// Fallback only — PHP normally supplies decimals via data-settings.
	static standardDecimals(locale, currency) {
		try {
			return new Intl.NumberFormat(locale, { style: 'currency', currency })
				.resolvedOptions().maximumFractionDigits;
		} catch {
			return 2;
		}
	}

	schema() {
		return { type: 'number', field: 'price' };
	}
}
