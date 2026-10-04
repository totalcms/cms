import MarkdownSourceEditor from '../../javascript/totalform/markdown/MarkdownSourceEditor.js';
import { createImageDialog } from '../../javascript/totalform/tiptap/extensions/ImageDialog.js';
import { createFileDialog } from '../../javascript/totalform/tiptap/extensions/FileLink.js';

vi.mock('../../javascript/totalform/tiptap/extensions/ImageDialog.js', () => ({ createImageDialog: vi.fn() }));
vi.mock('../../javascript/totalform/tiptap/extensions/FileLink.js', () => ({ createFileDialog: vi.fn() }));

//-----------------------------------------------
// The markdown field's source editor. jsdom has no window.TotalCMSCodeMirror,
// so these run on the plain-textarea surface — what a forms.js page gets.
//-----------------------------------------------

function mount(value, options = {}) {
	document.body.innerHTML = '';
	const field = document.createElement('textarea');
	field.name = 'body';
	field.value = value;
	document.body.appendChild(field);

	const editor = new MarkdownSourceEditor(field, options);

	return { field, editor, source: editor.container.querySelector('.ste-source-textarea') };
}

const button = (editor, name) => editor.container.querySelector(`.ste-toolbar-btn[data-command="${name}"]`);

beforeEach(() => {
	createImageDialog.mockReset();
	createFileDialog.mockReset();
	delete window.TotalCMSCodeMirror;
});

describe('the source editor', () => {
	test('shows the stored markdown and hides the form textarea', () => {
		const { field, source } = mount('# Title');

		expect(source.value).toBe('# Title');
		expect(field.style.display).toBe('none');
	});

	test('keeps content the visual editor cannot, byte for byte', () => {
		const value = 'Claim.[^1]\n\n<div class="x">raw</div>\n\n<!-- note -->\n\n[![b](b.png)](https://a.co)\n\n[^1]: Note.\n';
		const { field, editor } = mount(value);

		expect(editor.getValue()).toBe(value);
		expect(field.value).toBe(value);
	});

	test('typing updates the form value and reports the change', () => {
		const changed = vi.fn();
		const { field, source } = mount('', { onContentChanged: changed });

		source.value = 'typed';
		source.dispatchEvent(new Event('input'));

		expect(field.value).toBe('typed');
		expect(changed).toHaveBeenCalledTimes(1);
	});

	test('has the default toolbar', () => {
		const { editor } = mount('');
		const names = [...editor.container.querySelectorAll('.ste-toolbar-btn[data-command]')].map((b) => b.dataset.command);

		expect(names).toEqual([
			'bold', 'italic', 'inlineCode',
			'bulletList', 'orderedList', 'blockquote', 'codeBlock', 'horizontalRule',
			'link', 'image', 'file', 'table',
			'preview', 'fullscreen',
		]);
		expect(editor.container.querySelector('.ste-toolbar-dropdown')).not.toBeNull();
	});

	test('a field can set its own toolbar', () => {
		const { editor } = mount('', { toolbarConfig: [{ name: 'text', buttons: ['bold', 'strike'] }] });
		const names = [...editor.container.querySelectorAll('.ste-toolbar-btn[data-command]')].map((b) => b.dataset.command);

		expect(names).toEqual(['bold', 'strike']);
	});

	test('a toolbar button edits the source around the selection', () => {
		const changed = vi.fn();
		const { field, editor, source } = mount('a word b', { onContentChanged: changed });
		source.setSelectionRange(2, 6);

		button(editor, 'bold').click();

		expect(field.value).toBe('a **word** b');
		expect([source.selectionStart, source.selectionEnd]).toEqual([4, 8]);
		expect(changed).toHaveBeenCalled();
	});

	test('the heading menu sets the level on the current line', () => {
		const { field, editor, source } = mount('Title');
		source.setSelectionRange(2, 2);

		editor.container.querySelector('.ste-toolbar-dropdown-item[data-level="2"]').click();

		expect(field.value).toBe('## Title');
	});

	test('the heading menu offers the configured levels', () => {
		const { editor } = mount('', { headingLevels: [1, 2] });
		const levels = [...editor.container.querySelectorAll('.ste-toolbar-dropdown-item')].map((i) => i.dataset.level);

		expect(levels).toEqual(['0', '1', '2']);
	});

	test('setValue replaces the content without reporting a change', () => {
		const changed = vi.fn();
		const { field, editor, source } = mount('old', { onContentChanged: changed });

		editor.setValue('# New');

		expect(source.value).toBe('# New');
		expect(field.value).toBe('# New');
		expect(changed).not.toHaveBeenCalled();
	});

	test('destroy removes the editor and shows the form textarea again', () => {
		const { field, editor } = mount('x');

		editor.destroy();

		expect(document.querySelector('.ste-editor-container')).toBeNull();
		expect(field.style.display).toBe('');
	});

	test('uses CodeMirror when it is on the page', () => {
		const cm = {
			getValue: () => '# Title', setValue: vi.fn(), on: vi.fn(), focus: vi.fn(), refresh: vi.fn(), destroy: vi.fn(),
			view: { state: { selection: { main: { from: 0, to: 0 } } }, dispatch: vi.fn() },
		};
		window.TotalCMSCodeMirror = { createMarkdownEditor: vi.fn(() => cm) };

		const { editor } = mount('# Title');

		expect(window.TotalCMSCodeMirror.createMarkdownEditor).toHaveBeenCalledTimes(1);
		expect(window.TotalCMSCodeMirror.createMarkdownEditor.mock.calls[0][1].value).toBe('# Title');
		expect(editor.container.querySelector('.ste-source-textarea')).toBeNull();

		button(editor, 'bold').click();
		expect(cm.view.dispatch).toHaveBeenCalledTimes(1);
	});
});

