import GallerySelection from '../../javascript/totalform/gallery-select.js';
import tcmsConfirm from '../../javascript/confirm-dialog';

//-----------------------------------------------
// The gallery field's multi-delete. Delete sends every selected name in ONE
// request to …/{property}/bulk/delete, so the object is saved once however
// many images go.
//-----------------------------------------------

vi.mock('../../javascript/confirm-dialog', () => ({ default: vi.fn(async () => true) }));

function thumbnail(name) {
	const el = document.createElement('div');
	el.className = 'image-preview';
	if (name) el.dataset.imageName = name;
	el.innerHTML = '<div class="dz-preview"><div class="actionbar"></div><img></div>';
	return el;
}

function gallery(names, postAPI = vi.fn(async (api, data) => ({ deleted: data.names, missing: [] }))) {
	const container = document.createElement('div');
	container.innerHTML = `
		<div class="gallery-select-bar">
			<span class="gallery-select-count"></span>
			<button class="gallery-bar-icon gallery-select-all"></button>
			<button class="gallery-bar-icon gallery-delete-selected"></button>
			<button class="gallery-bar-icon gallery-select-done"></button>
		</div>
		<div class="total-preview"></div>
		<button class="gallery-select" aria-pressed="false"></button>`;
	document.body.appendChild(container);

	const previewContainer = container.querySelector('.total-preview');
	names.forEach(name => previewContainer.appendChild(thumbnail(name)));

	const g = {
		container,
		previewContainer,
		property: 'photos',
		activePreview: null,
		closeSharedDialog: vi.fn(),
		imageDataStore: new Map(names.filter(Boolean).map(name => [name, { name }])),
		form: { collection: 'shoots', id: 'one', isEditMode: () => true, api: { postAPI } },
	};
	g.selection = new GallerySelection(g);
	return g;
}

const click = (el, opts = {}) => el.querySelector('img').dispatchEvent(new MouseEvent('click', { bubbles: true, ...opts }));
const preview = (g, name) => g.previewContainer.querySelector(`[data-image-name="${name}"]`);
const names = g => Array.from(g.previewContainer.children).map(el => el.dataset.imageName ?? '');

afterEach(() => {
	document.body.innerHTML = '';
	vi.clearAllMocks();
});

describe('gallery selection mode', () => {
	test('a thumbnail click selects instead of reaching the edit dialog', () => {
		const g = gallery(['a.png', 'b.png']);
		const openEdit = vi.fn();
		preview(g, 'a.png').querySelector('img').addEventListener('click', openEdit);

		g.container.querySelector('.gallery-select').click();
		click(preview(g, 'a.png'));

		expect(openEdit).not.toHaveBeenCalled();
		expect([...g.selection.selected]).toEqual(['a.png']);
		expect(preview(g, 'a.png').classList.contains('selected')).toBe(true);
		expect(g.container.classList.contains('gallery-selecting')).toBe(true);
	});

	test('outside selection mode a click goes through', () => {
		const g = gallery(['a.png']);
		const openEdit = vi.fn();
		preview(g, 'a.png').querySelector('img').addEventListener('click', openEdit);

		click(preview(g, 'a.png'));

		expect(openEdit).toHaveBeenCalledOnce();
		expect(g.selection.selected.size).toBe(0);
	});

	test('the Select button toggles selection mode', () => {
		const g = gallery(['a.png']);
		const button = g.container.querySelector('.gallery-select');

		button.click();
		expect(g.selection.active).toBe(true);
		expect(button.getAttribute('aria-pressed')).toBe('true');

		button.click();
		expect(g.selection.active).toBe(false);
		expect(button.getAttribute('aria-pressed')).toBe('false');
	});

	test.each(['metaKey', 'ctrlKey', 'shiftKey'])('a %s click on a thumbnail starts selecting with that image', (key) => {
		const g = gallery(['a.png', 'b.png']);
		const openEdit = vi.fn();
		preview(g, 'b.png').querySelector('img').addEventListener('click', openEdit);

		click(preview(g, 'b.png'), { [key]: true });

		expect(openEdit).not.toHaveBeenCalled();
		expect(g.selection.active).toBe(true);
		expect([...g.selection.selected]).toEqual(['b.png']);
	});

	test('a modified click on an action bar button is left to the button', () => {
		const g = gallery(['a.png']);
		const actionbar = preview(g, 'a.png').querySelector('.actionbar');
		actionbar.innerHTML = '<button class="edit"></button>';

		actionbar.querySelector('button').dispatchEvent(new MouseEvent('click', { bubbles: true, metaKey: true }));

		expect(g.selection.active).toBe(false);
	});

	test('a modified click starts nothing outside edit mode', () => {
		const g = gallery(['a.png']);
		g.form.isEditMode = () => false;

		click(preview(g, 'a.png'), { metaKey: true });

		expect(g.selection.active).toBe(false);
		expect(g.selection.selected.size).toBe(0);
	});

	test('shift-click selects the range, in page order', () => {
		const g = gallery(['a.png', 'b.png', 'c.png', 'd.png']);
		g.selection.start();

		click(preview(g, 'd.png'));
		click(preview(g, 'b.png'), { shiftKey: true });

		expect([...g.selection.selected].sort()).toEqual(['b.png', 'c.png', 'd.png']);
	});

	test('an upload still running cannot be selected or selected all', () => {
		const g = gallery(['a.png', null]);
		g.selection.start();

		click(g.previewContainer.children[1]);
		expect(g.selection.selected.size).toBe(0);

		g.selection.toggleAll();
		expect([...g.selection.selected]).toEqual(['a.png']);

		g.selection.toggleAll();
		expect(g.selection.selected.size).toBe(0);
	});

	test('Done and Esc leave selection mode and clear it', () => {
		const g = gallery(['a.png']);
		g.selection.start();
		click(preview(g, 'a.png'));

		document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

		expect(g.selection.active).toBe(false);
		expect(g.selection.selected.size).toBe(0);
		expect(preview(g, 'a.png').classList.contains('selected')).toBe(false);
	});
});

