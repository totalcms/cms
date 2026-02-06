/**
 * LinkDialog - Creates and manages the link insert/edit dialog.
 * Replaces the browser prompt() with a proper dialog using the ste-dialog pattern.
 */

export function createLinkDialog(editor) {
	const attrs = editor.getAttributes('link');
	const previousUrl = attrs.href || '';
	const previousTarget = attrs.target || '';
	const isEditing = !!previousUrl;

	const overlay = document.createElement('div');
	overlay.className = 'ste-dialog-overlay';

	const dialog = document.createElement('div');
	dialog.className = 'ste-dialog';

	dialog.innerHTML = `
		<div class="ste-dialog-header">
			<h3>${isEditing ? 'Edit Link' : 'Insert Link'}</h3>
			<button type="button" class="ste-dialog-close" aria-label="Close">&times;</button>
		</div>
		<div class="ste-dialog-body">
			<div class="ste-link-field">
				<label class="ste-link-label" for="ste-link-url">URL</label>
				<input type="text" id="ste-link-url" class="ste-url-input" placeholder="https://example.com or /path" value="${escapeAttr(previousUrl)}">
			</div>
			<div class="ste-link-field">
				<label class="ste-link-checkbox">
					<input type="checkbox" id="ste-link-newtab" ${previousTarget === '_blank' ? 'checked' : ''}>
					Open in new tab
				</label>
			</div>
		</div>
		<div class="ste-dialog-footer">
			${isEditing ? '<button type="button" class="ste-dialog-btn ste-dialog-btn--remove">Remove Link</button>' : ''}
			<div class="ste-dialog-actions">
				<button type="button" class="ste-dialog-btn ste-dialog-btn--cancel">Cancel</button>
				<button type="button" class="ste-dialog-btn ste-dialog-btn--insert">${isEditing ? 'Update' : 'Insert'}</button>
			</div>
		</div>
	`;

	overlay.appendChild(dialog);

	const urlInput = dialog.querySelector('#ste-link-url');
	const newTabCheckbox = dialog.querySelector('#ste-link-newtab');
	const insertBtn = dialog.querySelector('.ste-dialog-btn--insert');
	const removeBtn = dialog.querySelector('.ste-dialog-btn--remove');

	function close() {
		overlay.remove();
		editor.chain().focus().run();
	}

	function applyLink() {
		const url = urlInput.value.trim();
		if (!url) {
			// Empty URL = remove link
			editor.chain().focus().extendMarkRange('link').unsetLink().run();
			overlay.remove();
			return;
		}

		// Auto-prefix https:// only for bare domains (skip paths, protocols, anchors)
		const finalUrl = url.match(/^https?:\/\/|^mailto:|^tel:|^#|^\//) ? url : `https://${url}`;
		const target = newTabCheckbox.checked ? '_blank' : null;
		const rel = newTabCheckbox.checked ? 'noopener noreferrer' : null;

		editor.chain().focus().extendMarkRange('link').setLink({
			href: finalUrl,
			target,
			rel,
		}).run();

		overlay.remove();
	}

	// Insert/Update button
	insertBtn.addEventListener('click', applyLink);

	// Remove link button
	if (removeBtn) {
		removeBtn.addEventListener('click', () => {
			editor.chain().focus().extendMarkRange('link').unsetLink().run();
			overlay.remove();
		});
	}

	// Cancel & close
	dialog.querySelector('.ste-dialog-close').addEventListener('click', close);
	dialog.querySelector('.ste-dialog-btn--cancel').addEventListener('click', close);
	overlay.addEventListener('click', (e) => {
		if (e.target === overlay) close();
	});

	// Enter key submits
	urlInput.addEventListener('keydown', (e) => {
		if (e.key === 'Enter') {
			e.preventDefault();
			applyLink();
		}
		if (e.key === 'Escape') {
			e.preventDefault();
			close();
		}
	});

	// Mount and focus
	document.body.appendChild(overlay);
	urlInput.focus();
	urlInput.select();
}

function escapeAttr(str) {
	return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
