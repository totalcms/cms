import DeckField from '../../javascript/totalform/deck.js';

//-----------------------------------------------
// DeckField.validate() — the dialog-per-item deck. Mirrors the id checks in
// deckTableValidate.test.mjs. The message for a missing id *property* names
// the sub-schema: a child schema without `id` renders no id input, and the
// old generic "Item ID cannot be empty" sent authors hunting through their
// data for an empty value that did not exist.
//-----------------------------------------------

function deck(items, { schemaref = '' } = {}) {
	const field = Object.create(DeckField.prototype);
	field.container = document.createElement('div'); // visible
	field.input = document.createElement('input');
	field.schemaref = schemaref;
	field.minItems = 0;
	field.maxItems = -1;
	field.fieldClass = 'deck-item';
	field.items = items.map(({ id, hasIdInput = true }) => ({
		hasIdInput: () => hasIdInput,
		getItemId: () => id,
		validate: () => true,
		error: () => {},
	}));
	field.error = () => {};
	return field;
}

describe('DeckField.validate', () => {
	test('passes for unique, non-empty ids', () => {
		expect(deck([{ id: 'one' }, { id: 'two' }]).validate()).toBe(true);
	});

	test('names the sub-schema when it has no id property at all', () => {
		const field = deck([{ id: '', hasIdInput: false }], { schemaref: 'https://www.totalcms.co/schemas/custom/thread-message-item.json' });
		const errors = [];
		field.error = (message) => errors.push(message);
		expect(field.validate()).toBe(false);
		expect(errors[0]).toBe('Deck schema "thread-message-item" has no "id" property. Add an id property to the schema so its items can be keyed.');
	});

	test('still reports an empty id when the id input exists', () => {
		const field = deck([{ id: '' }], { schemaref: 'thread-message-item' });
		const errors = [];
		field.error = (message) => errors.push(message);
		expect(field.validate()).toBe(false);
		expect(errors[0]).toBe('Item ID cannot be empty');
	});

	test('fails on a duplicate id', () => {
		expect(deck([{ id: 'dup' }, { id: 'dup' }]).validate()).toBe(false);
	});
});
