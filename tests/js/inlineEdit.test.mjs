import { bootInlineEdit } from '../../javascript/totalform/inline-edit.js';

//-----------------------------------------------
// bootInlineEdit() turns the fragment AdminCellAction swaps into a cell
// into a working one-field form: TotalForm boots on it, focus lands in the
// field, Enter saves through htmx.ajax with generateData() as JSON, Escape
// cancels. TotalForm itself is mocked — its constructor needs a whole
// page; here it only needs to hand back generateData().
//-----------------------------------------------

const generated = { title: 'Renamed' };
vi.mock('../../javascript/totalform/totalform.js', () => ({
	default: class FakeTotalForm {
		constructor(form) {
			this.form = form;
			form.totalform = this;
			form.addEventListener('submit', e => e.preventDefault());
		}
		validate() { return true; }
		generateData() { return generated; }
	},
}));

function cellWith(fieldHtml) {
	document.body.innerHTML = `<table><tr><td>
		<form class="totalform inline-edit" data-inline-edit="1" data-action="collections/blog/hello/cell/title">
			<div class="form-field">${fieldHtml}</div>
			<div class="inline-edit-actions">
				<button type="submit" class="inline-edit-save">Save</button>
				<button type="button" class="inline-edit-cancel">Cancel</button>
			</div>
		</form>
	</td></tr></table>`;
	return document.querySelector('td');
}

const key = (el, k, init = {}) => el.dispatchEvent(new KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true, ...init }));

beforeEach(() => {
	window.htmx = { ajax: vi.fn() };
});

describe('bootInlineEdit', () => {
	test('boots TotalForm once and focuses the field', () => {
		const td = cellWith('<input type="text" name="title" value="Hello">');
		const tf = bootInlineEdit(td);

		expect(tf).not.toBeNull();
		expect(td.querySelector('form').totalform).toBe(tf);
		expect(document.activeElement).toBe(td.querySelector('input'));
		expect(bootInlineEdit(td)).toBeNull(); // second swap event, same form: no double boot
	});

	test('ignores a cell with no inline-edit fragment', () => {
		document.body.innerHTML = '<table><tr><td>Hello</td></tr></table>';
		expect(bootInlineEdit(document.querySelector('td'))).toBeNull();
	});

	test('Enter in a single-line input saves generateData() as JSON via htmx.ajax', () => {
		const td = cellWith('<input type="text" name="title" value="Hello">');
		bootInlineEdit(td);

		key(td.querySelector('input'), 'Enter');

		expect(window.htmx.ajax).toHaveBeenCalledTimes(1);
		expect(window.htmx.ajax).toHaveBeenCalledWith('PATCH', 'collections/blog/hello/cell/title', {
			target : td,
			swap   : 'innerHTML',
			values : { data: JSON.stringify(generated) },
		});
	});

	test('the Save button submits the same way, and only once', () => {
		const td = cellWith('<input type="text" name="title" value="Hello">');
		bootInlineEdit(td);

		td.querySelector('form').requestSubmit();
		td.querySelector('form').requestSubmit();

		expect(window.htmx.ajax).toHaveBeenCalledTimes(1);
	});

	test('Enter in a textarea keeps its newline', () => {
		const td = cellWith('<textarea name="summary">Hi</textarea>');
		bootInlineEdit(td);

		key(td.querySelector('textarea'), 'Enter');

		expect(window.htmx.ajax).not.toHaveBeenCalled();
	});

	test('Escape clicks Cancel', () => {
		const td = cellWith('<input type="text" name="title" value="Hello">');
		bootInlineEdit(td);
		const cancel = td.querySelector('.inline-edit-cancel');
		const clicked = vi.fn();
		cancel.addEventListener('click', clicked);

		key(td.querySelector('input'), 'Escape');

		expect(clicked).toHaveBeenCalledTimes(1);
		expect(window.htmx.ajax).not.toHaveBeenCalled();
	});
});
