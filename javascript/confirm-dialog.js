/**
 * Total CMS Confirm Dialog
 *
 * Promise-based replacement for window.confirm() with a countdown-disabled
 * confirm button. Forces the user to pause and read the message before
 * confirming destructive actions.
 *
 * Three ways to use:
 *
 *   1. HTMX (automatic via admin.js htmx:confirm listener):
 *      <button hx-delete="/api/posts/42" hx-confirm="Delete this post?">Delete</button>
 *      <button hx-delete="/api/nuke" hx-confirm="Wipe all data?"
 *              data-confirm-title="Nuclear option"
 *              data-confirm-countdown="10">Nuke</button>
 *
 *   2. Direct JS call:
 *      tcmsConfirm({ message: 'Delete this post?' }).then(ok => {
 *          if (ok) performDelete();
 *      });
 *
 *   3. Awaited:
 *      if (await tcmsConfirm({ message: 'Delete?', countdown: 3 })) { ... }
 *
 * Countdown resolution order:
 *   options.countdown -> window.TCMS_CONFIG.confirmCountdown -> 5
 */

const DEFAULT_COUNTDOWN = 5;

function resolveCountdown(override) {
	if (typeof override === 'number' && override >= 0) return override;
	const configured = window.TCMS_CONFIG?.confirmCountdown;
	if (typeof configured === 'number' && configured >= 0) return configured;
	return DEFAULT_COUNTDOWN;
}

export default function tcmsConfirm(options = {}) {
	const {
		title        = '',
		message      = 'Are you sure?',
		confirmLabel = "Yes, I'm sure",
		cancelLabel  = 'Cancel',
		countdown    = null,
	} = options;

	const seconds = resolveCountdown(countdown);

	return new Promise((resolve) => {
		const dialog = document.createElement('dialog');
		dialog.className = 'cms-modal small cms-confirm';

		const heading = title ? `<h2>${escapeHtml(title)}</h2>` : '';
		dialog.innerHTML = `
			${heading}
			<p class="cms-confirm-message">${escapeHtml(message)}</p>
			<div class="cms-confirm-buttons">
				<button type="button" class="dash-button transparent cms-confirm-cancel">${escapeHtml(cancelLabel)}</button>
				<button type="button" class="dash-button cms-confirm-ok" ${seconds > 0 ? 'disabled' : ''}>
					<span class="cms-confirm-ok-label">${escapeHtml(confirmLabel)}</span>
					<span class="cms-confirm-ok-counter"></span>
				</button>
			</div>
		`;

		document.body.appendChild(dialog);

		const okBtn      = dialog.querySelector('.cms-confirm-ok');
		const cancelBtn  = dialog.querySelector('.cms-confirm-cancel');
		const counterEl  = dialog.querySelector('.cms-confirm-ok-counter');

		let remaining = seconds;
		let timerId   = null;

		const renderCounter = () => {
			counterEl.textContent = remaining > 0 ? ` (${remaining})` : '';
		};

		const stopTimer = () => {
			if (timerId !== null) {
				clearInterval(timerId);
				timerId = null;
			}
		};

		const cleanup = (result) => {
			stopTimer();
			dialog.close();
			dialog.remove();
			resolve(result);
		};

		if (seconds > 0) {
			renderCounter();
			timerId = setInterval(() => {
				remaining -= 1;
				renderCounter();
				if (remaining <= 0) {
					stopTimer();
					okBtn.disabled = false;
				}
			}, 1000);
		}

		okBtn.addEventListener('click', () => cleanup(true));
		cancelBtn.addEventListener('click', () => cleanup(false));
		dialog.addEventListener('cancel', (e) => {
			e.preventDefault();
			cleanup(false);
		});

		dialog.showModal();
		cancelBtn.focus();
	});
}

function escapeHtml(str) {
	return String(str)
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&#039;');
}

globalThis.tcmsConfirm = tcmsConfirm;
