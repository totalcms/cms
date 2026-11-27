import TiptapEditor from '../../javascript/totalform/tiptap/TiptapEditor.js';

//-----------------------------------------------
// The table controls live in the toolbar's Table menu. They used to float over
// the table, and the editor is only as tall as its content, so they usually
// sat on the bottom rows, over the cells being typed in.
//-----------------------------------------------

const TABLE = '<table><tbody>'
	+ '<tr><th><p>Pitch</p></th><th><p>Price</p></th></tr>'
	+ '<tr><td><p>Standard</p></td><td><p>32</p></td></tr>'
	+ '</tbody></table><p>after</p>';

const WITH_TABLE_BUTTON = [
	{ name: 'text', buttons: ['bold'] },
	{ name: 'insert', buttons: ['link', 'table'] },
	{ name: 'misc', buttons: ['codeView'], align: 'right' },
];

function mount(html = TABLE, toolbarConfig = WITH_TABLE_BUTTON) {
	document.body.innerHTML = '';
	const ta = document.createElement('textarea');
	ta.name = 'rates';
	ta.value = html;
	document.body.appendChild(ta);
	return new TiptapEditor(ta, { toolbarConfig });
}

// Put the cursor inside the first text node that reads `text`.
function cursorIn(editor, text) {
	let pos = null;
	editor.editor.state.doc.descendants((node, at) => {
		if (pos === null && node.isText && node.text === text) pos = at + 1;
	});
	editor.editor.commands.setTextSelection(pos);
}

const tableMenu = editor => editor.container.querySelector('.ste-table-tools').closest('.ste-toolbar-dropdown');
const rows = editor => editor.editor.getHTML().match(/<tr>/g)?.length ?? 0;
const click = (menu, action) => menu.querySelector(`[data-action="${action}"]`).click();

describe('the Table menu', () => {
	test('offers the Insert Table grid outside a table', () => {
		const editor = mount();
		cursorIn(editor, 'after');

		const menu = tableMenu(editor);
		expect(menu.classList.contains('in-table')).toBe(false);
		expect(menu.querySelector('.ste-toolbar-dropdown-toggle').classList.contains('is-active')).toBe(false);
	});

	test('switches to the table controls, and lights up, inside a table', () => {
		const editor = mount();
		cursorIn(editor, '32');

		const menu = tableMenu(editor);
		expect(menu.classList.contains('in-table')).toBe(true);
		expect(menu.querySelector('.ste-toolbar-dropdown-toggle').classList.contains('is-active')).toBe(true);
	});

	test('adds and deletes rows in the table the cursor is in', () => {
		const editor = mount();
		cursorIn(editor, '32');
		const menu = tableMenu(editor);

		click(menu, 'addRowAfter');
		expect(rows(editor)).toBe(3);

		click(menu, 'deleteRow');
		expect(rows(editor)).toBe(2);
	});

	test('adds columns', () => {
		const editor = mount();
		cursorIn(editor, '32');

		click(tableMenu(editor), 'addColumnAfter');

		expect(editor.editor.getHTML().match(/<td/g)).toHaveLength(3);
	});

	test('shows whether the table has a header row', () => {
		const editor = mount();
		cursorIn(editor, '32');
		const menu = tableMenu(editor);
		const headerRow = menu.querySelector('[data-action="toggleHeaderRow"]');

		expect(headerRow.classList.contains('is-active')).toBe(true);

		click(menu, 'toggleHeaderRow');
		expect(headerRow.classList.contains('is-active')).toBe(false);
	});

	test('is the configured Table button when the toolbar has one', () => {
		const editor = mount();

		expect(editor.container.querySelectorAll('.ste-table-tools')).toHaveLength(1);
		expect(editor.container.querySelector('.ste-table-contextual')).toBeNull();
	});

	test('still reaches a table on a toolbar with no Table button, before the right-hand group', () => {
		const editor = mount(TABLE, null); // the default toolbar has no Table button
		cursorIn(editor, '32');

		const group = editor.container.querySelector('.ste-table-contextual');
		expect(group).not.toBeNull();
		expect(group.nextElementSibling.classList.contains('ste-toolbar-group--right')).toBe(true);
		expect(tableMenu(editor).classList.contains('in-table')).toBe(true);

		click(tableMenu(editor), 'addRowAfter');
		expect(rows(editor)).toBe(3);
	});

	test('no longer floats a box over the table', () => {
		const editor = mount();
		cursorIn(editor, '32');

		expect(editor.container.querySelector('.ste-table-popover')).toBeNull();
	});
});
