import GalleryField from '../../javascript/totalform/gallery.js';
import GalleryPreview from '../../javascript/totalform/gallery-preview.js';

//-----------------------------------------------
// A gallery thumbnail has no name until its upload finishes, and keeps none
// when the upload fails. Its delete went to `…/{property}/`, which the server
// read as the whole property: every image in the gallery was deleted.
//-----------------------------------------------

vi.mock('../../javascript/confirm-dialog', () => ({ default: vi.fn(async () => true) }));

function thumbnail(name) {
	const el = document.createElement('div');
	el.className = 'image-preview';
	if (name) el.dataset.imageName = name;
	el.innerHTML = `<div class="dz-preview"><div class="actionbar">
		<button class="edit"></button><button class="links"></button><button class="featured"></button>
		<button class="clear"></button><button class="trash"></button></div><img></div>`;
	return el;
}

function field(...names) {
	const g = Object.create(GalleryField.prototype);
	g.container = document.createElement('div');
	g.previewContainer = document.createElement('div');
	g.property = 'photos';
	g.form = {
		collection: 'shoots',
		id: 'one',
		isEditMode: () => true,
		api: { postAPI: vi.fn(async () => ({})) },
	};
	g.swapServerPreview = () => {};
	g.setupReorder = () => {};
	g.droplet = { dropzone: { files: [], removeFile: vi.fn(file => file.previewElement.remove()) } };
	g.imageDataStore = new Map(names.filter(Boolean).map(name => [name, { name }]));
	names.forEach(name => {
		const el = thumbnail(name);
		g.previewContainer.append(el);
		new GalleryPreview(el, g);
	});
	return g;
}

const flush = () => new Promise(resolve => setTimeout(resolve, 0));
const click = (el, button) => el.querySelector(`.actionbar .${button}`).click();

describe('a thumbnail without a name', () => {
	test.each(['trash', 'featured', 'clear'])('sends no request for %s', async button => {
		const g = field('a.jpg', '');

		click(g.previewContainer.children[1], button);
		await flush();

		expect(g.form.api.postAPI).not.toHaveBeenCalled();
	});

	test('is taken off the page when deleted, and nothing else is', async () => {
		const g = field('a.jpg', '');

		click(g.previewContainer.children[1], 'trash');
		await flush();

		expect(Array.from(g.previewContainer.children).map(el => el.dataset.imageName)).toEqual(['a.jpg']);
		expect(g.imageDataStore.has('a.jpg')).toBe(true);
	});

	test('is taken out of the upload queue when deleted', async () => {
		const g = field('a.jpg', '');
		const queued = { previewElement: g.previewContainer.children[1] };
		g.droplet.dropzone.files = [queued];

		click(queued.previewElement, 'trash');
		await flush();

		expect(g.droplet.dropzone.removeFile).toHaveBeenCalledWith(queued);
		expect(g.previewContainer.children).toHaveLength(1);
	});

	test('is left out of the saved value', () => {
		expect(field('a.jpg', '', 'b.jpg').getValue()).toEqual([{ name: 'a.jpg' }, { name: 'b.jpg' }]);
	});
});

test('a named thumbnail still deletes itself', async () => {
	const g = field('a.jpg', 'b.jpg');

	click(g.previewContainer.children[0], 'trash');
	await flush();

	expect(g.form.api.postAPI).toHaveBeenCalledWith('/collections/shoots/one/photos/a.jpg', '', 'DELETE');
	expect(Array.from(g.previewContainer.children).map(el => el.dataset.imageName)).toEqual(['b.jpg']);
});

//-----------------------------------------------
// The server renames files ("Hochzeit 001.jpg" is stored as "Hochzeit_001.jpg")
// and says which name it gave. Guessing it from the gallery's list gave a
// thumbnail another image's name, or none, when responses came out of order.
//-----------------------------------------------
describe('GalleryField.fileUploaded', () => {
	const response = (name, ...all) => ({ data: { photos: all.map(n => ({ name: n })) }, meta: { name } });

	function upload(g, name) {
		const previewElement = thumbnail('');
		g.previewContainer.append(previewElement);
		return { name, previewElement };
	}

	test('names each thumbnail after its own file when responses arrive out of order', () => {
		const g = field();
		const one = upload(g, 'Hochzeit 001.jpg');
		const two = upload(g, 'Hochzeit 002.jpg');

		g.fileUploaded(two, response('Hochzeit_002.jpg', 'Hochzeit_001.jpg', 'Hochzeit_002.jpg'));
		g.fileUploaded(one, response('Hochzeit_001.jpg', 'Hochzeit_001.jpg'));

		expect(one.previewElement.dataset.imageName).toBe('Hochzeit_001.jpg');
		expect(two.previewElement.dataset.imageName).toBe('Hochzeit_002.jpg');
		expect(g.getValue()).toEqual([{ name: 'Hochzeit_001.jpg' }, { name: 'Hochzeit_002.jpg' }]);
	});

	test('leaves the thumbnail without a name when the response gives none', () => {
		const g = field();
		const one = upload(g, 'photo.jpg');

		g.fileUploaded(one, { data: { photos: [{ name: 'other.jpg' }] } });

		expect(one.previewElement.dataset.imageName).toBeUndefined();
	});
});
