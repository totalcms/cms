import { afterEach, beforeEach, describe, expect, it, vi } from 'vite-plus/test';

//-----------------------------------------------
// The Stock Photos panel (bundled extension totalcms/stock-photos): the button
// on each image and gallery field, the ⋯ menu item, the "Add to" picker, the
// search results, the + button and Escape. The script runs its setup when it
// loads, so each test builds the page first and then loads a fresh copy.
//-----------------------------------------------

const SCRIPT = '../../resources/extensions/totalcms/stock-photos/assets/stock-photos.js';

/** A field container as core builds it: a label, and its TotalField on `totalfield`. */
function field(type, label, parent = document.body) {
	const container = document.createElement('div');
	container.className = `form-field ${type}-field`;
	const labelEl = document.createElement('label');
	labelEl.textContent = label;
	container.append(labelEl);
	container.totalfield = { label, property: label.toLowerCase(), insertPhotos: vi.fn(async payload => payload.photos.length) };
	parent.append(container);
	return container;
}

/** A card or deck field that holds child fields. */
function parentField(label) {
	const container = document.createElement('div');
	container.className = 'form-field card-field';
	container.totalfield = { label, property: label.toLowerCase() };
	document.body.append(container);
	return container;
}

function actionsMenu() {
	document.body.insertAdjacentHTML('beforeend', `
		<button popovertarget="collection-actions"></button>
		<nav popover id="collection-actions"><ul>
			<li class="schema"><a href="#">Edit Schema</a></li>
			<li class="relationships"><a href="#">Relationships</a></li>
			<li class="export"><a href="#">Export</a></li>
		</ul></nav>`);
}

const nextFrame = () => new Promise(resolve => requestAnimationFrame(resolve));
const panel     = () => document.getElementById('stock-photos-panel');
const picker    = () => panel().querySelector('.stock-photos-target select');

const searchResult = {
	provider : { id: 'pixabay', label: 'Pixabay', url: 'https://pixabay.com/', license: 'https://pixabay.com/service/license-summary/' },
	total    : 2,
	photos   : [
		{ id: '1', thumb: 't1.jpg', preview: 'p1.jpg', download: '/admin/ext/totalcms/stock-photos/download?url=a', filename: 'pasta-pixabay-1', width: 640, height: 427, alt: 'Pasta, food', credit: { author: 'Ana', source: 'Pixabay' } },
		{ id: '2', thumb: 't2.jpg', preview: 'p2.jpg', download: '/admin/ext/totalcms/stock-photos/download?url=b', filename: 'wine-pixabay-2', width: 640, height: 427, alt: 'Wine', credit: { source: 'Pixabay' } },
	],
};

async function load() {
	vi.resetModules();
	await import(SCRIPT);
}

async function search(query) {
	panel().querySelector('input[type=search]').value = query;
	panel().querySelector('form').dispatchEvent(new Event('submit', { cancelable: true }));
	await vi.waitFor(() => expect(panel().querySelector('.stock-photos-status').textContent).not.toBe('Searching…'));
}

beforeEach(() => {
	document.body.replaceChildren();
	vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => searchResult })));
});

afterEach(() => {
	vi.unstubAllGlobals();
});

describe('the field button', () => {
	it('goes in the label of each image and gallery field that can take photos', async () => {
		const image   = field('image', 'Hero');
		const gallery = field('gallery', 'Photos');
		const file    = field('file', 'Menu PDF');
		const loading = field('image', 'Not built yet');
		delete loading.totalfield;
		await load();

		expect(image.querySelector('label .stock-photos-field-button')).not.toBeNull();
		expect(gallery.querySelector('label .stock-photos-field-button')).not.toBeNull();
		expect(file.querySelector('.stock-photos-field-button')).toBeNull();
		expect(loading.querySelector('.stock-photos-field-button')).toBeNull();
		expect(image.querySelector('.stock-photos-field-button').getAttribute('aria-label')).toBe('Search stock photos for Hero');
	});

	it('is added once to a field that arrives after load, such as a new deck item', async () => {
		field('image', 'Hero');
		await load();

		const later = field('image', 'Later');
		await Promise.resolve();
		await nextFrame();
		await nextFrame();

		expect(later.querySelectorAll('.stock-photos-field-button')).toHaveLength(1);
		expect(document.querySelectorAll('.stock-photos-field-button')).toHaveLength(2);
	});

	it('opens the panel on its field, and closes it when pressed again', async () => {
		field('image', 'Hero');
		const gallery = field('gallery', 'Photos');
		await load();

		const button = gallery.querySelector('.stock-photos-field-button');
		button.click();
		expect(panel().hidden).toBe(false);
		expect(button.getAttribute('aria-expanded')).toBe('true');
		expect(picker().selectedOptions[0].textContent).toBe('Photos');

		button.click();
		expect(panel().hidden).toBe(true);
		expect(button.getAttribute('aria-expanded')).toBe('false');
	});
});

