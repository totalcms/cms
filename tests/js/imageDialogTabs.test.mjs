import { describe, expect, test, vi, beforeEach, afterEach } from 'vite-plus/test';
import { createImageDialog } from '../../javascript/totalform/tiptap/extensions/ImageDialog.js';
import { creditText } from '../../javascript/totalform/photo-drop.js';

//-----------------------------------------------
// Extension tabs in the Insert Image dialog (window.TotalCMS.imageDialogTabs):
// an extension renders the tab and picks a photo; core downloads it, uploads
// it to the field and inserts it with the credit as its title.
//-----------------------------------------------

// jsdom has no showModal/close; the dialog only needs open state and a close event.
beforeEach(() => {
	HTMLDialogElement.prototype.showModal = function () { this.open = true; };
	HTMLDialogElement.prototype.close = function () { this.open = false; this.dispatchEvent(new Event('close')); };
	globalThis.CSS ??= {};
	globalThis.CSS.escape ??= value => String(value).replace(/["\\:/]/g, '\\$&');
});

afterEach(() => {
	delete window.TotalCMS;
	document.body.innerHTML = '';
	vi.unstubAllGlobals();
	vi.restoreAllMocks();
});

// A fake XHR that answers every upload with { link }.
function stubUpload(link) {
	const sent = [];
	vi.stubGlobal('XMLHttpRequest', class {
		constructor() { this.listeners = {}; this.upload = { addEventListener() {} }; }
		open(method, url) { this.url = url; }
		setRequestHeader() {}
		addEventListener(name, fn) { this.listeners[name] = fn; }
		send(body) {
			sent.push({ url: this.url, body });
			this.status = 200;
			this.responseText = JSON.stringify({ link });
			queueMicrotask(() => this.listeners.load());
		}
	});
	return sent;
}

function openDialog(tab) {
	window.TotalCMS = { imageDialogTabs: [tab] };
	const target = { getContent: () => '', insertImage: vi.fn() };
	createImageDialog(target, { url: '/api/upload/blog/post/content' });
	return { target, dialog: document.querySelector('dialog') };
}

const photo = {
	url      : 'ext/totalcms/stock-photos/download?id=1',
	filename : 'burrata',
	alt      : 'Burrata on a plate',
	credit   : { author: 'Jane', source: 'Pixabay' },
};

describe('creditText', () => {
	test('names the author and the source when it has them', () => {
		expect(creditText({ author: 'Jane', source: 'Pixabay' })).toBe('Photo by Jane on Pixabay');
		expect(creditText({ author: 'Jane' })).toBe('Photo by Jane');
		expect(creditText({ source: 'Pixabay' })).toBe('Photo from Pixabay');
		expect(creditText({})).toBe('');
		expect(creditText(undefined)).toBe('');
	});
});

describe('extension tabs in the Insert Image dialog', () => {
	test('adds a tab and renders it once, the first time it is shown', () => {
		const render = vi.fn(panel => { panel.textContent = 'stock'; });
		const { dialog } = openDialog({ id: 'stock-photos', label: 'Stock Photos', render });

		const tab = dialog.querySelector('[data-tab="ext:stock-photos"]');
		expect(tab.textContent).toBe('Stock Photos');
		expect(render).not.toHaveBeenCalled();

		tab.click();
		dialog.querySelector('[data-tab="upload"]').click();
		tab.click();

		expect(render).toHaveBeenCalledTimes(1);
		expect(dialog.querySelector('[data-panel="ext:stock-photos"]').textContent).toContain('stock');
	});

	test('ignores tabs without an id, label or render function', () => {
		window.TotalCMS = { imageDialogTabs: [{ id: 'x' }, null, { label: 'y', render() {} }] };
		createImageDialog({ getContent: () => '', insertImage() {} }, { url: '/api/upload/a/b/c' });

		expect(document.querySelectorAll('.ste-dialog-tab')).toHaveLength(2);
	});

	test('a tab that throws while rendering says so instead of breaking the dialog', () => {
		vi.spyOn(console, 'error').mockImplementation(() => {});
		const { dialog } = openDialog({ id: 'broken', label: 'Broken', render() { throw new Error('nope'); } });

		dialog.querySelector('[data-tab="ext:broken"]').click();

		expect(dialog.querySelector('[data-panel="ext:broken"]').textContent).toContain('Broken could not be loaded.');
	});

	test('selecting a photo fills the alt, unless the editor typed their own', () => {
		let ctx;
		const { dialog } = openDialog({ id: 'stock', label: 'Stock', render(_panel, c) { ctx = c; } });
		dialog.querySelector('[data-tab="ext:stock"]').click();
		const alt = dialog.querySelector('.ste-alt-input');

		ctx.select(photo);
		expect(alt.value).toBe('Burrata on a plate');

		ctx.select({ ...photo, alt: 'Another' });
		expect(alt.value).toBe('Another');

		alt.value = 'Mine';
		ctx.select(photo);
		expect(alt.value).toBe('Mine');
	});

	test('Insert downloads the photo, uploads it to the field and inserts it with its credit', async () => {
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, blob: async () => new Blob(['x'], { type: 'image/jpeg' }) })));
		const sent = stubUpload('/stock/burrata.jpg');
		let ctx;
		const { dialog, target } = openDialog({ id: 'stock', label: 'Stock', render(_panel, c) { ctx = c; } });
		dialog.querySelector('[data-tab="ext:stock"]').click();

		ctx.select(photo);
		dialog.querySelector('.ste-dialog-btn--insert').click();
		await vi.waitFor(() => expect(target.insertImage).toHaveBeenCalled());

		expect(fetch).toHaveBeenCalledWith(photo.url, { credentials: 'same-origin' });
		expect(sent[0].url).toBe('/api/upload/blog/post/content');
		expect(sent[0].body.get('file').name).toBe('burrata.jpg');
		expect(target.insertImage).toHaveBeenCalledWith({ src: '/stock/burrata.jpg', alt: 'Burrata on a plate', title: 'Photo by Jane on Pixabay' });
		expect(dialog.open).toBe(false);
	});

	test('a photo that cannot be downloaded shows an error and keeps the dialog open', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {});
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 502 })));
		let ctx;
		const { dialog, target } = openDialog({ id: 'stock', label: 'Stock', render(_panel, c) { ctx = c; } });
		dialog.querySelector('[data-tab="ext:stock"]').click();

		ctx.select(photo);
		ctx.insert();
		const error = dialog.querySelector('[data-panel="ext:stock"] .ste-upload-error');
		await vi.waitFor(() => expect(error.style.display).toBe(''));

		expect(error.textContent).toContain('could not be downloaded');
		expect(target.insertImage).not.toHaveBeenCalled();
		expect(dialog.open).toBe(true);
	});

	test('Insert with nothing selected does nothing', () => {
		vi.stubGlobal('fetch', vi.fn());
		const { dialog, target } = openDialog({ id: 'stock', label: 'Stock', render() {} });
		dialog.querySelector('[data-tab="ext:stock"]').click();

		dialog.querySelector('.ste-dialog-btn--insert').click();

		expect(fetch).not.toHaveBeenCalled();
		expect(target.insertImage).not.toHaveBeenCalled();
	});
});
