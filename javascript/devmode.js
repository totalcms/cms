/**
 * Total CMS Development Mode Toggle
 *
 * Manages the development mode toggle switch with real-time countdown
 * and automatic API calls to enable/disable development mode.
 */
export default class DevModeToggle {

	constructor(toggle, options = {}) {
		this.toggle = toggle;
		this.endpoint = this.toggle.dataset.apiEndpoint || '/cache/devmode';
		this.countdownElement = document.getElementById('devmode-countdown');
		this.helpElement = this.toggle.closest('.form-field')?.querySelector('.help');
		
		this.countdownInterval = null;
		this.remainingSeconds = parseInt(options.remainingSeconds) || 0;

		this.init();
	}

	init() {
		this.toggle.addEventListener('change', this.onToggleChange.bind(this));
		
		// Start countdown if dev mode is active
		if (this.remainingSeconds > 0) {
			this.startCountdown();
		}
	}

	onToggleChange(event) {
		const enable = event.target.checked;
		const method = enable ? 'POST' : 'DELETE';

		this.makeApiCall(method)
			.then(data => this.handleApiSuccess(data))
			.catch(error => this.handleApiError(error, enable));
	}

	async makeApiCall(method) {
		const response = await fetch(this.endpoint, {
			method: method,
			headers: {
				'Content-Type': 'application/json',
				'X-Requested-With': 'XMLHttpRequest'
			}
		});

		if (!response.ok) {
			throw new Error(`HTTP error! status: ${response.status}`);
		}

		return await response.json();
	}

	handleApiSuccess(data) {
		if (!data.success) {
			throw new Error(data.message || 'Unknown error');
		}

		// Update UI based on new state
		if (data.devmode.enabled) {
			this.remainingSeconds = data.devmode.remaining_seconds;
			this.updateHelpText(`<strong>Development mode is active.</strong> Remaining time: <span id="devmode-countdown">${data.devmode.remaining_formatted}</span>`);
			this.countdownElement = document.getElementById('devmode-countdown');
			this.startCountdown();
		} else {
			this.remainingSeconds = 0;
			this.updateHelpText('Development mode is disabled. Caching is active.');
			this.stopCountdown();
		}

		console.log(data.message);
	}

	handleApiError(error, originalState) {
		// Revert toggle state on error
		this.toggle.checked = !originalState;
		console.error('Error toggling dev mode:', error);
	}

	updateHelpText(html) {
		if (this.helpElement) {
			this.helpElement.innerHTML = html;
		}
	}

	startCountdown() {
		this.stopCountdown(); // Clear any existing interval
		
		if (this.remainingSeconds <= 0) {
			return;
		}

		this.countdownInterval = setInterval(() => {
			this.updateCountdown();
		}, 1000);
	}

	stopCountdown() {
		if (this.countdownInterval) {
			clearInterval(this.countdownInterval);
			this.countdownInterval = null;
		}
	}

	updateCountdown() {
		if (this.remainingSeconds <= 0) {
			this.stopCountdown();
			// Refresh the page when dev mode expires
			window.location.reload();
			return;
		}

		const hours = Math.floor(this.remainingSeconds / 3600);
		const minutes = Math.floor((this.remainingSeconds % 3600) / 60);
		const seconds = this.remainingSeconds % 60;
		
		const formatted = `${hours}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
		
		if (this.countdownElement) {
			this.countdownElement.textContent = formatted;
		}
		
		this.remainingSeconds--;
	}

	destroy() {
		this.stopCountdown();
		this.toggle.removeEventListener('change', this.onToggleChange);
	}
}