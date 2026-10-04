import { CodeMirrorSurface, TextareaSurface } from '../../javascript/totalform/markdown/surfaces.js';

describe('TextareaSurface', () => {
	function mount(value) {
		const parent  = document.createElement('div');
		document.body.appendChild(parent);
		const surface = new TextareaSurface(parent, { value, placeholder: 'Write here' });

		return { parent, surface, el: parent.querySelector('textarea') };
	}

	test('renders a textarea holding the value', () => {
		const { el, surface } = mount('# Title');

		expect(el.className).toBe('ste-source-textarea');
		expect(el.placeholder).toBe('Write here');
		expect(surface.getValue()).toBe('# Title');
	});

	test('reports the selection', () => {
		const { el, surface } = mount('hello world');
		el.setSelectionRange(6, 11);

		expect(surface.getSelection()).toEqual([6, 11]);
	});

	test('applies an edit, sets the new selection and reports the change', () => {
		const { el, surface } = mount('a word b');
		const changed = vi.fn();
		surface.onChange(changed);

		surface.apply({ from: 2, to: 6, insert: '**word**', select: [4, 8] });

		expect(surface.getValue()).toBe('a **word** b');
		expect([el.selectionStart, el.selectionEnd]).toEqual([4, 8]);
		expect(changed).toHaveBeenCalledTimes(1);
	});

	test('reports typing', () => {
		const { el, surface } = mount('');
		const changed = vi.fn();
		surface.onChange(changed);

		el.value = 'typed';
		el.dispatchEvent(new Event('input'));

		expect(changed).toHaveBeenCalledTimes(1);
	});

	test('setValue replaces the text without reporting a change', () => {
		const { surface } = mount('old');
		const changed = vi.fn();
		surface.onChange(changed);

		surface.setValue('new');

		expect(surface.getValue()).toBe('new');
		expect(changed).not.toHaveBeenCalled();
	});

	test('destroy removes the textarea', () => {
		const { parent, surface } = mount('x');

		surface.destroy();

		expect(parent.querySelector('textarea')).toBeNull();
	});
});

describe('CodeMirrorSurface', () => {
	function fakeEditor(value, from, to) {
		const listeners = [];

		return {
			listeners,
			value,
			getValue() { return this.value; },
			setValue: vi.fn(function (next) { this.value = next; }),
			on: vi.fn((event, callback) => { if (event === 'change') listeners.push(callback); }),
			focus: vi.fn(),
			refresh: vi.fn(),
			destroy: vi.fn(),
			view: { state: { selection: { main: { from, to } } }, dispatch: vi.fn() },
		};
	}

	test('reads the value and selection from the editor', () => {
		const surface = new CodeMirrorSurface(fakeEditor('hello world', 6, 11));

		expect(surface.getValue()).toBe('hello world');
		expect(surface.getSelection()).toEqual([6, 11]);
	});

	test('applies an edit as one transaction with the new selection', () => {
		const editor  = fakeEditor('a word b', 2, 6);
		const surface = new CodeMirrorSurface(editor);

		surface.apply({ from: 2, to: 6, insert: '**word**', select: [4, 8] });

		expect(editor.view.dispatch).toHaveBeenCalledWith({
			changes: { from: 2, to: 6, insert: '**word**' },
			selection: { anchor: 4, head: 8 },
		});
	});

	test('passes on changes, focus, refresh and destroy', () => {
		const editor  = fakeEditor('', 0, 0);
		const surface = new CodeMirrorSurface(editor);
		const changed = vi.fn();
		surface.onChange(changed);

		editor.listeners.forEach((listener) => listener());
		surface.focus();
		surface.refresh();
		surface.destroy();

		expect(changed).toHaveBeenCalledTimes(1);
		expect(editor.focus).toHaveBeenCalled();
		expect(editor.refresh).toHaveBeenCalled();
		expect(editor.destroy).toHaveBeenCalled();
	});

	test('setValue does not report the change it causes', () => {
		const editor  = fakeEditor('old', 0, 0);
		const surface = new CodeMirrorSurface(editor);
		const changed = vi.fn();
		surface.onChange(changed);
		editor.setValue.mockImplementation(function (next) {
			this.value = next;
			editor.listeners.forEach((listener) => listener());
		});

		surface.setValue('new');

		expect(editor.value).toBe('new');
		expect(changed).not.toHaveBeenCalled();
	});
});
