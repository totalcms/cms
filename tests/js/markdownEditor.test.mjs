import tcmsConfirm from '../../javascript/confirm-dialog';
import TiptapMarkdownEditor from '../../javascript/totalform/tiptap/TiptapMarkdownEditor.js';

vi.mock('../../javascript/confirm-dialog', () => ({ default: vi.fn() }));

//-----------------------------------------------
// The markdown field's editor. The hidden textarea holds the canonical
// Markdown string; the expected strings in the round-trip tables are what
// Tiptap's serializer writes for each input, with our text-escaping override.
//-----------------------------------------------

function mount(value, options = {}) {
	document.body.innerHTML = '';
	const ta = document.createElement('textarea');
	ta.name = 'body';
	ta.value = value;
	document.body.appendChild(ta);

	return { ta, md: new TiptapMarkdownEditor(ta, options) };
}

// Load markdown and write it straight back out.
function roundTrip(markdown) {
	const { md } = mount('');
	md.editor.commands.setContent(markdown, { contentType: 'markdown' });

	return md.serialize(md.editor);
}

describe('markdown round trip', () => {
	test.each([
		['headings',            '# H1\n\n## H2\n\n###### H6'],
		['emphasis',            '**bold** and *italic* and ~~strike~~ and `code`'],
		['bullet list',         '- one\n- two\n- three'],
		['ordered list',        '1. one\n2. two\n3. three'],
		['nested list',         '- one\n  - nested\n  - nested two\n- two'],
		['task list',           '- [ ] todo\n- [x] done'],
		['link',                '[Total CMS](https://totalcms.co)'],
		['link with title',     '[Total CMS](https://totalcms.co "Title")'],
		['image',               '![Alt text](/img/a.jpg)'],
		['image with title',    '![Alt](/img/a.jpg "Title")'],
		['blockquote',          '> quoted'],
		['code fence',          '```php\necho "hi";\n```'],
		['rule',                'a\n\n---\n\nb'],
		['hard break',          'line one  \nline two'],
		['twig',                'Hello {{ cms.config("name") }} {% if x %}yes{% endif %}'],
		['heading id',          '## Install {#install}'],
		['escaped number',      '2026\\. A good year'],
		['escaped hash',        '\\# not a heading'],
		['ampersand',           'Fish & chips'],
		['comparison',          'a < b > c'],
		['named entity',        'a&nbsp;b'],
	])('%s comes back unchanged', (name, markdown) => {
		expect(roundTrip(markdown)).toBe(markdown);
	});

	test.each([
		['star bullets',      '* one\n* two',                         '- one\n- two'],
		['paren numbers',     '1) one\n2) two',                       '1. one\n2. two'],
		['setext heading',    'Title\n=====',                         '# Title'],
		['underscore marks',  '__bold__ and _italic_',                '**bold** and *italic*'],
		['tilde fence',       '~~~\nplain\n~~~',                      '```\nplain\n```'],
		['reference link',    'See [docs][d].\n\n[d]: https://a.co',  'See [docs](https://a.co).'],
		['soft break',        'line one\nline two',                   'line one  \nline two'],
		['encoded ampersand', 'Fish &amp; chips',                     'Fish & chips'],
	])('%s is normalized', (name, markdown, expected) => {
		expect(roundTrip(markdown)).toBe(expected);
	});

	test('a table keeps its cells', () => {
		const out = roundTrip('| A | B |\n|---|---|\n| 1 | 2 |');

		expect(out).toMatch(/^\| A\s+\| B\s+\|\n\| -+ \| -+ \|\n\| 1\s+\| 2\s+\|$/);
	});
});

describe('the stored value', () => {
	test('is not rewritten by mounting the editor', () => {
		const { ta, md } = mount('* one\n* two');

		expect(ta.value).toBe('* one\n* two');
		expect(md.getValue()).toBe('* one\n* two');
	});

	test('is normalized once the user edits', () => {
		const changed = vi.fn();
		const { ta, md } = mount('* one\n* two', { onContentChanged: changed });

		md.editor.chain().focus('end').insertContent(' more').run();

		expect(ta.value).toBe('- one\n- two more');
		expect(md.getValue()).toBe('- one\n- two more');
		expect(changed).toHaveBeenCalled();
	});

	test('an empty field stays an empty string', () => {
		const { ta, md } = mount('');

		expect(md.getValue()).toBe('');
		md.editor.chain().focus().insertContent('x').run();
		md.editor.commands.clearContent(true);
		expect(ta.value).toBe('');
	});

	test('setValue replaces the content without normalizing it', () => {
		const { ta, md } = mount('old');

		md.setValue('* new');

		expect(ta.value).toBe('* new');
		expect(md.getValue()).toBe('* new');
		expect(md.editor.getText()).toContain('new');
	});
});

