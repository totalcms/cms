import DeckItem from '../../javascript/totalform/deckItem.js';

// generateLabel interpolates the deck-item label pattern from the item's data,
// pads oid tokens, and falls back to the item id when it resolves to nothing.
function item(fieldData, { itemId = 'existing' } = {}) {
	const di = Object.create(DeckItem.prototype);
	const container = document.createElement('div');
	container.setAttribute('data-item-id', itemId); // existing item => deterministic (no special vars)
	di.container = container;
	di.getValue = () => fieldData;
	di.deck = { getNextOid: () => 1 };
	di.generateUuid = () => 'uuid';
	return di;
}

describe('DeckItem.generateLabel', () => {
	test('interpolates a field reference', () => {
		expect(item({ name: 'Star Dust', id: 'sd' }).generateLabel('${name}')).toBe('Star Dust');
	});
	test('interpolates within surrounding text', () => {
		expect(item({ name: 'Star Dust', id: 'sd' }).generateLabel('Item: ${name}')).toBe('Item: Star Dust');
	});
	test('pads an oid token', () => {
		expect(item({ id: 'sd' }).generateLabel('${oid-000}')).toBe('001');
	});
	test('falls back to the item id when the pattern resolves to empty', () => {
		expect(item({ id: 'sd' }).generateLabel('${missing}')).toBe('sd');
	});
});
