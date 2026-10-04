import StyledMarkdownField from '../../javascript/totalform/styledmarkdown.js';
import StyledTextField from '../../javascript/totalform/styledtext.js';
import TiptapMarkdownEditor from '../../javascript/totalform/tiptap/TiptapMarkdownEditor.js';

//-----------------------------------------------
// StyledMarkdownField is the styledtext field with a markdown editor. The methods
// are called on plain objects: constructing a real field needs a whole form.
//-----------------------------------------------

function textarea(value) {
	document.body.innerHTML = '';
	const ta = document.createElement('textarea');
	ta.value = value;
	document.body.appendChild(ta);

	return ta;
}

describe('StyledMarkdownField', () => {
	test('is the styledtext field with a markdown editor', () => {
		const editor = StyledMarkdownField.prototype.createEditor.call({ input: textarea('# Hi'), settings: {} });

		expect(StyledMarkdownField.prototype).toBeInstanceOf(StyledTextField);
		expect(editor).toBeInstanceOf(TiptapMarkdownEditor);
	});

	test('reads and writes the markdown string, not HTML', () => {
		const input = textarea('* one');
		const field = { input, settings: {}, changed: vi.fn() };
		field.tiptap = StyledMarkdownField.prototype.createEditor.call(field);

		expect(StyledMarkdownField.prototype.getValue.call(field)).toBe('* one');

		StyledMarkdownField.prototype.setValue.call(field, '## Two');

		expect(input.value).toBe('## Two');
		expect(StyledMarkdownField.prototype.getValue.call(field)).toBe('## Two');
		expect(field.changed).toHaveBeenCalled();
	});

	test('falls back to the input before the editor exists', () => {
		expect(StyledMarkdownField.prototype.getValue.call({ input: textarea('raw'), tiptap: null })).toBe('raw');
	});

	test('declares a string property', () => {
		expect(StyledMarkdownField.prototype.schema.call({})).toEqual({ type: 'string', field: 'styledmarkdown' });
	});
});
