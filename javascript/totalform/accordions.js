import Details from './details.js';

//-----------------------------------------------
// Formgrid accordion groups
//
// One Details instance per `.formgrid-accordion` wrapper. Open/solo behaviour
// comes from the wrapper's data attributes, which Details reads for itself —
// nothing here configures it.
//-----------------------------------------------
export default class FormAccordions {
	// Details._ensureVisible() re-scrolls and focuses the summary at
	// (speed + 50)ms after an open. Our field scroll has to land after that to
	// win, while TotalForm.scrollToField() focuses the input at 300ms and wins
	// the focus. 260ms sits in that gap.
	static RESCROLL_DELAY = 260;

	constructor(form) {
		this.form = form;
		this.groups = [];

		form.querySelectorAll('.formgrid-accordion').forEach(wrapper => {
			// processFields() re-enters on refresh, and the array path through
			// Details skips its own `container.details` short-circuit, so guard here.
			if (wrapper.details) return;

			// Scope to direct children. An unscoped query would also collect the
			// details.cms-accordion that image, gallery and property fields render
			// inside themselves, and solo mode would then close the panel whenever
			// one of those editors was opened.
			const panels = Array.from(wrapper.querySelectorAll(':scope > details.formgrid-panel'));
			if (panels.length === 0) return;

			panels.forEach(panel => {
				panel.addEventListener('toggle', () => {
					if (panel.open) this.reinitPanel(panel);
				});
			});

			const group = new Details(panels);
			this.groups.push(group);

			// Details can open a panel (openFirst, or a location-hash match) as
			// part of its own construction, above. The DOM's native `toggle` event
			// for that is queued asynchronously (per spec), so it has not fired by
			// the time we get here — check directly rather than wait for it.
			panels.forEach(panel => {
				if (panel.open) this.reinitPanel(panel);
			});
		});

		this.onErrorNavigate = e => this.revealField(e.detail && e.detail.field);
		this.form.addEventListener('tcms:error-navigate', this.onErrorNavigate);
	}

	// Open the panel containing an invalid field so the browser can focus it.
	revealField(field) {
		if (!field || !field.container) return;

		const panel = field.container.closest('details.formgrid-panel');
		if (!panel || panel.open) return;

		const group = this.groups.find(g => g.details.includes(panel));
		if (!group) return;

		group._openDetail(panel);

		setTimeout(() => {
			field.container.scrollIntoView({ behavior: 'smooth', block: 'center' });
		}, FormAccordions.RESCROLL_DELAY);
	}

	// Fields are constructed by processFields() before any panel is open, so a
	// field inside a collapsed panel was built against a container that was not
	// rendered. Give each one a chance to rebuild the first time its panel is
	// actually shown. Once only: after that the user may have edited the field,
	// and rebuilding would discard what they typed.
	reinitPanel(panel) {
		if (panel.dataset.fieldsReinit === 'done') return;
		panel.dataset.fieldsReinit = 'done';

		panel.querySelectorAll('.form-field').forEach(element => {
			// A field inside a closed dialog is still unrendered — rebuilding it
			// now would just reconstruct it broken. The dialog owns that moment;
			// see the note in the docs about fields in collapsed containers.
			if (element.closest('dialog:not([open])')) return;
			element.totalfield?.reinit();
		});
	}

	destroy() {
		this.form.removeEventListener('tcms:error-navigate', this.onErrorNavigate);
		this.groups = [];
	}
}
