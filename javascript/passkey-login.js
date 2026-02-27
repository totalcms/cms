/**
 * Passkey Login - handles WebAuthn authentication on the login page.
 */
export default class PasskeyLogin {
	constructor(button) {
		this.button = button;
		this.api    = button.dataset.api || '';

		if (!window.PublicKeyCredential) {
			button.style.display = 'none';
			const divider = button.closest('.login-form')?.querySelector('.login-divider');
			if (divider) divider.style.display = 'none';
			return;
		}

		button.addEventListener('click', () => this.loginWithPasskey());
		this.tryConditionalMediation();
	}

	async tryConditionalMediation() {
		try {
			if (!PublicKeyCredential.isConditionalMediationAvailable ||
				!await PublicKeyCredential.isConditionalMediationAvailable()) {
				return;
			}

			const options = await this.fetchOptions();
			if (!options) return;

			const credential = await navigator.credentials.get({
				publicKey: this.buildRequestOptions(options),
				mediation: 'conditional',
			});

			if (credential) {
				await this.submitAssertion(credential);
			}
		} catch {
			// Conditional mediation silently fails if user interacts with form instead
		}
	}

	async loginWithPasskey() {
		this.button.disabled  = true;
		this.button.textContent = 'Authenticating...';

		try {
			const options = await this.fetchOptions();
			if (!options) throw new Error('Failed to get authentication options');

			const credential = await navigator.credentials.get({
				publicKey: this.buildRequestOptions(options),
			});

			await this.submitAssertion(credential);
		} catch (err) {
			this.button.disabled  = false;
			this.button.textContent = 'Sign in with Passkey';

			if (err.name !== 'AbortError' && err.name !== 'NotAllowedError') {
				this.showError(err.message || 'Passkey authentication failed');
			}
		}
	}

	async fetchOptions() {
		const res = await fetch(`${this.api}/passkeys/login/options`, {
			credentials: 'same-origin',
		});
		if (!res.ok) return null;
		return res.json();
	}

	async submitAssertion(credential) {
		const body = JSON.stringify({
			id:       credential.id,
			rawId:    bufferToBase64url(credential.rawId),
			type:     credential.type,
			response: {
				authenticatorData: bufferToBase64url(credential.response.authenticatorData),
				clientDataJSON:    bufferToBase64url(credential.response.clientDataJSON),
				signature:         bufferToBase64url(credential.response.signature),
				userHandle:        credential.response.userHandle
					? bufferToBase64url(credential.response.userHandle)
					: null,
			},
		});

		const res = await fetch(`${this.api}/passkeys/login`, {
			method:      'POST',
			credentials: 'same-origin',
			headers:     { 'Content-Type': 'application/json' },
			body,
		});

		const data = await res.json();

		if (data.success && data.redirect) {
			window.location.href = data.redirect;
		} else {
			throw new Error(data.error || 'Authentication failed');
		}
	}

	buildRequestOptions(options) {
		const publicKey = {
			challenge:        base64urlToBuffer(options.challenge),
			rpId:             options.rpId,
			timeout:          options.timeout,
			userVerification: options.userVerification || 'preferred',
		};

		if (options.allowCredentials) {
			publicKey.allowCredentials = options.allowCredentials.map(c => ({
				id:         base64urlToBuffer(c.id),
				type:       c.type,
				transports: c.transports,
			}));
		}

		return publicKey;
	}

	showError(message) {
		const section = this.button.closest('.login-form');
		if (!section) return;

		let alert = section.querySelector('.passkey-error');
		if (!alert) {
			alert = document.createElement('p');
			alert.className = 'cms-twig-error passkey-error';
			alert.setAttribute('role', 'alert');
			section.appendChild(alert);
		}
		alert.textContent = message;
	}
}

function bufferToBase64url(buffer) {
	const bytes = new Uint8Array(buffer);
	let str = '';
	for (const byte of bytes) str += String.fromCharCode(byte);
	return btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function base64urlToBuffer(base64url) {
	const base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
	const pad    = base64.length % 4 === 0 ? '' : '='.repeat(4 - (base64.length % 4));
	const binary = atob(base64 + pad);
	const bytes  = new Uint8Array(binary.length);
	for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
	return bytes.buffer;
}
