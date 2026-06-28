import TiptapEditor from '../../javascript/totalform/tiptap/TiptapEditor.js';

//-----------------------------------------------
// cleanHTML() normalises ProseMirror output before it hits the textarea:
// ProseMirror wraps list-item content in <p>, but the saved markup should be
// <li>text</li>; and a trailing empty paragraph (the editor's trailing line)
// should be dropped. It's a pure string->string transform.
//-----------------------------------------------

const clean = (html) => Object.create(TiptapEditor.prototype).cleanHTML(html);

describe('TiptapEditor.cleanHTML', () => {
	test('unwraps a single <p> inside a list item', () => {
		expect(clean('<ul><li><p>one</p></li></ul>')).toBe('<ul><li>one</li></ul>');
	});

	test('leaves multi-paragraph list items untouched', () => {
		const html = '<ul><li><p>a</p><p>b</p></li></ul>';
		expect(clean(html)).toBe(html);
	});

	test('strips trailing empty paragraphs (including whitespace-only)', () => {
		expect(clean('<p>kept</p><p></p>')).toBe('<p>kept</p>');
		expect(clean('<p>kept</p><p>   </p><p></p>')).toBe('<p>kept</p>');
	});

	test('keeps trailing paragraphs that have content', () => {
		expect(clean('<p>a</p><p>b</p>')).toBe('<p>a</p><p>b</p>');
	});
});
