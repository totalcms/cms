//-----------------------------------------------
// Shared field-collection helpers for composite/container fields
//-----------------------------------------------
// A composite field (image, file, card, gallery, …) renders its own sub-fields
// as nested `.form-field` elements. When a container (deck item, deck-table row,
// autogen scope) collects "its own" fields, those nested sub-fields must be
// skipped — otherwise they leak to the container's top level and collide with
// its real properties. The canonical bug: an image field's readonly `name`
// ("Filename") sub-field overwriting a deck item's own `name` property, which
// also poisons `${name}` autogen.
//
// This mirrors the scoping fix already applied to PropertyField.getValue()
// (property.js / commit d00dfc01) and generalizes it so deck items, deck-table
// rows, and autogen all agree on what "this container's own fields" means.
//
// Authored as `.mjs` (no dependencies) so it can be imported natively by Node
// for unit tests while the package stays CommonJS; esbuild bundles it into the
// admin assets via the explicit-extension import in the call sites.

/**
 * True when `container` is a sub-field rendered inside another `.form-field`
 * that lives within `scopeEl` — i.e. it belongs to a composite field's own
 * getValue(), not to `scopeEl`'s top level.
 *
 * @param {Element} container
 * @param {Element} scopeEl
 * @returns {boolean}
 */
export function isNestedField(container, scopeEl) {
	const parentField = container?.parentElement?.closest('.form-field');
	return !!(parentField && scopeEl.contains(parentField));
}

/**
 * Collect a scope's own top-level field values via their TotalField instances,
 * skipping composite sub-fields. Falls back to raw input values for any
 * top-level input that has no TotalField instance yet.
 *
 * @param {Element} scopeEl
 * @returns {Object<string,*>}
 */
export function collectScopedFieldValues(scopeEl) {
	const data = {};

	// Primary pass: fields with a TotalField instance.
	for (const container of scopeEl.querySelectorAll('.form-field')) {
		if (isNestedField(container, scopeEl)) continue;

		const field = container.totalfield;
		if (!field || !field.input || !field.input.name) continue;

		const name = field.input.name;
		try {
			data[name] = field.getValue();
		} catch (error) {
			// If getValue fails (e.g. field not fully initialized), fall back to the raw input value.
			console.warn(`Failed to get value for field ${name}:`, error);
			data[name] = field.input.value || '';
		}
	}

	// Fallback pass: top-level inputs without a TotalField instance.
	for (const input of scopeEl.querySelectorAll('input, textarea, select')) {
		if (!input.name || Object.prototype.hasOwnProperty.call(data, input.name)) continue;

		const container = input.closest('.form-field');
		if (container && isNestedField(container, scopeEl)) continue;

		if (input.type === 'checkbox') {
			data[input.name] = input.checked;
		} else if (input.type === 'radio') {
			if (input.checked) data[input.name] = input.value;
		} else if (input.tagName === 'SELECT' && input.multiple) {
			data[input.name] = Array.from(input.selectedOptions).map(option => option.value);
		} else {
			data[input.name] = input.value;
		}
	}

	return data;
}

/**
 * Collect raw input values keyed by name for autogen pattern interpolation,
 * skipping composite sub-fields so e.g. `${name}` reads the container's own
 * `name` field, not an image/file field's `name` sub-field.
 *
 * @param {Element} scopeEl
 * @returns {Object<string,string>}
 */
export function collectScopedInputValues(scopeEl) {
	const data = {};

	for (const input of scopeEl.querySelectorAll('input, textarea, select')) {
		if (!input.name) continue;

		const container = input.closest('.form-field');
		if (container && isNestedField(container, scopeEl)) continue;

		data[input.name] = input.value;
	}

	return data;
}
