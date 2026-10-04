import MarkdownField from '../../javascript/totalform/markdown.js';
import MarkdownSourceEditor from '../../javascript/totalform/markdown/MarkdownSourceEditor.js';
import TotalField from '../../javascript/totalform/totalfield.js';
import { editorUploadUrl } from '../../javascript/totalform/editor-uploads.js';

function textarea(value) {
	document.body.innerHTML = '';
	const ta = document.createElement('textarea');
	ta.value = value;
	document.body.appendChild(ta);

	return ta;
}

describe('MarkdownField', () => {
	test('is a plain field with the source editor, not a styledtext field', () => {
		const editor = MarkdownField.prototype.createEditor.call({ input: textarea('# Hi'), settings: {} });

		expect(Object.getPrototypeOf(MarkdownField.prototype)).toBe(TotalField.prototype);
		expect(editor).toBeInstanceOf(MarkdownSourceEditor);
	});

	test('reads and writes the markdown string', () => {
		const input = textarea('* one');
		const field = { input, settings: {}, changed: vi.fn() };
		field.editor = MarkdownField.prototype.createEditor.call(field);

		expect(MarkdownField.prototype.getValue.call(field)).toBe('* one');

		MarkdownField.prototype.setValue.call(field, '## Two');

		expect(input.value).toBe('## Two');
		expect(MarkdownField.prototype.getValue.call(field)).toBe('## Two');
		expect(field.changed).toHaveBeenCalled();
	});

	test('falls back to the input before the editor exists', () => {
		expect(MarkdownField.prototype.getValue.call({ input: textarea('raw'), editor: null })).toBe('raw');
	});

	test('declares a string property', () => {
		expect(MarkdownField.prototype.schema.call({})).toEqual({ type: 'string', field: 'markdown' });
	});
});

describe('editorUploadUrl', () => {
	const api = { buildApiQuery: (path) => '/api' + path };

	test('builds the upload URL for a top-level property', () => {
		const field = { api, getUploadContext: () => ({ collection: 'notes', id: 'one', property: 'body', subpath: '' }) };

		expect(editorUploadUrl(field)).toBe('/api/upload/notes/one/body');
	});

	test('includes the nested path for a field inside a card or deck', () => {
		const field = { api, getUploadContext: () => ({ collection: 'notes', id: 'one', property: 'cards', subpath: 'item1/body' }) };

		expect(editorUploadUrl(field)).toBe('/api/upload/notes/one/cards/item1/body');
	});

	test('is null until the object can take uploads', () => {
		expect(editorUploadUrl({ api, getUploadContext: () => null })).toBeNull();
	});
});