describe('the markdown toolbar', () => {
	test('offers only what markdown can express', () => {
		const { md } = mount('');
		const commands = [...md.container.querySelectorAll('.ste-toolbar-btn[data-command]')].map((b) => b.dataset.command);

		expect(commands).toEqual([
			'toggleBold', 'toggleItalic', 'toggleCode',
			'toggleBulletList', 'toggleOrderedList', 'toggleBlockquote', 'toggleCodeBlock', 'setHorizontalRule',
			'openLinkDialog', 'openImageDialog', 'openFileDialog',
			'toggleCodeView',
		]);
		for (const absent of ['toggleUnderline', 'setTextAlign', 'indent', 'outdent', 'openVideoDialog', 'openAnchorDialog', 'unsetAllMarks']) {
			expect(commands, absent).not.toContain(absent);
		}
	});

	test('the schema has no nodes or marks markdown cannot write', () => {
		const { md } = mount('');
		const { marks, nodes } = md.editor.schema;

		for (const mark of ['underline', 'textStyle', 'highlight', 'superscript', 'subscript']) {
			expect(marks[mark], mark).toBeUndefined();
		}
		expect(nodes.taskList).toBeDefined();
		expect(nodes.table).toBeDefined();
	});
});

const FOOTNOTED = 'Claim.[^1]\n\n[^1]: The note.';

// jsdom has no window.TotalCMSCodeMirror, so source mode is the plain textarea
// fallback — the same thing a public form gets without the CodeMirror bundle.
const sourceTextarea = (md) => md.container.querySelector('.ste-code-view textarea');

describe('source mode', () => {
	beforeEach(() => {
		tcmsConfirm.mockReset();
	});

	test('a clean value opens in visual mode', () => {
		const { md } = mount('# Title');

		expect(md.mode).toBe('visual');
		expect(md.noticeEl).toBeNull();
	});

	test('content the visual editor cannot keep opens in source mode, untouched', () => {
		const { ta, md } = mount(FOOTNOTED);

		expect(md.mode).toBe('source');
		expect(sourceTextarea(md).value).toBe(FOOTNOTED);
		expect(md.noticeEl.textContent).toContain('footnotes');
		expect(ta.value).toBe(FOOTNOTED);
		expect(md.getValue()).toBe(FOOTNOTED);
	});

	test('the code view gets a usable height when the wrapper has none', () => {
		const { md } = mount(FOOTNOTED);

		expect(md.container.querySelector('.ste-code-view').style.height).toBe('300px');
	});

	test('typing in source mode updates the stored value', () => {
		const changed = vi.fn();
		const { ta, md } = mount(FOOTNOTED, { onContentChanged: changed });

		sourceTextarea(md).value = FOOTNOTED + '\n\nMore.';
		sourceTextarea(md).dispatchEvent(new Event('input'));

		expect(ta.value).toBe(FOOTNOTED + '\n\nMore.');
		expect(changed).toHaveBeenCalled();
	});

	test('switching to visual asks first, and declining stays in source', async () => {
		tcmsConfirm.mockResolvedValue(false);
		const { ta, md } = mount(FOOTNOTED);

		await md.toggleCodeView();

		expect(tcmsConfirm).toHaveBeenCalledTimes(1);
		expect(tcmsConfirm.mock.calls[0][0].message).toContain('footnotes');
		expect(md.mode).toBe('source');
		expect(ta.value).toBe(FOOTNOTED);
	});

	test('accepting switches to visual without rewriting the stored value', async () => {
		tcmsConfirm.mockResolvedValue(true);
		const { ta, md } = mount(FOOTNOTED);

		await md.toggleCodeView();

		expect(md.mode).toBe('visual');
		expect(md.noticeEl).toBeNull();
		expect(md.container.querySelector('.ste-code-view')).toBeNull();
		expect(ta.value).toBe(FOOTNOTED);
	});

	test('clean content moves between modes without a confirmation', async () => {
		const { ta, md } = mount('# Title');

		await md.toggleCodeView();
		expect(md.mode).toBe('source');
		sourceTextarea(md).value = '# Changed';
		sourceTextarea(md).dispatchEvent(new Event('input'));
		await md.toggleCodeView();

		expect(tcmsConfirm).not.toHaveBeenCalled();
		expect(md.mode).toBe('visual');
		expect(ta.value).toBe('# Changed');
		expect(md.editor.getText()).toContain('Changed');
	});

	test('setValue with lossy content moves a visual editor into source mode', () => {
		const { md } = mount('plain');

		md.setValue(FOOTNOTED);

		expect(md.mode).toBe('source');
		expect(sourceTextarea(md).value).toBe(FOOTNOTED);
	});
});

