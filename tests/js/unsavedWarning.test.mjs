import TotalForm from '../../javascript/totalform/totalform.js';
import { coreFieldTypes } from '../../javascript/totalform/field-types-core.js';

//-----------------------------------------------
// The leave-page warning. TotalForm installs a window.onbeforeunload guard
// that prompts when any field is unsaved; `no-unsaved-warning` on the form
// element (and `data-ajax="false"`) keeps it uninstalled. The switch is a
// form-level class — `no-change-listener` is per field and does not do this.
//-----------------------------------------------

function buildForm(formClass = '', attrs = '') {
	document.body.innerHTML = `<form class="totalform ${formClass}" data-api="/api" data-route="/collections/things" data-method="POST" data-form="object" data-collection="things" ${attrs}>
		<div class="form-field text-field" data-type="text"><input name="title" value=""></div>
	</form>`;
	const form = document.body.querySelector('form');
	new TotalForm(form);
	return form;
}

function edit(form) {
	const input = form.querySelector('input[name=title]');
	input.value = 'changed';
	input.dispatchEvent(new Event('input', { bubbles: true }));
}

function unloadEvent() {
	return { preventDefault: vi.fn(), returnValue: undefined };
}

beforeEach(() => {
	TotalForm.registerBuiltInFieldTypes(coreFieldTypes);
	window.onbeforeunload = null;
});

test('a default form installs the guard, and it prompts once a field is unsaved', () => {
	const form = buildForm();
	const totalform = form.totalform;

	expect(window.onbeforeunload).toBeTypeOf('function');

	const clean = unloadEvent();
	window.onbeforeunload(clean);
	expect(clean.preventDefault).not.toHaveBeenCalled();

	edit(form);
	expect(totalform.isUnsaved()).toBe(true);

	const dirty = unloadEvent();
	expect(window.onbeforeunload(dirty)).toBe('There are unsaved changes');
	expect(dirty.preventDefault).toHaveBeenCalled();
});

test('no-unsaved-warning on the form keeps the guard uninstalled, even with unsaved edits', () => {
	const form = buildForm('no-unsaved-warning');

	edit(form);

	// The form still tracks its own dirty state; only the page-leave prompt is off.
	expect(form.totalform.isUnsaved()).toBe(true);
	expect(window.onbeforeunload).toBeNull();
});

test('a non-AJAX form never installs the guard either', () => {
	buildForm('', 'data-ajax="false"');

	expect(window.onbeforeunload).toBeNull();
});
