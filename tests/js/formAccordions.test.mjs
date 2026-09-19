import FormAccordions from '../../javascript/totalform/accordions.js';

// jsdom implements neither of the two browser APIs this path uses.
// Details._animateDetail() calls element.animate() (Web Animations), and both
// Details._ensureVisible() and our re-scroll call scrollIntoView(). Both fire on
// timers that outlive the test, so leaving them unstubbed throws after the test
// has already passed. Stub animate() so assigning onfinish schedules the
// callback as a microtask — matching the spec, which always dispatches
// `onfinish` as a queued task, never synchronously within the call stack that
// assigned it. Every synchronous assertion in these tests holds regardless,
// because `Details._openDetail()` sets `detail.open` directly before the
// rAF/animation step ever runs.
beforeEach(() => {
	Element.prototype.animate = function () {
		return {
			cancel() {},
			set onfinish(fn) { queueMicrotask(fn); },
			set oncancel(fn) {},
		};
	};
	Element.prototype.scrollIntoView = function () {};
});

function buildForm(html) {
	document.body.innerHTML = `<form id="f">${html}</form>`;
	return document.getElementById('f');
}

function group({ solo, openFirst, panels }) {
	const bodies = panels.map(p => `
		<details class="cms-accordion formgrid-panel">
			<summary>${p.title}</summary>
			<div class="content">${p.body || ''}</div>
		</details>`).join('');
	return `<div class="formgrid-accordion" data-solo-mode="${solo}" data-open-first="${openFirst}">${bodies}</div>`;
}

