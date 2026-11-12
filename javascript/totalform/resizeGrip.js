//-----------------------------------------------
// A drag handle in the bottom-right corner that resizes a box vertically.
//
// For boxes whose content is a scrolling editor (CodeMirror): CSS
// `resize: vertical` works in Chrome, but Safari draws no grip when a
// scrolling child covers the corner. This handle is an ordinary element, so
// it sits above the editor and works the same in every browser.
//-----------------------------------------------
export const GRIP_CLASS = 'totalform-resize-grip';
export const MIN_HEIGHT = 60;

export function addResizeGrip(box, { minHeight = MIN_HEIGHT } = {}) {
	const grip = document.createElement('div');
	grip.className = GRIP_CLASS;
	grip.setAttribute('aria-hidden', 'true');
	box.appendChild(grip);

	grip.addEventListener('pointerdown', (event) => {
		if (event.button !== 0) return;
		// Also keeps the drag from selecting text on the page.
		event.preventDefault();

		const startY      = event.clientY;
		const startHeight = box.offsetHeight;

		const move = (moveEvent) => {
			box.style.height = Math.max(minHeight, startHeight + moveEvent.clientY - startY) + 'px';
		};
		const stop = () => {
			window.removeEventListener('pointermove', move);
			window.removeEventListener('pointerup', stop);
			window.removeEventListener('pointercancel', stop);
		};

		window.addEventListener('pointermove', move);
		window.addEventListener('pointerup', stop);
		window.addEventListener('pointercancel', stop);
	});

	return grip;
}
