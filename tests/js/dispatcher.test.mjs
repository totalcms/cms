import TotalDispatcher from '../../javascript/totalform/dispatcher.js';

//-----------------------------------------------
// TotalDispatcher debounces field events (default 300ms) and bubbles them with a
// detail payload. The whole form keys its dirty/save reactions off these, so the
// debounce-per-event-name behaviour is worth pinning.
//-----------------------------------------------

describe('TotalDispatcher', () => {
	test('debounces repeated dispatches of the same event into one', () => {
		vi.useFakeTimers();
		const el = document.createElement('div');
		const dispatcher = new TotalDispatcher(el);
		let count = 0;
		el.addEventListener('field-change', () => { count += 1; });

		dispatcher.dispatchEvent('field-change', {});
		dispatcher.dispatchEvent('field-change', {});
		dispatcher.dispatchEvent('field-change', {});
		expect(count).toBe(0); // nothing fired synchronously
		vi.advanceTimersByTime(300);

		expect(count).toBe(1);
		vi.useRealTimers();
	});

	test('debounces each event name independently', () => {
		vi.useFakeTimers();
		const el = document.createElement('div');
		const dispatcher = new TotalDispatcher(el);
		const seen = [];
		el.addEventListener('a', () => seen.push('a'));
		el.addEventListener('b', () => seen.push('b'));

		dispatcher.dispatchEvent('a', {});
		dispatcher.dispatchEvent('b', {});
		vi.advanceTimersByTime(300);

		expect(seen.sort()).toEqual(['a', 'b']);
		vi.useRealTimers();
	});

	test('delivers the detail payload and bubbles to ancestors', () => {
		vi.useFakeTimers();
		const parent = document.createElement('div');
		const child = document.createElement('div');
		parent.appendChild(child);
		const dispatcher = new TotalDispatcher(child);
		let detail = null;
		parent.addEventListener('evt', (e) => { detail = e.detail; });

		dispatcher.dispatchEvent('evt', { field: 'x' });
		vi.advanceTimersByTime(300);

		expect(detail).toEqual({ field: 'x' });
		vi.useRealTimers();
	});
});
