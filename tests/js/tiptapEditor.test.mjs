import TiptapEditor from '../../javascript/totalform/tiptap/TiptapEditor.js';

//-----------------------------------------------
// TiptapEditor mounts a real ProseMirror editor (jsdom env).
//
// Regression target: a transaction can dispatch onUpdate during the editor's
// initial mount, before `this.editor` is assigned (and the old code destroyed
// and recreated the editor, leaving a torn-down instance in `this.editor`).
// syncToTextarea() then called `this.editor.getHTML()` on a null/destroyed
// editor → DOMSerializer.fromSchema(null) → "Cannot read properties of null".
//-----------------------------------------------

function makeTextarea(value) {
	document.body.innerHTML = '';
	const ta = document.createElement('textarea');
	ta.name = 'notes';
	ta.value = value;
	document.body.appendChild(ta);
	return ta;
}

describe('TiptapEditor', () => {
	test('mounts list content without throwing and syncs the textarea (single-creation path)', () => {
		const ta = makeTextarea('<p>Intro</p><ul><li>one</li><li>two</li></ul>');

		let editor;
		expect(() => { editor = new TiptapEditor(ta, {}); }).not.toThrow();
		expect(editor.getHTML()).toMatch(/<ul><li>one<\/li>/);
		expect(ta.value).toMatch(/<ul>/);
	});

	test('syncToTextarea uses the editor handed to onUpdate even before this.editor is assigned', () => {
		const ta = makeTextarea('<p>hi</p>');
		const editor = new TiptapEditor(ta, {});
		const live = editor.editor;

		// Simulate a mount-time onUpdate: the instance property isn't set yet.
		editor.editor = null;
		ta.value = 'STALE';

		expect(() => editor.syncToTextarea(live)).not.toThrow();
		expect(ta.value).toMatch(/<p>hi<\/p>/); // synced from the live editor passed to the callback
	});

	test('syncToTextarea is a no-op (no throw) when the editor is null or destroyed', () => {
		const ta = makeTextarea('<p>hi</p>');
		const editor = new TiptapEditor(ta, {});
		const live = editor.editor;

		ta.value = 'KEEP';
		editor.editor = null;
		expect(() => editor.syncToTextarea()).not.toThrow(); // null editor guarded
		expect(ta.value).toBe('KEEP');                        // textarea left untouched

		live.destroy();
		expect(() => editor.syncToTextarea(live)).not.toThrow(); // destroyed editor guarded
	});

	test('getHTML falls back to the textarea value when the editor is unavailable', () => {
		const ta = makeTextarea('<p>fallback</p>');
		const editor = new TiptapEditor(ta, {});

		editor.editor.destroy();
		expect(() => editor.getHTML()).not.toThrow();
		expect(editor.getHTML()).toBe(ta.value); // returns the last-synced textarea value
	});
});
