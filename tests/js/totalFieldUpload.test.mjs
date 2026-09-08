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

// A video field's poster sub-field: the poster extends the VIDEO's own upload
// context by one segment (see totalfield.js), so wherever the video lives —
// top level, card, deck item — the poster path is the video path + `/poster`.
function posterInsideVideo(videoField) {
	const videoEl = document.createElement('div');
	videoEl.className = 'form-field';
	videoEl.dataset.type = 'video';
	videoEl.totalfield = videoField;
	// Keep whatever ancestry the video already had (a card's inner div) by
	// nesting the real video element inside it.
	const previous = videoField.container;
	if (previous && previous.parentElement) previous.appendChild(videoEl);
	videoField.container = videoEl;
	const media = document.createElement('div');
	media.className = 'video-media';
	videoEl.appendChild(media);
	const cardFields = document.createElement('div');
	cardFields.className = 'card-fields';
	media.appendChild(cardFields);
	const posterEl = document.createElement('div');
	posterEl.className = 'form-field';
	posterEl.dataset.type = 'image';
	cardFields.appendChild(posterEl);
	return posterEl;
}

describe('TotalField.getUploadContext', () => {
	test('poster inside a top-level video → the video property, subpath poster', () => {
		const video  = field({ property: 'promo' });
		const poster = field({ property: 'poster', container: posterInsideVideo(video) });
		expect(poster.getUploadContext()).toEqual({
			collection: 'posts', id: 'p1', property: 'promo', subpath: 'poster',
		});
	});

	test('poster inside a video inside a card → the card property, subpath video/poster', () => {
		const video  = field({ property: 'promo', container: cardContainer('hero') });
		const poster = field({ property: 'poster', container: posterInsideVideo(video) });
		// Attach the video element under the card so closest() finds the card.
		const cardInner = video.container.parentElement ?? null;
		expect(poster.getUploadContext()).toEqual({
			collection: 'posts', id: 'p1', property: 'hero', subpath: 'promo/poster',
		});
		expect(poster.buildPropertyApi('/collections')).toBe('/collections/posts/p1/hero/promo/poster');
		expect(cardInner).not.toBeNull();
	});

	test('poster inside a video inside a deck item → the deck property, subpath item/video/poster', () => {
		const item   = deckItemEl('slides', 'first');
		const video  = field({ property: 'promo', deckItem: item });
		const poster = field({ property: 'poster', container: posterInsideVideo(video), deckItem: item });
		item.appendChild(video.container);
		expect(poster.getUploadContext()).toEqual({
			collection: 'posts', id: 'p1', property: 'slides', subpath: 'first/promo/poster',
		});
	});

	test('poster inside a video whose deck item has no id yet → null (not ready)', () => {
		const item   = deckItemEl('slides', '');
		const video  = field({ property: 'promo', deckItem: item });
		const poster = field({ property: 'poster', container: posterInsideVideo(video), deckItem: item });
		expect(poster.getUploadContext()).toBeNull();
	});

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
