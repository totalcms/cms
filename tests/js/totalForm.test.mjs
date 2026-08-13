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

	test('still ignores inputs inside dialogs', () => {
		const form = formWithHtml(
			`<div class="form-field" data-type="deck"><dialog><input name="inmodal"></dialog></div>` + textField('title'),
		);
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
