import DeckTableField from '../../javascript/totalform/deckTable.js';

//-----------------------------------------------
// A new deck-table row is a deck item that does not exist on disk yet, so an
// image or file dropped into it must wait for the parent save (which gives the
// row its shape and id) before uploading. The regular deck marks a new item
// `unsaved` for exactly this — TotalField.parentIsSaved() reads that class —
// and the table must do the same, before the row's fields initialize so the
// dropzone starts with auto-processing off.
//-----------------------------------------------

function table() {
	const field = Object.create(DeckTableField.prototype);
	field.container = document.createElement('div');
	field.input = document.createElement('input');
	field.minItems = 0;
	field.maxItems = -1;
	field.oidCounter = 0;

	const body = document.createElement('div');
	body.className = 'deck-table-body';
	field.container.appendChild(body);
	field.tableBody = body;

	const tpl = document.createElement('template');
	tpl.className = 'deck-table-template';
	// An image cell carries its own action bar — with a trash button — BEFORE
	// the row's own delete button in DOM order, exactly as the PHP renders it.
	tpl.innerHTML = `<div class="deck-table-row">
		<div class="deck-table-cell"><div class="form-field"><input id="field-abc" name="id"></div></div>
		<div class="deck-table-cell"><div class="form-field" data-type="image">
			<input id="field-def" name="photo">
			<div class="total-preview"><div class="dz-preview"><div class="actionbar"><button type="button" class="trash">image</button></div></div></div>
			<template id="template-def"><div class="dz-preview"></div></template>
		</div></div>
		<div class="deck-table-actions"><button type="button" class="trash">row</button></div>
	</div>`;
	field.container.appendChild(tpl);
	field.template = tpl;

	field.rowsSeenByInit = [];
	field.initRow = row => { field.rowsSeenByInit.push({ row, unsavedAtInit: row.classList.contains('unsaved') }); };
	field.form = null; // initRow's real implementation tolerates no form
	field.changed = () => {};
	field.updateAddButton = () => {};
	return field;
}

describe('DeckTableField new rows', () => {
	test('a new row is marked unsaved before its fields initialize', () => {
		const field = table();
		field.addRow();

		const row = field.tableBody.querySelector('.deck-table-row');
		expect(row.classList.contains('unsaved')).toBe(true);
		expect(field.rowsSeenByInit).toHaveLength(1);
		expect(field.rowsSeenByInit[0].unsavedAtInit).toBe(true);
	});

	test('saved() clears the row so later uploads go straight through', () => {
		const field = table();
		field.addRow();
		field.storedValue = undefined;
		field.getValue = () => ({});
		field.saved();

		expect(field.tableBody.querySelector('.deck-table-row.unsaved')).toBeNull();
	});

	test('a cloned row does not reuse the image template id', () => {
		const field = table();
		field.addRow();
		field.addRow();

		const ids = Array.from(field.container.querySelectorAll('.deck-table-body template[id]')).map(t => t.id);
		expect(ids).toHaveLength(2);
		expect(new Set(ids).size).toBe(2);
		expect(ids).not.toContain('template-def');
	});

	test('the row delete button is the row\'s own, not the image cell\'s trash', () => {
		const field = table();
		field.initRow = DeckTableField.prototype.initRow; // the real one
		field.initRowVisibility = () => {};
		field.removeRow = row => { row.remove(); field.removed = (field.removed || 0) + 1; };
		field.addRow();

		const row = field.tableBody.querySelector('.deck-table-row');
		const imageTrash = row.querySelector('.form-field[data-type="image"] .actionbar button.trash');
		const rowTrash   = row.querySelector('.deck-table-actions button.trash');

		// The image's trash deletes the image (its own handler, not under test) — never the row.
		imageTrash.click();
		expect(field.tableBody.contains(row)).toBe(true);
		expect(field.removed || 0).toBe(0);

		rowTrash.click();
		expect(field.tableBody.contains(row)).toBe(false);
		expect(field.removed).toBe(1);
	});
});

