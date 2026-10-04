import { tiptapInsertTarget } from '../../javascript/totalform/tiptap/extensions/insertTargets.js';

// A stand-in for editor.chain().focus().<command>(...).run()
function fakeEditor() {
	const calls = [];
	const chain = {
		focus: () => chain,
		setImage: (attrs) => { calls.push(['setImage', attrs]); return chain; },
		insertContent: (html) => { calls.push(['insertContent', html]); return chain; },
		run: () => true,
	};

	return { calls, chain: () => chain, getHTML: () => '<p>content</p>' };
}

describe('tiptapInsertTarget', () => {
	test('reads the editor content', () => {
		expect(tiptapInsertTarget(fakeEditor()).getContent()).toBe('<p>content</p>');
	});

	test('inserts an image node, leaving out an empty alt', () => {
		const editor = fakeEditor();
		const target = tiptapInsertTarget(editor);

		target.insertImage({ src: '/a.png', alt: 'A' });
		target.insertImage({ src: '/b.png', alt: '' });

		expect(editor.calls).toEqual([
			['setImage', { src: '/a.png', alt: 'A' }],
			['setImage', { src: '/b.png', alt: undefined }],
		]);
	});

	test('inserts a file link as the same anchor markup as before', () => {
		const editor = fakeEditor();

		tiptapInsertTarget(editor).insertFileLink({ href: '/files/a.pdf', text: 'A file' });

		expect(editor.calls).toEqual([
			['insertContent', '<a href="/files/a.pdf" target="_blank" rel="noopener">A file</a>'],
		]);
	});
});
