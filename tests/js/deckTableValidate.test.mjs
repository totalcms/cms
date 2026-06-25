import DeckTableField from '../../javascript/totalform/deckTable.js';

//-----------------------------------------------
// DeckTableField.validate() is the gate that stops a bad deck from being saved.
// The high-value cases protect against silent data loss: an empty item id is
// dropped on save, and a duplicate id makes one item overwrite another.
//-----------------------------------------------

function deckTable(ids, { required = false, minItems = 0, maxItems = -1 } = {}) {
	const field = Object.create(DeckTableField.prototype);
	field.container = document.createElement('div'); // visible (no field-hidden)
	const input = document.createElement('input');
	input.type = 'text';
	if (required) input.required = true;
	field.input = input;

	const tableBody = document.createElement('div');
	for (const id of ids) {
		const row = document.createElement('div');
		row.className = 'deck-table-row';
		const idInput = document.createElement('input');
		idInput.name = 'id';
		idInput.value = id;
		row.appendChild(idInput);
		tableBody.appendChild(row);
	}
	field.tableBody = tableBody;
	field.minItems = minItems;
	field.maxItems = maxItems;
	field.error = () => {}; // suppress dispatcher/console side-effects
	return field;
}

describe('DeckTableField.validate', () => {
	test('passes for unique, non-empty ids', () => {
		expect(deckTable(['one', 'two', 'three']).validate()).toBe(true);
	});

	test('fails when a row id is empty (the item would be dropped on save)', () => {
		expect(deckTable(['one', '']).validate()).toBe(false);
	});

	test('fails on a duplicate id (one item would overwrite another)', () => {
		expect(deckTable(['dup', 'dup']).validate()).toBe(false);
	});

	test('enforces required — at least one item', () => {
		expect(deckTable([], { required: true }).validate()).toBe(false);
		expect(deckTable(['one'], { required: true }).validate()).toBe(true);
	});

	test('enforces minItems', () => {
		expect(deckTable(['one'], { minItems: 2 }).validate()).toBe(false);
		expect(deckTable(['one', 'two'], { minItems: 2 }).validate()).toBe(true);
	});

	test('enforces maxItems', () => {
		expect(deckTable(['a', 'b', 'c'], { maxItems: 2 }).validate()).toBe(false);
		expect(deckTable(['a', 'b'], { maxItems: 2 }).validate()).toBe(true);
	});
});
