import TotalField from "./totalfield.js";

//-----------------------------------------------
// Total CMS Localized Text Field (Pro)
//
// Tab-per-locale interface. One delegated click handler toggles `hidden` on
// panes and `aria-selected` on tabs. getValue() returns a locale-keyed dict
// of all inputs (hidden or not); setValue(dict) distributes values to each
// locale's input.
//-----------------------------------------------
export default class LocalizedTextField extends TotalField {

	constructor(container, settings) {
		super(container, settings);

		// All per-locale inputs in document order (hidden + visible).
		this.localeInputs = Array.from(
			this.container.querySelectorAll('input[data-locale], textarea[data-locale]')
		);

		// Surface changed() on EACH input — base class only listens on the
		// first one, but edits in any tab should mark the form unsaved.
		this.localeInputs.forEach(input => {
			input.addEventListener('input', () => this.changed());
			input.addEventListener('change', () => this.changed());
		});

		this.bindTabSwitching();

		// Refresh stored value now that getValue() can read all locales.
		this.storedValue = JSON.stringify(this.getValue());
	}

	bindTabSwitching() {
		this.container.addEventListener('click', e => {
			const tab = e.target.closest('[data-locale-tab]');
			if (!tab || !this.container.contains(tab)) return;
			e.preventDefault();
			this.switchToLocale(tab.dataset.localeTab);
		});

		// Arrow-key navigation between tabs (Left/Right). Per WAI-ARIA
		// tablist pattern. Keeps the field usable without a mouse.
		this.container.addEventListener('keydown', e => {
			const tab = e.target.closest('[data-locale-tab]');
			if (!tab || !this.container.contains(tab)) return;
			if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
			e.preventDefault();

			const tabs = Array.from(this.container.querySelectorAll('[data-locale-tab]'));
			const idx  = tabs.indexOf(tab);
			const next = e.key === 'ArrowRight'
				? tabs[(idx + 1) % tabs.length]
				: tabs[(idx - 1 + tabs.length) % tabs.length];
			this.switchToLocale(next.dataset.localeTab);
			next.focus();
		});
	}

	switchToLocale(locale) {
		this.container.querySelectorAll('[data-locale-tab]').forEach(t => {
			const isActive = t.dataset.localeTab === locale;
			t.setAttribute('aria-selected', isActive ? 'true' : 'false');
			t.tabIndex = isActive ? 0 : -1;
			t.classList.toggle('active', isActive);
		});
		this.container.querySelectorAll('[data-locale-pane]').forEach(p => {
			p.hidden = p.dataset.localePane !== locale;
		});

		// Subclass hook — LocalizedStyledTextField uses this to nudge the
		// matching Tiptap instance so its layout settles.
		this.onLocaleSwitched?.(locale);
	}

	/** Return the locale-keyed dict of values. */
	getValue() {
		const out = {};
		this.localeInputs.forEach(input => {
			const code = input.dataset.locale;
			if (code) out[code] = input.value ?? '';
		});
		return out;
	}

	/** Accept a dict and distribute values; missing locales become empty strings. */
	setValue(value) {
		const dict = (value && typeof value === 'object') ? value : {};
		this.localeInputs.forEach(input => {
			const code = input.dataset.locale;
			if (code) input.value = dict[code] ?? '';
		});
		this.changed();
	}

	// Override changed() to compare stringified dicts since getValue() returns an object.
	changed() {
		if (this.input) this.input.setCustomValidity("");
		this.container.classList.remove("error");

		const current = JSON.stringify(this.getValue());
		if (this.storedValue === current) return;

		this.storedValue = current;
		this.container.classList.add("unsaved");

		if (this.isSubField()) {
			this.dispatcher.dispatchEvent("subfield-change", { field: this });
			return;
		}
		this.dispatcher.dispatchEvent("field-change", { field: this });
	}

	schema() {
		return {
			"type"  : "localizedtext",
			"field" : "localizedtext"
		};
	}
}
