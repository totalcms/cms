/**
 * Where an upload dialog puts its result. The dialogs used to take a Tiptap
 * editor; the markdown source field has none, so they take one of these.
 *
 * A target is { getContent(), insertImage({src, alt, title}), insertFileLink({href, text}) }.
 * getContent() is what the dialog searches to warn that a file is still in use.
 * `title` is optional (a stock photo's credit).
 */
export function tiptapInsertTarget(editor) {
	return {
		getContent: () => editor.getHTML(),
		insertImage: ({ src, alt, title }) => {
			editor.chain().focus().setImage({ src, alt: alt || undefined, title: title || undefined }).run();
		},
		insertFileLink: ({ href, text }) => {
			editor.chain().focus().insertContent(
				`<a href="${href}" target="_blank" rel="noopener">${text}</a>`
			).run();
		},
	};
}