describe('preview', () => {
	test('a field can add the preview button back with toolbarConfig', () => {
		const { md } = mount('# Title', { toolbarConfig: [{ name: 'misc', buttons: ['bold', 'preview', 'codeView'] }] });

		md.container.querySelector('[data-command="togglePreview"]').click();

		expect(md.previewEl.querySelector('h1').textContent).toBe('Title');
		expect(md.container.querySelector('[data-command="toggleBold"]').disabled).toBe(true);
	});

	test('renders the current value and hides the editor', () => {
		const { md } = mount('# Title\n\nline one\nline two');

		md.togglePreview();

		expect(md.previewEl.querySelector('h1').textContent).toBe('Title');
		expect(md.previewEl.querySelector('br')).not.toBeNull();
		expect(md.container.querySelector('.ste-editor-wrapper').style.display).toBe('none');
		expect(md.container.classList.contains('ste-mode-preview')).toBe(true);
	});

	test('sanitizes what it renders', () => {
		const { md } = mount('Hi\n\n<script>alert(1)</script>\n\n<b onclick="x()">bold</b>');

		md.togglePreview();

		expect(md.previewEl.querySelector('script')).toBeNull();
		expect(md.previewEl.innerHTML).not.toContain('onclick');
	});

	test('closing it returns to the mode it came from', () => {
		const { md } = mount(FOOTNOTED);

		md.togglePreview();
		expect(md.container.querySelector('.ste-code-view').style.display).toBe('none');
		md.togglePreview();

		expect(md.previewEl).toBeNull();
		expect(md.mode).toBe('source');
		expect(md.container.querySelector('.ste-code-view').style.display).toBe('');
		expect(md.container.classList.contains('ste-mode-preview')).toBe(false);
	});
});

//-----------------------------------------------
// Regressions from the whole-change review (2026-10-03).
//-----------------------------------------------
describe('transactions that are not edits', () => {
	test('focusing a field that ends in a list does not rewrite or dirty it', () => {
		// StarterKit appends a trailing paragraph on the first transaction when
		// the document does not end in one. That is a document change, but the
		// markdown it serializes to is the same content.
		const changed = vi.fn();
		const { ta, md } = mount('* one\n* two', { onContentChanged: changed });

		md.editor.view.dispatch(md.editor.state.tr);
		md.editor.commands.focus();

		expect(ta.value).toBe('* one\n* two');
		expect(changed).not.toHaveBeenCalled();
	});

	test('undoing back to the loaded content restores the original bytes', () => {
		const { ta, md } = mount('* one\n* two');

		md.editor.chain().focus('end').insertContent(' more').run();
		expect(ta.value).toBe('- one\n- two more');
		md.editor.commands.undo();

		expect(ta.value).toBe('* one\n* two');
	});

	test('a character limit never truncates stored content', () => {
		const { ta, md } = mount('0123456789 abcdefghij', { charCounterMax: 5, charCounterCount: true });

		md.editor.view.dispatch(md.editor.state.tr);
		md.editor.commands.focus();

		expect(ta.value).toBe('0123456789 abcdefghij');
	});
});

describe('the hidden visual editor', () => {
	const LOSSY = 'Claim.[^1]\n\n<div class="x">raw</div>\n\n[^1]: The note.';

	test('a toolbar command in source mode cannot overwrite the stored value', () => {
		const { ta, md } = mount(LOSSY);

		md.container.querySelector('[data-command="setHorizontalRule"]').click();
		md.editor.chain().setHorizontalRule().run();

		expect(ta.value).toBe(LOSSY);
	});

	test('formatting buttons are disabled in source mode and preview, and come back', async () => {
		const { md } = mount('# Title');
		const bold = md.container.querySelector('[data-command="toggleBold"]');
		const source = md.container.querySelector('[data-command="toggleCodeView"]');

		await md.toggleCodeView();
		expect(bold.disabled).toBe(true);
		expect(source.disabled).toBe(false);

		await md.toggleCodeView();
		expect(bold.disabled).toBe(false);

		md.togglePreview();
		expect(bold.disabled).toBe(true);
		md.togglePreview();
		expect(bold.disabled).toBe(false);
	});

	test('a command while previewing cannot overwrite the stored value', () => {
		const { ta, md } = mount('* one\n* two');

		md.togglePreview();
		md.editor.chain().setHorizontalRule().run();

		expect(ta.value).toBe('* one\n* two');
	});
});
