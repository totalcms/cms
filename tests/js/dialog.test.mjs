import Dialog from '../../javascript/totalform/dialog.js';

//-----------------------------------------------
// Escape closes a native <dialog> via the browser's cancel behavior WITHOUT
// going through Dialog.close() — so onClose cleanup (gallery/image dialogs
// persist their edits there) was silently skipped and the body stayed
// scroll-locked. The cancel listener must run the same cleanup.
//-----------------------------------------------
describe('Dialog', () => {
	test('native cancel (Escape) runs onClose and restores body scrolling', () => {
		const el = document.createElement('dialog');
		document.body.appendChild(el);
		const onClose = vi.fn();
		new Dialog(el, { onClose });

		document.body.style.position = 'fixed';
		el.dispatchEvent(new Event('cancel'));

		expect(onClose).toHaveBeenCalledTimes(1);
		expect(document.body.style.position).toBe('');
	});

	test('close() runs onClose exactly once', () => {
		const el = document.createElement('dialog');
		el.close = vi.fn(); // jsdom's <dialog> has no close()
		document.body.appendChild(el);
		const onClose = vi.fn();
		const dialog = new Dialog(el, { onClose });

		dialog.close();

		expect(onClose).toHaveBeenCalledTimes(1);
	});
});
