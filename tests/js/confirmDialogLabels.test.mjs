import tcmsConfirm from '../../javascript/confirm-dialog.js';

//-----------------------------------------------
// The confirm dialog's message comes from the call site (already translated),
// but its Cancel / "Yes, I'm sure" buttons were hardcoded English defaults, so
// they stayed English on every localized site. They now read the js catalog.
//
// The catalog is absent whenever the admin bundle is loaded outside
// admin-dashboard.twig (cms.adminAssetsHead() on a customer page, a public
// cms.form.builder() form), so the English fallback has to survive that.
//-----------------------------------------------

beforeAll(() => {
	// jsdom implements neither method on <dialog>
	HTMLDialogElement.prototype.showModal = vi.fn();
	HTMLDialogElement.prototype.close = vi.fn();
});

afterEach(() => {
	delete window.TCMS_TRANSLATIONS;
	document.querySelectorAll('.cms-confirm').forEach(el => el.remove());
});

// Open the dialog, read both button labels, then cancel so the promise settles.
async function labels() {
	const pending = tcmsConfirm({ message: 'Delete this?', countdown: 0 });
	const dialog  = document.querySelector('.cms-confirm');
	const read    = {
		confirm : dialog.querySelector('.cms-confirm-ok-label').textContent,
		cancel  : dialog.querySelector('.cms-confirm-cancel').textContent,
	};

	dialog.querySelector('.cms-confirm-cancel').click();
	await pending;

	return read;
}

describe('tcmsConfirm button labels', () => {
	test('uses the js catalog when the admin dashboard injected one', async () => {
		window.TCMS_TRANSLATIONS = {
			'confirm.yes_sure' : 'Ja, ich bin sicher',
			'confirm.cancel'   : 'Abbrechen',
		};

		expect(await labels()).toEqual({ confirm: 'Ja, ich bin sicher', cancel: 'Abbrechen' });
	});

	test('falls back to English rather than raw keys when no catalog is present', async () => {
		expect(await labels()).toEqual({ confirm: "Yes, I'm sure", cancel: 'Cancel' });
	});

	test('an explicit label still wins over the catalog', async () => {
		window.TCMS_TRANSLATIONS = { 'confirm.cancel': 'Abbrechen' };

		const pending = tcmsConfirm({ message: 'x', countdown: 0, cancelLabel: 'Nope' });
		const dialog  = document.querySelector('.cms-confirm');
		expect(dialog.querySelector('.cms-confirm-cancel').textContent).toBe('Nope');

		dialog.querySelector('.cms-confirm-cancel').click();
		await pending;
	});
});