describe('preview', () => {
	test('inside the form it replaces the editor and locks the formatting buttons', () => {
		const { editor } = mount('# Title');

		button(editor, 'preview').click();

		expect(editor.previewing).toBe(true);
		expect(editor.container.classList.contains('ste-previewing')).toBe(true);
		expect(editor.container.querySelector('.ste-preview h1').textContent).toBe('Title');
		expect(button(editor, 'bold').disabled).toBe(true);
		expect(button(editor, 'preview').disabled).toBe(false);
		expect(button(editor, 'fullscreen').disabled).toBe(false);
		expect(button(editor, 'preview').classList.contains('is-active')).toBe(true);
	});

	test('toggling it off brings the editor back', () => {
		const { editor } = mount('# Title');

		button(editor, 'preview').click();
		button(editor, 'preview').click();

		expect(editor.previewing).toBe(false);
		expect(editor.container.classList.contains('ste-previewing')).toBe(false);
		expect(button(editor, 'bold').disabled).toBe(false);
	});

	test('in fullscreen it sits beside the source, which stays editable', () => {
		const { editor } = mount('# Title');

		button(editor, 'fullscreen').click();
		button(editor, 'preview').click();

		expect(editor.container.classList.contains('ste-fullscreen')).toBe(true);
		expect(editor.container.classList.contains('ste-previewing')).toBe(true);
		expect(button(editor, 'bold').disabled).toBe(false);
	});

	test('in fullscreen it follows the source as it changes', () => {
		vi.useFakeTimers();
		const { editor, source } = mount('# One');
		button(editor, 'fullscreen').click();
		button(editor, 'preview').click();

		source.value = '# Two';
		source.dispatchEvent(new Event('input'));
		vi.advanceTimersByTime(200);

		expect(editor.container.querySelector('.ste-preview h1').textContent).toBe('Two');
		vi.useRealTimers();
	});

	test('leaving fullscreen with the preview on locks the formatting buttons again', () => {
		const { editor } = mount('# Title');
		button(editor, 'fullscreen').click();
		button(editor, 'preview').click();

		button(editor, 'fullscreen').click();

		expect(editor.fullscreen).toBe(false);
		expect(button(editor, 'bold').disabled).toBe(true);
	});

	test('sanitizes what it shows', () => {
		const { editor } = mount('<script>alert(1)</script>\n\n<b onclick="x()">b</b>');

		editor.togglePreview();

		expect(editor.container.querySelector('.ste-preview script')).toBeNull();
		expect(editor.container.querySelector('.ste-preview').innerHTML).not.toContain('onclick');
	});
});

describe('fullscreen', () => {
	test('toggles the class and the button state', () => {
		const { editor } = mount('x');

		button(editor, 'fullscreen').click();

		expect(editor.fullscreen).toBe(true);
		expect(editor.container.classList.contains('ste-fullscreen')).toBe(true);
		expect(button(editor, 'fullscreen').classList.contains('is-active')).toBe(true);
	});

	test('Escape leaves it', () => {
		const { editor } = mount('x');
		button(editor, 'fullscreen').click();

		document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

		expect(editor.fullscreen).toBe(false);
		expect(editor.container.classList.contains('ste-fullscreen')).toBe(false);
	});
});

describe('images and files', () => {
	test('without an upload URL the image button inserts the syntax', () => {
		const { field, editor } = mount('', { uploadUrl: () => null });

		button(editor, 'image').click();

		expect(field.value).toBe('![alt](url)');
		expect(createImageDialog).not.toHaveBeenCalled();
	});

	test('with an upload URL it opens the upload dialog, which inserts markdown at the cursor', () => {
		const { field, editor, source } = mount('before  after', {
			uploadUrl: () => '/api/upload/notes/one/body',
			imagePreset: 'featured',
			imageUploadRules: { size: { max: 100 } },
		});
		source.setSelectionRange(7, 7);

		button(editor, 'image').click();

		expect(createImageDialog).toHaveBeenCalledTimes(1);
		const [target, config] = createImageDialog.mock.calls[0];
		expect(config.url()).toBe('/api/upload/notes/one/body');
		expect(config.imagePreset).toBe('featured');
		expect(config.rules).toEqual({ size: { max: 100 } });
		expect(target.getContent()).toBe('before  after');

		target.insertImage({ src: '/img/my photo (1).png', alt: 'Me' });

		expect(field.value).toBe('before ![Me](/img/my%20photo%20%281%29.png) after');
	});

	test('the file dialog inserts a markdown link', () => {
		const { field, editor } = mount('', { uploadUrl: '/api/upload/notes/one/body', fileUploadRules: { size: { max: 5 } } });

		button(editor, 'file').click();

		const [target, config] = createFileDialog.mock.calls[0];
		expect(config.rules).toEqual({ size: { max: 5 } });

		target.insertFileLink({ href: '/files/report.pdf', text: 'The [2026] report' });

		expect(field.value).toBe('[The \\[2026\\] report](/files/report.pdf)');
	});

	test('without an upload URL the file button inserts a link', () => {
		const { field, editor } = mount('');

		button(editor, 'file').click();

		expect(field.value).toBe('[text](url)');
		expect(createFileDialog).not.toHaveBeenCalled();
	});
});
