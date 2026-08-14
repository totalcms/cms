import TotalForm from '../../javascript/totalform/totalform.js';
import TotalField from '../../javascript/totalform/totalfield.js';

//-----------------------------------------------
// validate() is the submit gate. It must do two things when the form is
// invalid: render the error summary AND put the cursor on the first offending
// field. The summary alone (added in 3.1.7, which dropped the old
// `form.reportValidity()`) leaves the user with feedback appended to the
// bottom of a long form and no idea which field to fix.
//
// Composite fields make this harder than it looks: a card's own input is a
// hidden marker (CardField.php) that is barred from constraint validation, so
// the card can never report invalid on its own. Its validity has to be folded
// up from its sub-fields — which is what rootField() is for.
//
// TotalForm's constructor takes 25+ dependencies, so these build a real <form>
// with real TotalField instances and borrow the prototype.
//-----------------------------------------------

const CARD = `
	<div class="form-field" data-type="card">
		<label>Address</label>
		<input type="hidden" name="address" value="address">
		<div class="card-fields">
			<div class="form-field" data-type="text"><label>Street</label><input name="street"></div>
			<div class="form-field" data-type="text"><label>Postal Code</label><input name="postal" required></div>
		</div>
	</div>`;

const text = (name, { required = false, label = name } = {}) =>
	`<div class="form-field" data-type="text"><label>${label}</label>` +
	`<input name="${name}" ${required ? 'required' : ''}></div>`;

function buildForm(html) {
	document.body.innerHTML = `<form>${html}</form>`;
	const el = document.querySelector('form');
	// Document order guarantees a parent is instantiated before its children,
	// so container.totalfield is set by the time getParent() walks up.
	el.querySelectorAll('.form-field').forEach(node => new TotalField(node, {}));

	const form = Object.create(TotalForm.prototype);
	form.form = el;
	form.fields = Array.from(el.querySelectorAll('.form-field'))
		.map(node => node.totalfield)
		.filter(field => !field.isSubField());
	return form;
}

// Record scrollToField instead of running it — jsdom has no scrollIntoView and
// the real method defers focus behind a timeout.
function spyOnScroll(form) {
	const scrolled = [];
	form.scrollToField = field => scrolled.push(field);
	return scrolled;
}

describe('TotalForm.getInvalidFields', () => {
	test('folds an invalid sub-field up to the card the user can see', () => {
		const form = buildForm(CARD);

		const invalid = form.getInvalidFields();

		expect(invalid).toHaveLength(1);
		expect(invalid[0].property).toBe('address');
	});

	test('reports a card once even when several sub-fields are invalid', () => {
		const form = buildForm(`
			<div class="form-field" data-type="card">
				<label>Address</label>
				<input type="hidden" name="address" value="address">
				<div class="card-fields">
					<div class="form-field" data-type="text"><label>Street</label><input name="street" required></div>
					<div class="form-field" data-type="text"><label>Postal</label><input name="postal" required></div>
				</div>
			</div>`);

		expect(form.getInvalidFields().map(f => f.property)).toEqual(['address']);
	});

	test('keeps document order across top-level fields and cards', () => {
		const form = buildForm(text('title', { required: true }) + CARD + text('body', { required: true }));

		expect(form.getInvalidFields().map(f => f.property)).toEqual(['title', 'address', 'body']);
	});

	test('skips hidden sub-fields and hidden roots', () => {
		const form = buildForm(CARD);
		const postal = document.querySelector('[name="postal"]').closest('.form-field');
		postal.totalfield.hide();

		expect(form.getInvalidFields()).toEqual([]);
	});

	test('empty for a form with nothing invalid', () => {
		const form = buildForm(text('title') + text('body'));
		expect(form.getInvalidFields()).toEqual([]);
	});
});

describe('TotalForm.validate', () => {
	test('sends the cursor to the FIRST invalid field, not the last', () => {
		const form = buildForm(text('slug') + text('title', { required: true }) + text('body', { required: true }));
		const scrolled = spyOnScroll(form);

		expect(form.validate()).toBe(false);
		expect(scrolled.map(f => f.property)).toEqual(['title']);
	});

	test('lands on the card when the failure is inside it', () => {
		const form = buildForm(text('slug') + CARD);
		const scrolled = spyOnScroll(form);

		form.validate();

		expect(scrolled.map(f => f.property)).toEqual(['address']);
	});

	test('a valid form clears the summary and scrolls nowhere', () => {
		const form = buildForm(text('title') + text('body'));
		const scrolled = spyOnScroll(form);

		expect(form.validate()).toBe(true);
		expect(scrolled).toEqual([]);
	});
});

describe('TotalForm.showErrorSummary', () => {
	// The browser supplies the message text ("Please fill out this field." in
	// Chrome, "Constraints not satisfied" in jsdom), so assert against the live
	// validationMessage rather than pinning a string that varies by engine.
	const required = name => document.querySelector(`[name="${name}"]`).validationMessage;

	test('lists every invalid field with its message', () => {
		const form = buildForm(text('title', { required: true }) + text('body', { required: true }));
		spyOnScroll(form);

		form.validate();

		const links = form.form.querySelectorAll('.tcms-error-summary-link');
		expect(Array.from(links).map(a => a.textContent)).toEqual([
			`title - ${required('title')}`,
			`body - ${required('body')}`,
		]);
	});

	test('names the offending sub-field and borrows its message', () => {
		// Without this the entry reads just "Address" with no reason, because the
		// card's hidden marker input has an empty validationMessage.
		const form = buildForm(CARD);
		spyOnScroll(form);

		form.validate();

		const link = form.form.querySelector('.tcms-error-summary-link');
		expect(link.textContent).toBe(`Address: Postal Code - ${required('postal')}`);
	});
});

describe('TotalForm.scrollToField', () => {
	beforeEach(() => { Element.prototype.scrollIntoView = vi.fn(); });

	test('focuses the offending sub-field, not the card hidden marker', () => {
		vi.useFakeTimers();
		const form = buildForm(CARD);
		const card = document.querySelector('[data-type="card"]').totalfield;

		form.scrollToField(card);
		vi.advanceTimersByTime(300);

		expect(document.activeElement).toBe(document.querySelector('[name="postal"]'));
		vi.useRealTimers();
	});

	test('does not try to focus an input inside a closed dialog', () => {
		vi.useFakeTimers();
		const form = buildForm(`
			<div class="form-field" data-type="deck">
				<label>Items</label>
				<input type="hidden" name="items" value="items">
				<dialog class="cms-modal">
					<div class="form-field" data-type="text"><label>Name</label><input name="name" required></div>
				</dialog>
			</div>`);
		const deck = document.querySelector('[data-type="deck"]').totalfield;

		form.scrollToField(deck);
		vi.advanceTimersByTime(300);

		expect(document.activeElement).not.toBe(document.querySelector('[name="name"]'));
		vi.useRealTimers();
	});
});
