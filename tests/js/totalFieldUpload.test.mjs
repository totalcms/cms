import TotalField from '../../javascript/totalform/totalfield.js';

//-----------------------------------------------
// getUploadContext() resolves where a field's uploads should be addressed —
// top-level, inside a card, or inside a deck item — and buildPropertyApi() turns
// that into a URL. The nesting rules are subtle (deck wins over card; deck items
// need a typed id), so they're worth pinning. Both read only instance state.
//-----------------------------------------------

const defaultForm = { collection: 'posts', getId: () => 'p1', id: 'p1' };

function field({ property = 'pic', form = defaultForm, deckItem = null, container = null } = {}) {
	const f = Object.create(TotalField.prototype);
	f.property = property;
	f.form = form;
	f.deckItem = deckItem;
	f.container = container ?? document.createElement('div');
	return f;
}

function cardContainer(cardProperty) {
	const card = document.createElement('div');
	card.className = 'form-field';
	card.dataset.type = 'card';
	card.totalfield = { property: cardProperty };
	const inner = document.createElement('div');
	card.appendChild(inner);
	return inner;
}

function deckItemEl(deckProperty, itemId) {
	const deck = document.createElement('div');
	deck.className = 'form-field';
	deck.dataset.type = 'deck';
	deck.totalfield = { property: deckProperty };
	const item = document.createElement('div');
	item.className = 'deck-item';
	deck.appendChild(item);
	const dialog = document.createElement('dialog');
	const idInput = document.createElement('input');
	idInput.name = 'id';
	idInput.value = itemId;
	dialog.appendChild(idInput);
	item.appendChild(dialog);
	return item;
}

describe('TotalField.getUploadContext', () => {
	test('top-level field → its own property, no subpath', () => {
		expect(field({ property: 'myimage' }).getUploadContext()).toEqual({
			collection: 'posts', id: 'p1', property: 'myimage', subpath: '',
		});
	});

	test('field inside a card → the card property, subpath is the field name', () => {
		expect(field({ property: 'pic', container: cardContainer('mycard') }).getUploadContext()).toEqual({
			collection: 'posts', id: 'p1', property: 'mycard', subpath: 'pic',
		});
	});

	test('field inside a deck item → deck property, subpath is itemId/field', () => {
		expect(field({ property: 'pic', deckItem: deckItemEl('mydeck', 'item7') }).getUploadContext()).toEqual({
			collection: 'posts', id: 'p1', property: 'mydeck', subpath: 'item7/pic',
		});
	});

	test('returns null without a form', () => {
		expect(field({ form: null }).getUploadContext()).toBeNull();
	});

	test('returns null before the parent object has an id', () => {
		expect(field({ form: { collection: 'posts', getId: () => '' } }).getUploadContext()).toBeNull();
	});

	test('returns null for a deck item whose id is not typed yet', () => {
		expect(field({ deckItem: deckItemEl('mydeck', '') }).getUploadContext()).toBeNull();
	});
});

describe('TotalField.buildPropertyApi', () => {
	test('top-level URL has no subpath segment', () => {
		expect(field({ property: 'myimage' }).buildPropertyApi('/upload', '.jpg'))
			.toBe('/upload/posts/p1/myimage.jpg');
	});

	test('nested URL inserts the subpath between property and suffix', () => {
		expect(field({ property: 'pic', deckItem: deckItemEl('mydeck', 'item7') }).buildPropertyApi('/upload'))
			.toBe('/upload/posts/p1/mydeck/item7/pic');
	});
});
