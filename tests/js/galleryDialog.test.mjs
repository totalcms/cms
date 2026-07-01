import GalleryField from '../../javascript/totalform/gallery.js';

//-----------------------------------------------
// GalleryField.readSharedDialog() rebuilds one image's nested data object from
// the flat, prefixed dialog fields (exif-*, focalpoint-*, palette-N). This is
// the inverse of the flattening that caused trouble elsewhere, so it's worth
// pinning. It only reads this.sharedDialogFields, so we can drive it with stubs.
//-----------------------------------------------

function readDialog(fields) {
	const gallery = Object.create(GalleryField.prototype);
	gallery.sharedDialogFields = fields.map(([property, value]) => ({
		totalfield: { property, getValue: () => value },
	}));
	return gallery.readSharedDialog();
}

describe('GalleryField.readSharedDialog', () => {
	test('reconstructs nested exif / focalpoint / palette from flat prefixed fields', () => {
		const data = readDialog([
			['name', 'pic.jpg'],
			['alt', 'a photo'],
			['focalpoint-x', 50],
			['focalpoint-y', 60],
			['exif-author', 'Jane'],
			['exif-camera', 'Canon'],
			['palette-0', { hex: '#ffffff' }],
			['palette-1', { hex: '#000000' }],
		]);

		expect(data).toEqual({
			name: 'pic.jpg',
			alt: 'a photo',
			focalpoint: { x: 50, y: 60 },
			exif: { author: 'Jane', camera: 'Canon' },
			palette: ['#ffffff', '#000000'],
		});
	});

	test('drops empty exif values and falls back to {nodata} when there is no exif', () => {
		const data = readDialog([
			['name', 'pic.jpg'],
			['exif-author', ''], // empty exif value is dropped
		]);

		expect(data).toEqual({ name: 'pic.jpg', exif: { nodata: '' } });
	});
});

//-----------------------------------------------
// ROUND-TRIP: populateSharedDialog() flattens an image into the dialog fields and
// readSharedDialog() rebuilds it. If that isn't lossless, editing an image in a
// gallery silently corrupts its data on save — the highest-stakes failure here.
//-----------------------------------------------
function galleryWithFields(fieldKeys) {
	const plain = (property) => {
		let stored;
		return { totalfield: { property, setValue: (v) => { stored = v; }, getValue: () => stored, saved: () => {} } };
	};
	// palette-* are color fields: setValue takes a hex string, getValue returns {hex}.
	const color = (property) => {
		let stored = '';
		return { totalfield: { property, setValue: (v) => { stored = v; }, getValue: () => ({ hex: stored }), saved: () => {} } };
	};

	const gallery = Object.create(GalleryField.prototype);
	gallery.sharedDialogFields = fieldKeys.map((k) => (k.startsWith('palette-') ? color(k) : plain(k)));
	gallery.sharedDialog = { dialog: document.createElement('div') };
	gallery.activePreview = { container: document.createElement('div') };
	return gallery;
}

describe('GalleryField populate -> read round-trip', () => {
	test('preserves a fully-populated image exactly', () => {
		const fields = ['name', 'alt', 'link', 'focalpoint-x', 'focalpoint-y', 'exif-author', 'exif-camera', 'palette-0', 'palette-1'];
		const gallery = galleryWithFields(fields);

		const original = {
			name: 'pic.jpg',
			alt: 'astronaut',
			link: 'https://example.com',
			focalpoint: { x: 30, y: 70 },
			exif: { author: 'Jane', camera: 'Canon' },
			palette: ['#ffffff', '#000000'],
		};

		gallery.populateSharedDialog(original);
		expect(gallery.readSharedDialog()).toEqual(original);
	});

	test('an image with no exif comes back with the {nodata} placeholder (documented quirk)', () => {
		const gallery = galleryWithFields(['name', 'exif-author']);

		gallery.populateSharedDialog({ name: 'pic.jpg' }); // no exif on the source

		expect(gallery.readSharedDialog()).toEqual({ name: 'pic.jpg', exif: { nodata: '' } });
	});
});

//-----------------------------------------------
// isUnsaved() must report queued droplet files as unsaved. A new-form gallery's
// getValue() is [] until edit mode, so changed() never adds the dirty class —
// without the pending-files check, afterSave() filters the gallery out and the
// deferred uploads never flush.
//-----------------------------------------------
describe('GalleryField.isUnsaved', () => {
	function galleryWith(pending) {
		const g = Object.create(GalleryField.prototype);
		g.droplet = { pendingFiles: () => pending };
		g.container = document.createElement('div');
		g.previewContainer = document.createElement('div');
		return g;
	}

	test('true when the droplet has queued/uploading files', () => {
		expect(galleryWith([{}]).isUnsaved()).toBe(true);
	});

	test('false when there are no pending files and nothing is dirty', () => {
		expect(galleryWith([]).isUnsaved()).toBe(false);
	});
});
