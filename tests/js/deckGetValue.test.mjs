import DeckField from '../../javascript/totalform/deck.js';
import DeckTableField from '../../javascript/totalform/deckTable.js';

//-----------------------------------------------
// Deck values are an object keyed by item id. Both the card-style deck (deck.js,
// items carry a .deckitem instance) and the table deck (deckTable.js, rows
// collected via collectScopedFieldValues) must key correctly and not let a
// composite child's sub-fields leak into the item. getValue() reads only a few
// instance props, so we exercise it via Object.create + a built DOM.
//-----------------------------------------------

// --- card-style deck (deck.js) ---
function cardDeck(items) {
	const container = document.createElement('div');
	for (const it of items) {
		const el = document.createElement('div');
		el.className = 'deck-item';
		if (it) el.deckitem = { getItemId: () => it.id, getValue: () => it.value };
		container.appendChild(el);
	}
	const deck = Object.create(DeckField.prototype);
	deck.container = container;
	deck.fieldClass = 'deck-item';
	return deck;
}

describe('DeckField.getValue', () => {
	test('keys items by their id', () => {
		const deck = cardDeck([
			{ id: 'one', value: { name: 'A' } },
			{ id: 'two', value: { name: 'B' } },
		]);
		expect(deck.getValue()).toEqual({ one: { name: 'A' }, two: { name: 'B' } });
	});

	test('skips elements without a deckitem instance', () => {
		const deck = cardDeck([{ id: 'one', value: { name: 'A' } }, null]);
		expect(deck.getValue()).toEqual({ one: { name: 'A' } });
	});

	test('returns an empty object for an empty deck', () => {
		expect(cardDeck([]).getValue()).toEqual({});
	});
});

// --- table deck (deckTable.js) ---
function fieldEl(name, value) {
	const fc = document.createElement('div');
	fc.className = 'form-field';
	const input = document.createElement('input');
	input.name = name;
	fc.appendChild(input);
	fc.totalfield = { input, getValue: () => value };
	return fc;
}

function tableDeck(rows) {
	const tableBody = document.createElement('div');
	tableBody.className = 'deck-table-body';
	document.body.innerHTML = '';
	document.body.appendChild(tableBody);
	for (const buildRow of rows) {
		const row = document.createElement('div');
		row.className = 'deck-table-row';
		buildRow(row);
		tableBody.appendChild(row);
	}
	const deck = Object.create(DeckTableField.prototype);
	deck.tableBody = tableBody;
	return deck;
}

describe('DeckTableField.getValue', () => {
	test('keys rows by id and keeps a composite child\'s value nested (no leak)', () => {
		const deck = tableDeck([
			(row) => {
				row.appendChild(fieldEl('id', 'r1'));
				row.appendChild(fieldEl('name', 'Row One'));
				const image = fieldEl('myimage', { name: 'pic.jpg' });
				// image's internal `name` sub-field — must NOT clobber the row's name
				image.appendChild(fieldEl('name', 'pic.jpg'));
				row.appendChild(image);
			},
		]);

		expect(deck.getValue()).toEqual({
			r1: { id: 'r1', name: 'Row One', myimage: { name: 'pic.jpg' } },
		});
	});

	test('skips rows with an empty id', () => {
		const deck = tableDeck([
			(row) => { row.appendChild(fieldEl('id', '')); row.appendChild(fieldEl('name', 'orphan')); },
			(row) => { row.appendChild(fieldEl('id', 'keep')); row.appendChild(fieldEl('name', 'kept')); },
		]);

		expect(deck.getValue()).toEqual({ keep: { id: 'keep', name: 'kept' } });
	});
});
