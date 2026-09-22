//-----------------------------------------------
// Give a cloned form fragment (a deck item, a deck-table row) fresh element ids.
//
// A clone carries the template's ids, so the page would hold duplicate
// `field-*`, `help-*`, `datalist-*` and `template-*` ids and every
// `for` / `aria-describedby` / `list` link would point at the original.
// Each old uuid maps to one new uuid, shared across prefixes and across the
// `-confirm` suffix PasswordField gives its confirm input: PasswordField
// resolves the confirm via `${input.id}-confirm`, so the two must move
// together — a cloned dialog with a password field otherwise throws on
// validate. One implementation for both decks; they used to drift.
//-----------------------------------------------
const PREFIXES = /^(field|help|datalist|template)-(.+)$/;
const SUFFIX   = '-confirm';

export function regenerateIds(element) {
	const idMap = {};

	element.querySelectorAll('[id]').forEach(el => {
		const match = el.id.match(PREFIXES);
		if (!match) return;

		const prefix = match[1];
		let oldUuid  = match[2];
		let suffix   = '';

		if (oldUuid.endsWith(SUFFIX)) {
			suffix  = SUFFIX;
			oldUuid = oldUuid.slice(0, -SUFFIX.length);
		}

		idMap[oldUuid] ??= Math.random().toString(36).substring(2, 15);
		el.id = `${prefix}-${idMap[oldUuid]}${suffix}`;
	});

	for (const [oldUuid, newUuid] of Object.entries(idMap)) {
		element.querySelectorAll(`[for="field-${oldUuid}"]`).forEach(el => el.setAttribute('for', `field-${newUuid}`));
		element.querySelectorAll(`[for="field-${oldUuid}${SUFFIX}"]`).forEach(el => el.setAttribute('for', `field-${newUuid}${SUFFIX}`));
		element.querySelectorAll(`[aria-describedby="help-${oldUuid}"]`).forEach(el => el.setAttribute('aria-describedby', `help-${newUuid}`));
		element.querySelectorAll(`[list="datalist-${oldUuid}"]`).forEach(el => el.setAttribute('list', `datalist-${newUuid}`));
	}

	return idMap;
}