describe('deleting the selection', () => {
	test('sends every selected name in one request and removes those thumbnails', async () => {
		const g = gallery(['a.png', 'b.png', 'c.png']);
		g.selection.start();
		click(preview(g, 'a.png'));
		click(preview(g, 'c.png'));

		await g.selection.deleteSelected();

		const postAPI = g.form.api.postAPI;
		expect(postAPI).toHaveBeenCalledOnce();
		expect(postAPI).toHaveBeenCalledWith('/collections/shoots/one/photos/bulk/delete', { names: ['a.png', 'c.png'] });
		expect(names(g)).toEqual(['b.png']);
		expect([...g.imageDataStore.keys()]).toEqual(['b.png']);
		expect(g.selection.active).toBe(false);
	});

	test('names the server no longer holds go from the page too', async () => {
		const g = gallery(['a.png', 'b.png'], vi.fn(async () => ({ deleted: ['a.png'], missing: ['b.png'] })));
		g.selection.start();
		g.selection.toggleAll();

		await g.selection.deleteSelected();

		expect(names(g)).toEqual([]);
	});

	test('a refused confirmation sends nothing', async () => {
		tcmsConfirm.mockResolvedValueOnce(false);
		const g = gallery(['a.png']);
		g.selection.start();
		click(preview(g, 'a.png'));

		await g.selection.deleteSelected();

		expect(g.form.api.postAPI).not.toHaveBeenCalled();
		expect(names(g)).toEqual(['a.png']);
	});

	test('a failed request keeps the images and the selection', async () => {
		const alert = vi.spyOn(window, 'alert').mockImplementation(() => {});
		vi.spyOn(console, 'error').mockImplementation(() => {});
		const g = gallery(['a.png', 'b.png'], vi.fn(async () => { throw new Error('offline'); }));
		g.selection.start();
		click(preview(g, 'a.png'));

		await g.selection.deleteSelected();

		expect(alert).toHaveBeenCalledOnce();
		expect(names(g)).toEqual(['a.png', 'b.png']);
		expect([...g.selection.selected]).toEqual(['a.png']);
		expect(g.selection.active).toBe(true);
		expect(g.selection.busy).toBe(false);
	});

	test('closes the shared edit dialog when its image is deleted', async () => {
		const g = gallery(['a.png', 'b.png']);
		g.activePreview = { getImageName: () => 'a.png' };
		g.selection.start();
		click(preview(g, 'a.png'));

		await g.selection.deleteSelected();

		expect(g.closeSharedDialog).toHaveBeenCalledOnce();
	});
});
