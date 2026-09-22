import { regenerateIds } from '../../javascript/totalform/regenerateIds.mjs';

//-----------------------------------------------
// Cloned deck items and deck-table rows carry the template's ids. The shared
// regenerateIds() gives each clone fresh ones while keeping every link intact —
// the case that bit: PasswordField finds its confirm input by `${id}-confirm`,
// so both must move to the same new uuid or validate() throws on the clone.
//-----------------------------------------------

function fragment(html) {
	const el = document.createElement('div');
	el.innerHTML = html;
	return el;
}

describe('regenerateIds', () => {
	test('keeps a password input and its -confirm twin on one uuid, with their labels', () => {
		const el = fragment(`
			<label for="field-abc">Password</label><input id="field-abc" type="password">
			<label for="field-abc-confirm">Confirm</label><input id="field-abc-confirm" type="password">
		`);
		regenerateIds(el);

		const [pw, confirm] = el.querySelectorAll('input');
		expect(pw.id).not.toBe('field-abc');
		expect(confirm.id).toBe(`${pw.id}-confirm`);
		expect(el.querySelector('label[for]').getAttribute('for')).toBe(pw.id);
		expect(el.querySelectorAll('label')[1].getAttribute('for')).toBe(confirm.id);
	});

	test('moves help, datalist and template ids on the same uuid and fixes their references', () => {
		const el = fragment(`
			<input id="field-x1" aria-describedby="help-x1" list="datalist-x1">
			<p id="help-x1"></p><datalist id="datalist-x1"></datalist>
			<template id="template-x1"></template>
		`);
		const map = regenerateIds(el);
		const uuid = map.x1;

		expect(uuid).toBeTruthy();
		expect(el.querySelector('input').id).toBe(`field-${uuid}`);
		expect(el.querySelector('input').getAttribute('aria-describedby')).toBe(`help-${uuid}`);
		expect(el.querySelector('input').getAttribute('list')).toBe(`datalist-${uuid}`);
		expect(el.querySelector('p').id).toBe(`help-${uuid}`);
		expect(el.querySelector('datalist').id).toBe(`datalist-${uuid}`);
		expect(el.querySelector('template').id).toBe(`template-${uuid}`);
	});

	test('leaves ids outside the known prefixes alone', () => {
		const el = fragment(`<div id="keep-me"></div><input id="field-y">`);
		regenerateIds(el);
		expect(el.querySelector('div').id).toBe('keep-me');
		expect(el.querySelector('input').id).not.toBe('field-y');
	});

	test('two clones of the same template get different ids', () => {
		const a = fragment(`<input id="field-z">`);
		const b = fragment(`<input id="field-z">`);
		regenerateIds(a); regenerateIds(b);
		expect(a.querySelector('input').id).not.toBe(b.querySelector('input').id);
	});
});