describe('the actions menu item', () => {
	it('goes after Relationships and opens the panel on the first field', async () => {
		actionsMenu();
		field('image', 'Hero');
		field('gallery', 'Photos');
		await load();

		const items = Array.from(document.querySelectorAll('#collection-actions li')).map(li => li.className);
		expect(items).toEqual(['schema', 'relationships', 'stock-photos', 'export']);

		document.querySelector('#collection-actions .stock-photos a').click();
		expect(panel().hidden).toBe(false);
		expect(picker().selectedOptions[0].textContent).toBe('Hero');
	});

	it('is not added to a page without image or gallery fields', async () => {
		actionsMenu();
		field('file', 'Menu PDF');
		await load();

		expect(document.querySelector('#collection-actions .stock-photos')).toBeNull();
	});
});

describe('the Add to picker', () => {
	it('lists nested fields under their parent and numbers names that repeat', async () => {
		field('image', 'Hero');
		const menu = parentField('Menu');
		field('image', 'Photo', menu);
		field('image', 'Photo', menu);
		await load();

		document.querySelector('.stock-photos-field-button').click();
		expect(Array.from(picker().options).map(option => option.textContent)).toEqual(['Hero', 'Menu › Photo (1)', 'Menu › Photo (2)']);
		expect(picker().disabled).toBe(false);
	});

	it('is disabled when the page has one field', async () => {
		field('image', 'Hero');
		await load();

		document.querySelector('.stock-photos-field-button').click();
		expect(picker().disabled).toBe(true);
	});
});

describe('searching and adding', () => {
	it('shows the results with the source and license links', async () => {
		field('image', 'Hero');
		await load();
		document.querySelector('.stock-photos-field-button').click();
		await search('pasta');

		const url = new URL(fetch.mock.calls[0][0]);
		expect(url.pathname).toMatch(/ext\/totalcms\/stock-photos\/search$/);
		expect(url.searchParams.get('q')).toBe('pasta');

		expect(panel().querySelectorAll('.stock-photos-grid li')).toHaveLength(2);
		expect(panel().querySelector('.stock-photos-status').textContent).toBe('2 of 2 photos');
		expect(panel().querySelector('.stock-photos-source').textContent).toBe('Images from Pixabay');
		expect(panel().querySelector('.stock-photos-license').href).toBe('https://pixabay.com/service/license-summary/');
		expect(panel().querySelector('.stock-photos-more').hidden).toBe(true);
	});

	it('shows the error the search route returns', async () => {
		field('image', 'Hero');
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 502, json: async () => ({ ...searchResult, photos: [], error: 'Pixabay rate limit reached.' }) })));
		await load();
		document.querySelector('.stock-photos-field-button').click();
		await search('pasta');

		expect(panel().querySelector('.stock-photos-status').textContent).toBe('Search failed: Pixabay rate limit reached.');
	});

	it('+ puts the photo, with its alt and credit, in the field chosen in Add to', async () => {
		const hero    = field('image', 'Hero');
		const gallery = field('gallery', 'Photos');
		await load();
		hero.querySelector('.stock-photos-field-button').click();
		await search('pasta');

		picker().value = '1';
		picker().dispatchEvent(new Event('change'));

		const add = panel().querySelector('.stock-photos-add');
		expect(add.getAttribute('aria-label')).toBe('Add photo by Ana');
		add.click();
		await vi.waitFor(() => expect(add.dataset.state).toBe('added'));

		expect(hero.totalfield.insertPhotos).not.toHaveBeenCalled();
		expect(gallery.totalfield.insertPhotos).toHaveBeenCalledWith({ photos: [{
			url      : '/admin/ext/totalcms/stock-photos/download?url=a',
			filename : 'pasta-pixabay-1',
			alt      : 'Pasta, food',
			credit   : { author: 'Ana', source: 'Pixabay' },
		}] });
	});

	it('+ shows a failure when the field adds nothing', async () => {
		const hero = field('image', 'Hero');
		hero.totalfield.insertPhotos = vi.fn(async () => 0);
		await load();
		hero.querySelector('.stock-photos-field-button').click();
		await search('pasta');

		const add = panel().querySelector('.stock-photos-add');
		add.click();
		await vi.waitFor(() => expect(add.dataset.state).toBe('failed'));
	});

	it('a thumbnail drag carries the photo drop payload', async () => {
		field('image', 'Hero');
		await load();
		document.querySelector('.stock-photos-field-button').click();
		await search('pasta');

		const data  = {};
		const event = new Event('dragstart');
		event.dataTransfer = { setData: (type, value) => { data[type] = value; }, effectAllowed: '' };
		panel().querySelector('.stock-photos-grid img').dispatchEvent(event);

		expect(JSON.parse(data['application/x-totalcms-photo']).photos[0].filename).toBe('pasta-pixabay-1');
		expect(event.dataTransfer.effectAllowed).toBe('copy');
	});
});

describe('Escape', () => {
	it('closes the panel and returns focus to the button that opened it', async () => {
		field('image', 'Hero');
		await load();
		const button = document.querySelector('.stock-photos-field-button');
		button.click();

		document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
		expect(panel().hidden).toBe(true);
		expect(document.activeElement).toBe(button);
	});

	it('leaves the panel open when it belongs to an open dialog the user is in', async () => {
		field('image', 'Hero');
		await load();
		document.querySelector('.stock-photos-field-button').click();

		const dialog = document.createElement('dialog');
		dialog.setAttribute('open', '');
		const input = document.createElement('input');
		dialog.append(input);
		document.body.append(dialog);
		input.focus();

		document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
		expect(panel().hidden).toBe(false);
	});
});
