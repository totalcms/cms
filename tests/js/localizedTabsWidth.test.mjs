import LocalizedTextField from '../../javascript/totalform/localizedtext.js';

//-----------------------------------------------
// trackTabsWidth() publishes the locale tab strip's width as
// `--locale-tabs-width` so the help overlay can stop short of the tabs
// instead of painting its opaque background over them. CSS cannot measure
// the strip — its width depends on how many locales are configured and how
// wide their labels render — so this is the one thing the field has to tell
// the stylesheet.
//-----------------------------------------------

// A localized field whose tabs and label report the geometry the test wants.
// jsdom lays nothing out, so both are stubbed: `offsetTop` decides whether the
// header has wrapped, and the strip's rect is the width to publish.
function field({ tabsWidth = 320, tabsTop = 0, labelTop = 0, withTabs = true, withLabel = true } = {}) {
	const container = document.createElement('div');
	container.className = 'form-field localizedtext-field';

	const header = document.createElement('div');
	header.className = 'localized-header';
	container.appendChild(header);

	if (withLabel) {
		const label = document.createElement('label');
		Object.defineProperty(label, 'offsetTop', { value: labelTop });
		header.appendChild(label);
	}

	if (withTabs) {
		const tabs = document.createElement('div');
		tabs.className = 'locale-tabs';
		Object.defineProperty(tabs, 'offsetTop', { value: tabsTop });
		tabs.getBoundingClientRect = () => ({ width: tabsWidth, height: 24, top: tabsTop, left: 0, right: tabsWidth, bottom: tabsTop + 24 });
		header.appendChild(tabs);
	}

	const instance = Object.create(LocalizedTextField.prototype);
	instance.container = container;

	return instance;
}

const published = instance => instance.container.style.getPropertyValue('--locale-tabs-width');

describe('LocalizedTextField.trackTabsWidth', () => {
	test('publishes the tab strip width when the tabs share the label row', () => {
		const f = field({ tabsWidth: 335 });
		f.trackTabsWidth();
		expect(published(f)).toBe('335px');
	});

	test('rounds a fractional width up, so the overlay never laps the first tab', () => {
		const f = field({ tabsWidth: 334.2 });
		f.trackTabsWidth();
		expect(published(f)).toBe('335px');
	});

	test('publishes zero once the header wraps the tabs onto their own row', () => {
		// Nothing left on the label row to avoid — the overlay may use it all.
		const f = field({ tabsWidth: 335, labelTop: 0, tabsTop: 28 });
		f.trackTabsWidth();
		expect(published(f)).toBe('0px');
	});

	test('treats a sub-pixel row difference as the same row', () => {
		const f = field({ tabsWidth: 200, labelTop: 0, tabsTop: 1 });
		f.trackTabsWidth();
		expect(published(f)).toBe('200px');
	});

	test('does nothing when the field has no tabs or no label', () => {
		const noTabs = field({ withTabs: false });
		expect(() => noTabs.trackTabsWidth()).not.toThrow();
		expect(published(noTabs)).toBe('');

		const noLabel = field({ withLabel: false });
		expect(() => noLabel.trackTabsWidth()).not.toThrow();
		expect(published(noLabel)).toBe('');
	});

	test('re-measures when the strip or the field resizes', () => {
		const observed = [];
		const original = globalThis.ResizeObserver;
		globalThis.ResizeObserver = class {
			constructor(cb) { this.cb = cb; }
			observe(el) { observed.push(el); }
		};

		try {
			const f = field({ tabsWidth: 120 });
			f.trackTabsWidth();
			// The strip itself and the field around it: the first catches a late
			// font swap, the second catches the header wrapping.
			expect(observed).toHaveLength(2);
			expect(observed[0].className).toBe('locale-tabs');
			expect(observed[1]).toBe(f.container);

			// A callback run after a resize republishes the current width.
			f.container.style.setProperty('--locale-tabs-width', '0px');
			f.tabsObserver.cb();
			expect(published(f)).toBe('120px');
		} finally {
			globalThis.ResizeObserver = original;
		}
	});
});
