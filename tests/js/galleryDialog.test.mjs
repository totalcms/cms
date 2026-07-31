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
// Closing the shared dialog must mark the gallery field itself dirty. The
// shared dialog lives OUTSIDE previewContainer, so dirty dialog subfields are
// invisible to isUnsaved() — commitSharedDialog() therefore flags the gallery
// via changed() once the data store is updated. Without this, editing the
// focal point via the drag target (which fires no native change event that
// could bubble to the gallery container) never enables autosave or Save/Cmd+S.
//-----------------------------------------------
describe('GalleryField.commitSharedDialog', () => {
	function galleryForCommit(storeImage, dialogValues, dirtyKeys = []) {
		const g = Object.create(GalleryField.prototype);
		g.container = document.createElement('div');
		g.input = document.createElement('input');
		g.dispatcher = { dispatchEvent: vi.fn() };
		g.form = { isEditMode: () => true };
		g.previewContainer = document.createElement('div');
		const child = document.createElement('div');
		child.preview = {};
		child.dataset.imageName = storeImage.name;
		g.previewContainer.appendChild(child);
		g.imageDataStore = new Map([[storeImage.name, storeImage]]);
		g.storedValue = g.getValue();
		g.sharedDialogFields = Object.entries(dialogValues).map(([property, value]) => ({
			totalfield: {
				property,
				getValue  : () => value,
				isUnsaved : () => dirtyKeys.includes(property),
			},
		}));
		g.activePreview = {
			getImageName: () => storeImage.name,
			container: document.createElement('div'),
		};
		return g;
	}

	test('marks the gallery unsaved when dialog fields were edited (focal point drag target)', () => {
		const g = galleryForCommit(
			{ name: 'pic.jpg', focalpoint: { x: 50, y: 50 }, exif: { nodata: '' } },
			{ 'name': 'pic.jpg', 'focalpoint-x': 30, 'focalpoint-y': 70 },
			['focalpoint-x', 'focalpoint-y'],
		);

		g.commitSharedDialog();

		expect(g.imageDataStore.get('pic.jpg').focalpoint).toEqual({ x: 30, y: 70 });
		expect(g.container.classList.contains('unsaved')).toBe(true);
		expect(g.isUnsaved()).toBe(true);
	});

	test('leaves the store and dirty state untouched when no dialog field was edited', () => {
		const original = { name: 'pic.jpg', focalpoint: { x: 50, y: 50 }, exif: { nodata: '' } };
		const g = galleryForCommit(
			original,
			// Dialog rebuild would differ in shape from the server-loaded object —
			// without the dirty-field gate this would false-positive as a change.
			{ 'name': 'pic.jpg', 'focalpoint-x': 50, 'focalpoint-y': 50 },
		);

		g.commitSharedDialog();

		expect(g.imageDataStore.get('pic.jpg')).toBe(original);
		expect(g.container.classList.contains('unsaved')).toBe(false);
	});

	test('stays clean when edits net out to the same value', () => {
		const g = galleryForCommit(
			{ name: 'pic.jpg', focalpoint: { x: 50, y: 50 }, exif: { nodata: '' } },
			{ 'name': 'pic.jpg', 'focalpoint-x': 50, 'focalpoint-y': 50 },
			['focalpoint-x'], // e.g. dragged 50 -> 30 -> back to 50
		);
		// Baseline matches what the dialog rebuild produces
		g.imageDataStore.set('pic.jpg', g.readSharedDialog());
		g.storedValue = g.getValue();

		g.commitSharedDialog();

		expect(g.container.classList.contains('unsaved')).toBe(false);
	});

	test('does nothing without an active preview', () => {
		const g = galleryForCommit(
			{ name: 'pic.jpg', focalpoint: { x: 50, y: 50 }, exif: { nodata: '' } },
			{ 'name': 'other.jpg', 'focalpoint-x': 1, 'focalpoint-y': 2 },
			['focalpoint-x'],
		);
		g.activePreview = null;

		g.commitSharedDialog();

		expect(g.imageDataStore.get('pic.jpg').focalpoint).toEqual({ x: 50, y: 50 });
		expect(g.container.classList.contains('unsaved')).toBe(false);
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
