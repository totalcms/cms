import TiptapEditor from '../../javascript/totalform/tiptap/TiptapEditor.js';

//-----------------------------------------------
// Tiptap only keeps attributes a node or mark declares, so anything it does not
// know about is dropped on the round-trip through the schema.
//
// Regression target: inline spans lost every attribute except class. A span
// carrying behaviour in data-* came back as
// `<span class="mailto-obfuscated">someone@example.com</span>` — the obfuscated
// mailto markup was reduced to a plaintext address that the decoder then
// skipped, because it needs data-user/data-domain to rebuild the link. Blocks
// were already covered by GlobalAttributes; marks were not.
//-----------------------------------------------

function mount(html) {
	document.body.innerHTML = '';
	const ta = document.createElement('textarea');
	ta.name = 'body';
	ta.value = html;
	document.body.appendChild(ta);
	return new TiptapEditor(ta, {});
}

describe('Tiptap attribute preservation', () => {
	test('keeps data-* and title on an inline span', () => {
		const editor = mount(
			'<p>Write to <span class="mailto-obfuscated" data-user="c3VwcG9ydA==" '
			+ 'data-domain="dG90YWxjbXMuY28=" data-subject="SGVsbG8=" title="Email us">'
			+ 'someone@example.com</span>.</p>'
		);

		const html = editor.getHTML();
		expect(html).toContain('data-user="c3VwcG9ydA=="');
		expect(html).toContain('data-domain="dG90YWxjbXMuY28="');
		expect(html).toContain('data-subject="SGVsbG8="');
		expect(html).toContain('title="Email us"');
		expect(html).toContain('class="mailto-obfuscated"');
	});

	test('keeps data-* on block nodes', () => {
		const editor = mount('<p class="legal-meta" id="meta" data-role="meta">Body</p>');

		const html = editor.getHTML();
		expect(html).toContain('data-role="meta"');
		expect(html).toContain('id="meta"');
		expect(html).toContain('class="legal-meta"');
	});

	test('does not invent attributes on plain content', () => {
		const editor = mount('<p><span class="foo">plain</span></p>');

		const html = editor.getHTML();
		expect(html).toContain('<span class="foo">plain</span>');
		expect(html).not.toContain('data-');
		expect(html).not.toContain('title=');
	});
});
