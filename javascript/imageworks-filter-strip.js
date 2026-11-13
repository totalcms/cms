const MAX_CHAIN = 3;

/**
 * The admin ImageWorks filter strip. Looks and duotones are pick-one; effect
 * tiles (vignette, and any `effect` filter from config or an extension)
 * toggle on top of them. The pressed tiles write the hidden `filt` input as a
 * chain: the look or duotone first, then the effects in strip order, at most
 * three names (all a chain takes).
 *
 * The `duo` text field only applies to duotone, so it is shown and enabled
 * while duotone is in the chain, and hidden and disabled otherwise. A disabled
 * field stays out of the FormData the preview URL is built from, yet keeps
 * what was typed for when duotone is picked again.
 *
 * @param {HTMLElement|null} filterStrip the #filter-strip element
 */
export function initFilterStrip(filterStrip) {
	if (!filterStrip) {
		return;
	}

	const filtInput = filterStrip.querySelector('input[name="filt"]');
	const tiles = Array.from(filterStrip.querySelectorAll(".filter-tile"));
	const duoBox = document.getElementById("filter-duo");
	const duoInput = duoBox?.querySelector('input[name="duo"]');

	const isEffect = tile => tile.dataset.group === "effect";
	const isPressed = tile => tile.getAttribute("aria-pressed") === "true";
	const press = (tile, pressed) => tile.setAttribute("aria-pressed", pressed ? "true" : "false");

	const chain = () => [
		...tiles.filter(tile => !isEffect(tile) && isPressed(tile)),
		...tiles.filter(tile => isEffect(tile) && isPressed(tile)),
	].map(tile => tile.dataset.filter);

	const showDuo = () => {
		if (!duoBox || !duoInput) {
			return;
		}
		const isDuotone = chain().includes("duotone");
		duoBox.hidden = !isDuotone;
		duoInput.disabled = !isDuotone;
	};

	tiles.forEach(tile => {
		tile.addEventListener("click", () => {
			if (isPressed(tile)) {
				press(tile, false);
			} else {
				// A new look replaces the current one; an effect is added
				const replaced = isEffect(tile) ? [] : tiles.filter(other => !isEffect(other) && isPressed(other));
				if (chain().length - replaced.length >= MAX_CHAIN) {
					return;
				}
				replaced.forEach(other => press(other, false));
				press(tile, true);
			}
			filtInput.value = chain().join(",");
			showDuo();
		});
	});

	showDuo();
}
