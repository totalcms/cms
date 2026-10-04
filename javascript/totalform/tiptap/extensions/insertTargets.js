/**
 * Where an upload dialog puts its result. The dialogs used to take a Tiptap
 * editor; the markdown source field has none, so they take one of these.
 *
 * A target is { getContent(), insertImage({src, alt}), insertFileLink({href, text}) }.
 * getContent() is what the dialog searches to warn that a file is still in use.
 */
export function tiptapInsertTarget(editor) {
	return {
		getContent: () => editor.getHTML(),
		insertImage: ({ src, alt }) => {
			editor.chain().focus().setImage({ src, alt: alt || undefined }).run();
		},
		insertFileLink: ({ href, text }) => {
			editor.chain().focus().insertContent(
				`<a href="${href}" target="_blank" rel="noopener">${text}</a>`
			).run();
		},
	};
}
