import { addResizeGrip, GRIP_CLASS, MIN_HEIGHT } from '../../javascript/totalform/resizeGrip.js';

//-----------------------------------------------
// The corner handle that resizes an editor box in every browser, Safari
// included (it draws no CSS `resize` grip over a scrolling editor).
//-----------------------------------------------

function box(height = 180) {
	const el = document.createElement('div');
	document.body.appendChild(el);
	Object.defineProperty(el, 'offsetHeight', { get: () => parseInt(el.style.height || height) });
	return el;
}

function pointer(target, type, clientY, button = 0) {
	const event = new MouseEvent(type, { bubbles: true, cancelable: true, clientY, button });
	target.dispatchEvent(event);
	return event;
}

describe('addResizeGrip', () => {
	test('adds the grip to the box', () => {
		const el   = box();
		const grip = addResizeGrip(el);
		expect(grip.parentNode).toBe(el);
		expect(grip.classList.contains(GRIP_CLASS)).toBe(true);
	});

	test('dragging the grip changes the height by the distance moved', () => {
		const el   = box(180);
		const grip = addResizeGrip(el);

		pointer(grip, 'pointerdown', 300);
		pointer(window, 'pointermove', 400);
		expect(el.style.height).toBe('280px');
		pointer(window, 'pointermove', 250);
		expect(el.style.height).toBe('130px');
		pointer(window, 'pointerup', 250);

		// Released: moving the pointer no longer resizes.
		pointer(window, 'pointermove', 600);
		expect(el.style.height).toBe('130px');
	});

	test('never shrinks below the minimum', () => {
		const el   = box(180);
		const grip = addResizeGrip(el);

		pointer(grip, 'pointerdown', 300);
		pointer(window, 'pointermove', 0);
		pointer(window, 'pointerup', 0);
		expect(el.style.height).toBe(MIN_HEIGHT + 'px');
	});

	test('ignores a right click', () => {
		const el   = box(180);
		const grip = addResizeGrip(el);

		pointer(grip, 'pointerdown', 300, 2);
		pointer(window, 'pointermove', 400);
		expect(el.style.height).toBe('');
	});
});