describe('FormAccordions', () => {
	test('a group of one stays closed', () => {
		const form = buildForm(group({ solo: false, openFirst: false, panels: [{ title: 'Advanced' }] }));
		new FormAccordions(form);

		expect(form.querySelector('details').open).toBe(false);
	});

	test('a linked group opens its first panel', () => {
		const form = buildForm(group({
			solo: true, openFirst: true,
			panels: [{ title: 'A' }, { title: 'B' }],
		}));
		new FormAccordions(form);

		const details = form.querySelectorAll('details');
		expect(details[0].open).toBe(true);
		expect(details[1].open).toBe(false);
	});

	test('two groups are independent', () => {
		// Real cross-group interaction, not just two groups that happen to
		// never open anything: opening a panel in the first group's solo-mode
		// Details instance must not touch the second group's state at all.
		const form = buildForm(
			group({ solo: true, openFirst: false, panels: [{ title: 'A1' }, { title: 'A2' }] }) +
			group({ solo: true, openFirst: false, panels: [{ title: 'B1' }, { title: 'B2' }] }),
		);
		const accordions = new FormAccordions(form);

		expect(accordions.groups.length).toBe(2);
		const [groupA, groupB] = accordions.groups;

		const groupBStateBefore = groupB.details.map(d => d.open);

		groupA._openDetail(groupA.details[0]);

		expect(groupA.details[0].open).toBe(true);
		expect(groupB.details.map(d => d.open)).toEqual(groupBStateBefore);
	});

	test('a field accordion nested inside a panel is not absorbed into the group', () => {
		// An image/gallery/property field renders its own details.cms-accordion.
		// It has no .formgrid-panel class and must not join the group, or solo
		// mode would close the panel when the field's editor is opened.
		const form = buildForm(group({
			solo: true, openFirst: true,
			panels: [
				{ title: 'A', body: '<details class="cms-accordion"><summary>Edit image</summary><div class="content">x</div></details>' },
				{ title: 'B' },
			],
		}));
		const accordions = new FormAccordions(form);

		expect(accordions.groups[0].details.length).toBe(2); // the two panels only
		expect(accordions.groups[0].details.some(d => d.querySelector('summary').textContent === 'Edit image')).toBe(false);
	});

	test('a nested accordion group inside a panel is not absorbed into the outer group', () => {
		// The formgrid grammar allows a >> << group nested inside another
		// panel's content, so a genuine details.cms-accordion.formgrid-panel
		// belonging to an *inner* group can appear as a non-direct descendant
		// of the outer wrapper. The plain class filter alone would let it
		// through unchanged (it has both classes, same as an outer panel) —
		// only `:scope >` excludes it.
		const nestedGroup = `<div class="formgrid-accordion" data-solo-mode="false" data-open-first="false">
			<details class="cms-accordion formgrid-panel">
				<summary>Inner</summary>
				<div class="content"></div>
			</details>
		</div>`;
		const form = buildForm(group({
			solo: true, openFirst: true,
			panels: [
				{ title: 'A', body: nestedGroup },
				{ title: 'B' },
			],
		}));
		const accordions = new FormAccordions(form);

		expect(accordions.groups[0].details.length).toBe(2); // the two outer panels only
		expect(accordions.groups[0].details.some(d => d.querySelector('summary').textContent === 'Inner')).toBe(false);
	});

	test('tcms:error-navigate opens the panel holding the field', () => {
		const form = buildForm(group({
			solo: true, openFirst: true,
			panels: [
				{ title: 'A' },
				{ title: 'B', body: '<div class="form-field" id="target"><input name="slug"></div>' },
			],
		}));
		new FormAccordions(form);

		const details = form.querySelectorAll('details');
		expect(details[1].open).toBe(false);

		const field = { container: document.getElementById('target'), property: 'slug' };
		form.dispatchEvent(new CustomEvent('tcms:error-navigate', { bubbles: true, detail: { field, property: 'slug' } }));

		expect(details[1].open).toBe(true);
	});

	test('tcms:error-navigate for a field outside any panel is a no-op', () => {
		const form = buildForm(
			'<div class="form-field" id="loose"><input name="title"></div>' +
			group({ solo: false, openFirst: false, panels: [{ title: 'A' }] }),
		);
		new FormAccordions(form);

		const field = { container: document.getElementById('loose'), property: 'title' };
		expect(() => {
			form.dispatchEvent(new CustomEvent('tcms:error-navigate', { bubbles: true, detail: { field, property: 'title' } }));
		}).not.toThrow();

		expect(form.querySelector('details').open).toBe(false);
	});

	test('constructing twice does not double-register a group', () => {
		const form = buildForm(group({ solo: true, openFirst: true, panels: [{ title: 'A' }, { title: 'B' }] }));
		const first  = new FormAccordions(form);
		const second = new FormAccordions(form);

		expect(second.groups.length).toBe(0); // already claimed by `first`
		expect(first.groups.length).toBe(1);
	});

	test('fields inside a panel are reinitialised the first time it opens', () => {
		const form = buildForm(group({
			solo: false, openFirst: false,
			panels: [{ title: 'Advanced', body: '<div class="form-field" id="f1"></div>' }],
		}));
		const field = document.getElementById('f1');
		let calls = 0;
		field.totalfield = { reinit: () => calls++ };

		new FormAccordions(form);
		expect(calls).toBe(0);            // still closed, not yet needed

		const panel = form.querySelector('details.formgrid-panel');
		panel.open = true;
		panel.dispatchEvent(new Event('toggle'));
		expect(calls).toBe(1);

		panel.open = false;
		panel.dispatchEvent(new Event('toggle'));
		panel.open = true;
		panel.dispatchEvent(new Event('toggle'));
		expect(calls).toBe(1);            // once only — reopening must not rebuild
	});

	test('a linked group reinitialises the panel Details opens at startup', () => {
		const form = buildForm(group({
			solo: true, openFirst: true,
			panels: [
				{ title: 'A', body: '<div class="form-field" id="a1"></div>' },
				{ title: 'B', body: '<div class="form-field" id="b1"></div>' },
			],
		}));
		const a = document.getElementById('a1');
		const b = document.getElementById('b1');
		let aCalls = 0, bCalls = 0;
		a.totalfield = { reinit: () => aCalls++ };
		b.totalfield = { reinit: () => bCalls++ };

		new FormAccordions(form);
		expect(aCalls).toBe(1);   // Details opened panel A, so its fields rebuilt
		expect(bCalls).toBe(0);   // B is still closed
	});

	test('a field inside a closed dialog is left alone', () => {
		const form = buildForm(group({
			solo: false, openFirst: false,
			panels: [{ title: 'P', body: '<dialog><div class="form-field" id="d1"></div></dialog>' }],
		}));
		const d = document.getElementById('d1');
		let calls = 0;
		d.totalfield = { reinit: () => calls++ };

		new FormAccordions(form);
		const panel = form.querySelector('details.formgrid-panel');
		panel.open = true;
		panel.dispatchEvent(new Event('toggle'));

		expect(calls).toBe(0);   // the dialog is still closed; rebuilding now would
		                         // just reconstruct it broken again
	});
});
