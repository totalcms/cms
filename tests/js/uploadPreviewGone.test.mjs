import FileField from '../../javascript/totalform/file.js';
import ImageField from '../../javascript/totalform/image.js';

//-----------------------------------------------
// TOTALCMS-DASHBOARD-5G: deleting a file while its upload is still running
// removes the preview element, and when the upload finishes fileUploaded()
// rebuilt the preview from previewContainer.children.item(0) — null — and
// crashed with "Cannot read properties of null (reading 'preview')".
// setupPreview() must leave the field alone when there is no preview.
//-----------------------------------------------

describe.each([
	['FileField', FileField],
	['ImageField', ImageField],
])('%s.setupPreview() with the preview removed', (_name, Field) => {
	it('does not throw and keeps the existing preview', () => {
		const existing = { fields: [] };
		const field = Object.create(Field.prototype);
		field.previewContainer = document.createElement('div'); // emptied by the delete
		field.preview = existing;

		expect(() => field.setupPreview({ name: 'photo.jpg' })).not.toThrow();
		expect(field.preview).toBe(existing);
	});
});
