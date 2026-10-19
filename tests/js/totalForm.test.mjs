import TotalForm from '../../javascript/totalform/totalform.js';

//-----------------------------------------------
// generateData() is the single point where the form collects values to save,
// and isUnsaved() is the dirty signal. Both iterate this.fields, so we exercise
// the real methods against stub fields (TotalForm's constructor takes 25+
// dependencies — not worth building here).
//-----------------------------------------------

function formWithFields(fields) {
	const form = Object.create(TotalForm.prototype);
	form.fields = fields;
	return form;
}

const field = ({ property, value, sub = false, unsaved = false }) => ({
	property,
	getValue: () => value,
	isSubField: () => sub,
	isUnsaved: () => unsaved,
});

describe('TotalForm.generateData', () => {
	test('collects each top-level field value keyed by its property', () => {
		const form = formWithFields([
			field({ property: 'title', value: 'Hello' }),
			field({ property: 'tags', value: ['a', 'b'] }),
		]);

		expect(form.generateData()).toEqual({ title: 'Hello', tags: ['a', 'b'] });
	});

	test('skips sub-fields — they belong to their composite parent, not the root', () => {
		const form = formWithFields([
			field({ property: 'mycard', value: { name: 'Star' } }),
			field({ property: 'name', value: 'leaked-from-card', sub: true }),
		]);

		// The card's own value is kept; its `name` sub-field does NOT leak to the root.
		expect(form.generateData()).toEqual({ mycard: { name: 'Star' } });
	});
});

describe('TotalForm.isUnsaved', () => {
	test('true when any field is unsaved', () => {
		const form = formWithFields([
			field({ property: 'a', unsaved: false }),
			field({ property: 'b', unsaved: true }),
		]);
		expect(form.isUnsaved()).toBe(true);
	});

	test('false when every field is saved', () => {
		const form = formWithFields([
			field({ property: 'a', unsaved: false }),
			field({ property: 'b', unsaved: false }),
		]);
		expect(form.isUnsaved()).toBe(false);
	});
});

//-----------------------------------------------
// Add-only forms with an autogen id have no id field. The saved id must be
// pulled from the (Fractal data-wrapped) response and set on the form BEFORE
// deferred file/image uploads flush, or they post to a URL with an empty id.
//-----------------------------------------------

describe('TotalForm.responseId', () => {
	const form = Object.create(TotalForm.prototype);

	test('reads the id from the data envelope', () => {
		expect(form.responseId({ data: { id: 'obj-1' } })).toBe('obj-1');
	});

	test('falls back to a top-level id', () => {
		expect(form.responseId({ id: 'obj-2' })).toBe('obj-2');
	});

	test('returns null when there is no id or no response', () => {
		expect(form.responseId({})).toBeNull();
		expect(form.responseId(null)).toBeNull();
	});
});

describe('TotalForm.getId', () => {
	test('prefers an already-assigned id outside edit mode (autogen add-only)', () => {
		const form = Object.create(TotalForm.prototype);
		form.isEditMode = () => false;
		form.id = 'saved-9';
		expect(form.getId()).toBe('saved-9');
	});
});

//-----------------------------------------------
// Fields whose real UI isn't a text box (deck, card, image, gallery, file,
// depot) render a proxy input carrying the value and/or validity, collapsed to
// zero size by CSS but still rendered — see FormField::proxyInput. The user
// never types into one, so focusFirstInput() must skip it, or opening a form
// that leads with such a field drops the cursor in an invisible box.
//-----------------------------------------------

function formWithHtml(html) {
	document.body.innerHTML = `<form>${html}</form>`;
	const form = Object.create(TotalForm.prototype);
	form.form = document.querySelector('form');
	return form;
}

// Mirrors FormField::proxyInput output.
const proxyField = (field, name, value = '') =>
	`<div class="form-field ${field}-field" data-type="${field}">
		<div class="form-group"><input type="text" name="${name}" value="${value}" data-proxy="true"></div>
	</div>`;

const textField = name =>
	`<div class="form-field text-field" data-type="text">
		<div class="form-group"><input name="${name}"></div>
	</div>`;

