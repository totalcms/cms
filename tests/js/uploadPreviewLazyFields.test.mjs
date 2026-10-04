import FileField from '../../javascript/totalform/file.js';
import ImageField from '../../javascript/totalform/image.js';

//-----------------------------------------------
// On a page running forms.js, heavy field classes load on demand. The preview
// a fresh upload creates contains a `list` sub-field (tags), so right after
// the upload answers that sub-field has no field object yet. setupPreview()
// used to write the server's file data into the sub-fields immediately and
// threw "Cannot read properties of undefined (reading 'property')". The data
// never reached the form, and the next save stored the empty sub-fields over
// the file that had just been uploaded.
//-----------------------------------------------

// A preview element that already has its preview object, so setupPreview()
// reuses it instead of building a real FilePreview / ImagePreview.
function fieldWithPreview(Field, form) {
	const preview = { fields: [], setValue: vi.fn() };
	const element = document.createElement('div');
	element.preview = preview;

	const field = Object.create(Field.prototype);
	field.previewContainer = document.createElement('div');
	field.previewContainer.appendChild(element);
	field.form = form;

	return { field, preview };
}

describe.each([
	['FileField', FileField],
	['ImageField', ImageField],
])('%s.setupPreview() while sub-field classes are still loading', (_name, Field) => {
	it('waits for them, then writes the uploaded data into the preview', async () => {
		let finishLoading;
		const loading = new Promise((resolve) => { finishLoading = resolve; });
		const form = { pending: new Set([loading]), whenReady: () => loading };
		const { field, preview } = fieldWithPreview(Field, form);
		const uploaded = { name: 'id.pdf', size: 133307 };

		expect(() => field.setupPreview(uploaded)).not.toThrow();
		expect(field.preview).toBe(preview);
		expect(preview.setValue).not.toHaveBeenCalled();

		finishLoading();
		await loading;
		await Promise.resolve();

		expect(preview.setValue).toHaveBeenCalledWith(uploaded);
	});

	it('writes the data straight away when nothing is loading', () => {
		const form = { pending: new Set(), whenReady: () => Promise.resolve() };
		const { field, preview } = fieldWithPreview(Field, form);

		field.setupPreview({ name: 'id.pdf' });

		expect(preview.setValue).toHaveBeenCalledWith({ name: 'id.pdf' });
	});
});
