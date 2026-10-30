import { describe, expect, it, vi } from 'vite-plus/test';
import { PHOTO_TYPE, fileName, hasPhotos, insertPhotos, photoMeta, readPhotos } from '../../javascript/totalform/photo-drop.js';

//-----------------------------------------------
// Photo drop (photo-drop.js): the public contract a stock-photo panel or any
// other photo source uses to put photos on an image or gallery field.
//-----------------------------------------------

const transfer = (data, types = [PHOTO_TYPE]) => ({ types, getData: type => (type === PHOTO_TYPE ? data : '') });

const okFetch = vi.fn(async () => ({ ok: true, blob: async () => new Blob(['x'], { type: 'image/jpeg' }) }));

describe('reading a drop', () => {
	it('reads the photos a drop carries', () => {
		const photos = readPhotos(transfer(JSON.stringify({ photos: [{ url: '/a.jpg' }, { alt: 'no url' }] })));
		expect(photos).toEqual([{ url: '/a.jpg' }]);
	});

	it('ignores drops of other types and payloads that do not parse', () => {
		expect(hasPhotos(transfer('', ['Files']))).toBe(false);
		expect(readPhotos(transfer('', ['Files']))).toEqual([]);
		expect(readPhotos(transfer('{not json'))).toEqual([]);
		expect(readPhotos(transfer(JSON.stringify({ url: '/a.jpg' })))).toEqual([]);
	});
});

describe('turning photos into files', () => {
	it('keeps alt and credit as the meta that rides with the upload', () => {
		expect(photoMeta({ url: '/a.jpg', alt: '  Burrata ', credit: { source: 'Pixabay' }, width: 10 }))
			.toEqual({ alt: 'Burrata', credit: { source: 'Pixabay' } });
		expect(photoMeta({ url: '/a.jpg', alt: ' ' })).toEqual({});
	});

	it('also carries tags, link and focal point, and drops empty or malformed ones', () => {
		expect(photoMeta({ url: '/a.jpg', tags: ['starter'], link: ' https://example.com/menu ', focalpoint: { x: 30, y: 60 } }))
			.toEqual({ tags: ['starter'], link: 'https://example.com/menu', focalpoint: { x: 30, y: 60 } });
		expect(photoMeta({ url: '/a.jpg', tags: [], link: '', focalpoint: [1, 2], credit: 'Ana' })).toEqual({});
	});

	it('names the file from the photo, else its URL, with an extension for its type', () => {
		expect(fileName({ url: '/x', filename: 'burrata plate' }, 'image/jpeg')).toBe('burrata-plate.jpg');
		expect(fileName({ url: 'https://cdn.example.com/photos/dish.webp?w=640' }, 'image/webp')).toBe('dish.webp');
		expect(fileName({ url: 'https://cdn.example.com/' }, 'image/png')).toBe('photo.png');
	});
});

describe('insertPhotos', () => {
	const droplet = singleMode => ({ settings: { singleMode }, addFiles: vi.fn() });

	it('gives a gallery every photo, in order, with its meta', async () => {
		vi.stubGlobal('fetch', okFetch);
		const gallery = droplet(false);
		const count = await insertPhotos(gallery, { photos: [{ url: '/one.jpg', alt: 'One' }, { url: '/two.jpg' }] });

		const files = gallery.addFiles.mock.calls[0][0];
		expect(count).toBe(2);
		expect(files.map(file => file.name)).toEqual(['one.jpg', 'two.jpg']);
		expect(files[0].tcmsMeta).toEqual({ alt: 'One' });
		vi.unstubAllGlobals();
	});

	it('gives an image field only the first photo and fetches nothing else', async () => {
		const fetch = vi.fn(okFetch);
		vi.stubGlobal('fetch', fetch);
		const image = droplet(true);
		await insertPhotos(image, { photos: [{ url: '/one.jpg' }, { url: '/two.jpg' }] });

		expect(fetch).toHaveBeenCalledTimes(1);
		expect(image.addFiles.mock.calls[0][0]).toHaveLength(1);
		vi.unstubAllGlobals();
	});

	it('skips a photo that fails to download and keeps the rest', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {});
		vi.stubGlobal('fetch', vi.fn(async url => (url === '/bad.jpg' ? { ok: false, status: 404 } : okFetch())));
		const gallery = droplet(false);
		const count = await insertPhotos(gallery, { photos: [{ url: '/bad.jpg' }, { url: '/good.jpg' }] });

		expect(count).toBe(1);
		expect(gallery.addFiles.mock.calls[0][0][0].name).toBe('good.jpg');
		vi.unstubAllGlobals();
	});
});