describe('TotalForm.focusFirstInput', () => {
	test('focuses a real field when it comes first', () => {
		const form = formWithHtml(textField('title') + textField('body'));
		form.focusFirstInput();
		expect(document.activeElement).toBe(document.querySelector('[name="title"]'));
	});

	test('skips a deck proxy to reach the first real input', () => {
		const form = formWithHtml(proxyField('deck', 'items', 'items') + textField('title'));
		form.focusFirstInput();
		expect(document.activeElement).toBe(document.querySelector('[name="title"]'));
	});

	test('skips image and gallery proxies too', () => {
		const form = formWithHtml(proxyField('image', 'photo') + proxyField('gallery', 'shots') + textField('title'));
		form.focusFirstInput();
		expect(document.activeElement).toBe(document.querySelector('[name="title"]'));
	});

	// The formgrid places fields by grid area, so markup order is not screen
	// order: on the blog form the Draft toggle is rendered before the ID field
	// it sits below, and used to take the cursor.
	const place = (name, top, left = 0, size = 20) => {
		document.querySelector(`[name="${name}"]`).getBoundingClientRect = () => ({ top, left, width: size, height: size });
	};

	test('focuses the field that is first on screen, not first in the markup', () => {
		const form = formWithHtml(textField('draft') + textField('featured') + textField('id') + textField('title'));
		place('draft', 292, 0);
		place('featured', 292, 300);
		place('id', 157);
		place('title', 237);

		form.focusFirstInput();
		expect(document.activeElement).toBe(document.querySelector('[name="id"]'));
	});

	test('left-most wins on the same row, and an input with no box is passed over', () => {
		const form = formWithHtml(textField('collapsed') + textField('right') + textField('left'));
		place('collapsed', 0, 0, 0);
		place('right', 100, 300);
		place('left', 100, 0);

		form.focusFirstInput();
		expect(document.activeElement).toBe(document.querySelector('[name="left"]'));
	});

	test('still ignores inputs inside dialogs', () => {
		const form = formWithHtml(
			`<div class="form-field" data-type="deck"><dialog><input name="inmodal"></dialog></div>` + textField('title'),
		);
		form.focusFirstInput();
		expect(document.activeElement).toBe(document.querySelector('[name="title"]'));
	});

	// Mirrors ObjectForm::botFields(): the honeypot sits off-screen in a 1px box
	// that still has a rect, so it must be excluded by markup, not geometry.
	const honeypot = `<div class="form-field text-field bot-field" data-type="text" aria-hidden="true">
		<label>Leave this field empty</label>
		<div class="form-group"><input type="text" name="extra_reference_code" tabindex="-1" autocomplete="off"></div>
	</div>`;

	test('skips the bot-protection honeypot even when it is placed first', () => {
		const form = formWithHtml(proxyField('image', 'photo') + honeypot + textField('title'));
		place('extra_reference_code', -10000, -10000, 1);
		place('title', 100);

		form.focusFirstInput();
		expect(document.activeElement).toBe(document.querySelector('[name="title"]'));
	});

	test('skips any input taken out of the tab order', () => {
		const form = formWithHtml(`<div class="form-field"><input name="skip" tabindex="-1"></div>` + textField('title'));
		form.focusFirstInput();
		expect(document.activeElement).toBe(document.querySelector('[name="title"]'));
	});

	test('a proxy is still a valid target for error reporting', () => {
		// Skipping proxies on form open must NOT make them unreportable — a deck
		// anchors its native error bubble on exactly this input, which is why it is
		// collapsed rather than display:none.
		const form = formWithHtml(proxyField('deck', 'items', 'items'));
		expect(form.isFocusable(document.querySelector('[name="items"]'))).toBe(true);
	});

	// On a public page the form is one element among many. A register form
	// beside a login form took the cursor from the login email the page had
	// marked autofocus, and on a phone scrolled the page down to itself.
	test('leaves focus alone when another field on the page already has it', () => {
		const form = formWithHtml(textField('title'));
		const other = document.createElement('input');
		other.name = 'email';
		document.body.prepend(other);
		other.focus();

		form.focusFirstInput();
		expect(document.activeElement).toBe(other);
	});

	test('leaves focus alone when the page marks another field autofocus', () => {
		const form = formWithHtml(textField('title'));
		const other = document.createElement('input');
		other.setAttribute('autofocus', '');
		document.body.prepend(other);

		form.focusFirstInput();
		expect(document.activeElement).not.toBe(document.querySelector('[name="title"]'));
	});

	test('does not scroll the page to a form below the first screen', () => {
		const form = formWithHtml(textField('title'));
		place('title', window.innerHeight + 200);

		form.focusFirstInput();
		expect(document.activeElement).not.toBe(document.querySelector('[name="title"]'));
	});

	test('still focuses a form whose first field is on screen', () => {
		const form = formWithHtml(textField('title'));
		place('title', 120);

		form.focusFirstInput();
		expect(document.activeElement).toBe(document.querySelector('[name="title"]'));
	});
});

describe('TotalForm.afterSave', () => {
	test('adopts the saved id before flushing deferred uploads', () => {
		const form = Object.create(TotalForm.prototype);
		form.id = '';
		form.form = { dataset: {} };
		form.droplets = [{ isUnsaved: () => true }];

		let idAtFlush = null;
		form.saveDroplets = () => { idAtFlush = form.id; };

		form.afterSave({ data: { id: 'new-123' } });

		// Set on the form (and its dataset) and available when droplets flush.
		expect(form.id).toBe('new-123');
		expect(form.form.dataset.id).toBe('new-123');
		expect(idAtFlush).toBe('new-123');
	});
});

//-----------------------------------------------
// Field events are debounced (TotalDispatcher, 300ms). A failed validation
// leaves the form in "error"; the field-change from the member's correction
// only arrives 300ms later. Saving inside that window used to pass
// validate() and then refuse in save() on the stale "error" state ("Error in
// form, cannot save"); the next click worked. validate() now flushes pending
// field events first, so the state reflects every edit already made.
//-----------------------------------------------
import TotalDispatcher from '../../javascript/totalform/dispatcher.js';

function wiredForm() {
	const form = Object.create(TotalForm.prototype);
	form.form = document.createElement('form');
	form.method = 'POST';
	form.states = ['unsaved', 'success', 'error', 'processing'];
	form.state = null;
	form.autosave = false;
	form.eventListeners();
	return form;
}

function pendingField(form) {
	const container = document.createElement('div');
	form.form.appendChild(container);
	const dispatcher = new TotalDispatcher(container);
	return { container, dispatcher, validate: () => true, isVisible: () => true };
}

describe('TotalForm.validate flushes pending field events', () => {
	test('a correction typed just before saving clears the stale error state', () => {
		vi.useFakeTimers();
		const form = wiredForm();
		const fixed = pendingField(form);
		form.fields = [fixed];

		form.changeState('error');                                 // the earlier failed attempt
		fixed.dispatcher.dispatchEvent('field-change', { field: fixed }); // the correction, still debounced

		expect(form.validate()).toBe(true);
		expect(form.isError()).toBe(false);
		expect(form.state).toBe('unsaved');
		vi.useRealTimers();
	});
});
