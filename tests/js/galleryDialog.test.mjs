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
