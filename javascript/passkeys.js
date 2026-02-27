/**
 * Passkey Manager - handles WebAuthn credential management on the profile page.
 */
export default class PasskeyManager {
	constructor(container) {
		this.container   = container;
		this.api         = container.dataset.api || '';
		this.listEl      = container.querySelector('#passkeys-list');
		this.statusEl    = container.querySelector('#passkey-status');
		this.registerBtn = container.querySelector('#passkey-register-btn');

		if (!window.PublicKeyCredential) {
			container.style.display = 'none';
			return;
		}

		this.registerBtn?.addEventListener('click', () => this.registerPasskey());
		this.loadPasskeys();
	}

	async loadPasskeys() {
		try {
			const res = await fetch(`${this.api}/passkeys/list`, {
				credentials: 'same-origin',
			});
			if (!res.ok) throw new Error('Failed to load passkeys');

			const passkeys = await res.json();
			this.renderPasskeyList(passkeys);
		} catch {
			this.showStatus('Failed to load passkeys', 'error');
		}
	}

	renderPasskeyList(passkeys) {
		if (!this.listEl) return;

		if (!passkeys.length) {
			this.listEl.innerHTML = '<p class="passkeys-empty">No passkeys registered yet.</p>';
			return;
		}

		const rows = passkeys.map(pk => {
			const created  = pk.createdAt ? new Date(pk.createdAt).toLocaleDateString() : '';
			const lastUsed = pk.lastUsed ? new Date(pk.lastUsed).toLocaleDateString() : '';

			return `<tr>
				<td>${this.escapeHtml(pk.name)}</td>
				<td>${created}</td>
				<td>${lastUsed}</td>
				<td><button type="button" class="cms-button cms-button-small cms-button-danger passkey-delete"
					data-id="${this.escapeAttr(pk.credentialId)}">Delete</button></td>
			</tr>`;
		}).join('');

		this.listEl.innerHTML = `
			<table class="passkeys-table">
				<thead>
					<tr>
						<th>Name</th>
						<th>Created</th>
						<th>Last Used</th>
						<th></th>
					</tr>
				</thead>
				<tbody>${rows}</tbody>
			</table>`;

		this.listEl.querySelectorAll('.passkey-delete').forEach(btn => {
			btn.addEventListener('click', () => this.deletePasskey(btn.dataset.id));
		});
	}

	async registerPasskey() {
		const name = this.promptName();
		if (name === null) return;

		this.registerBtn.disabled    = true;
		this.registerBtn.textContent = 'Registering...';

		try {
			// Get registration options
			const optRes = await fetch(`${this.api}/passkeys/register/options`, {
				credentials: 'same-origin',
			});
			if (!optRes.ok) throw new Error('Failed to get registration options');

			const options     = await optRes.json();
			const createOpts  = this.buildCreationOptions(options);

			// Create credential via browser API
			const credential = await navigator.credentials.create({
				publicKey: createOpts,
			});

			// Send to server
			const body = JSON.stringify({
				name,
				credential: {
					id:       credential.id,
					rawId:    bufferToBase64url(credential.rawId),
					type:     credential.type,
					response: {
						attestationObject: bufferToBase64url(credential.response.attestationObject),
						clientDataJSON:    bufferToBase64url(credential.response.clientDataJSON),
						transports:        credential.response.getTransports?.() || [],
					},
				},
			});

			const regRes = await fetch(`${this.api}/passkeys/register`, {
				method:      'POST',
				credentials: 'same-origin',
				headers:     { 'Content-Type': 'application/json' },
				body,
			});

			const data = await regRes.json();

			if (data.success) {
				this.showStatus('Passkey registered successfully!', 'success');
				this.loadPasskeys();
			} else {
				throw new Error(data.error || 'Registration failed');
			}
		} catch (err) {
			if (err.name !== 'AbortError' && err.name !== 'NotAllowedError') {
				this.showStatus(err.message || 'Registration failed', 'error');
			}
		} finally {
			this.registerBtn.disabled    = false;
			this.registerBtn.textContent = 'Register New Passkey';
		}
	}

	async deletePasskey(credentialId) {
		if (!confirm('Delete this passkey? You will no longer be able to sign in with it.')) return;

		try {
			const res = await fetch(`${this.api}/passkeys/${encodeURIComponent(credentialId)}`, {
				method:      'DELETE',
				credentials: 'same-origin',
			});

			if (!res.ok) throw new Error('Failed to delete passkey');

			this.showStatus('Passkey deleted', 'success');
			this.loadPasskeys();
		} catch (err) {
			this.showStatus(err.message || 'Failed to delete passkey', 'error');
		}
	}

	promptName() {
		return prompt('Give this passkey a name (e.g., "MacBook Pro", "1Password"):', 'My Passkey');
	}

	buildCreationOptions(options) {
		const publicKey = {
			challenge:        base64urlToBuffer(options.challenge),
			rp:               options.rp,
			user: {
				id:          base64urlToBuffer(options.user.id),
				name:        options.user.name,
				displayName: options.user.displayName,
			},
			pubKeyCredParams:       options.pubKeyCredParams,
			timeout:                options.timeout,
			attestation:            options.attestation || 'none',
			authenticatorSelection: options.authenticatorSelection,
		};

		if (options.excludeCredentials) {
			publicKey.excludeCredentials = options.excludeCredentials.map(c => ({
				id:         base64urlToBuffer(c.id),
				type:       c.type,
				transports: c.transports,
			}));
		}

		return publicKey;
	}

	showStatus(message, type) {
		if (!this.statusEl) return;
		this.statusEl.textContent = message;
		this.statusEl.className   = `passkey-status passkey-status-${type}`;

		clearTimeout(this._statusTimeout);
		this._statusTimeout = setTimeout(() => {
			this.statusEl.className = 'cms-hide';
		}, 5000);
	}

	escapeHtml(str) {
		const div = document.createElement('div');
		div.textContent = str;
		return div.innerHTML;
	}

	escapeAttr(str) {
		return str.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
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
