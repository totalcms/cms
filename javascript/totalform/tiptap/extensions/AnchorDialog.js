/**
 * AnchorDialog - Creates and manages the anchor ID dialog.
 * Uses a native <dialog> element so it joins the top layer alongside any
 * enclosing modal (e.g., a deck dialog hosting the styledtext field).
 */

export function createAnchorDialog(editor) {
	const currentId = getAnchorId(editor);
	const isEditing = !!currentId;

	const dialog = document.createElement('dialog');
	dialog.className = 'ste-dialog';

	dialog.innerHTML = `
		<div class="ste-dialog-header">
			<h3>${isEditing ? 'Edit Anchor' : 'Insert Anchor'}</h3>
			<button type="button" class="ste-dialog-close" aria-label="Close">&times;</button>
		</div>
		<div class="ste-dialog-body">
			<div class="ste-link-field">
				<label class="ste-link-label" for="ste-anchor-id">ID</label>
				<input type="text" id="ste-anchor-id" class="ste-url-input" placeholder="my-section" value="${escapeAttr(currentId || '')}" spellcheck="false">
			</div>
		</div>
		<div class="ste-dialog-footer">
			${isEditing ? '<button type="button" class="ste-dialog-btn ste-dialog-btn--remove ste-dialog-btn--icon" style="--btn-icon: var(--icon-ste-trash)" aria-label="Remove Anchor" title="Remove Anchor"></button>' : ''}
			<div class="ste-dialog-actions">
				<button type="button" class="ste-dialog-btn ste-dialog-btn--cancel">Cancel</button>
				<button type="button" class="ste-dialog-btn ste-dialog-btn--insert">${isEditing ? 'Update' : 'Apply'}</button>
			</div>
		</div>
	`;

	const idInput = dialog.querySelector('#ste-anchor-id');
	const insertBtn = dialog.querySelector('.ste-dialog-btn--insert');
	const removeBtn = dialog.querySelector('.ste-dialog-btn--remove');

	function applyAnchor() {
		const id = idInput.value.trim().replace(/\s+/g, '-');
		if (id) {
			editor.chain().focus().setAnchorId(id).run();
		} else {
			editor.chain().focus().removeAnchorId().run();
		}
		dialog.close();
	}

	insertBtn.addEventListener('click', applyAnchor);

	if (removeBtn) {
		removeBtn.addEventListener('click', () => {
			editor.chain().focus().removeAnchorId().run();
			dialog.close();
		});
	}

	dialog.querySelector('.ste-dialog-close').addEventListener('click', () => dialog.close());
	dialog.querySelector('.ste-dialog-btn--cancel').addEventListener('click', () => dialog.close());

	// Backdrop click closes
	dialog.addEventListener('click', (e) => {
		if (e.target === dialog) dialog.close();
	});

	// Enter key submits (Escape is handled natively)
	idInput.addEventListener('keydown', (e) => {
		if (e.key === 'Enter') {
			e.preventDefault();
			applyAnchor();
		}
	});

	dialog.addEventListener('close', () => {
		dialog.remove();
		editor.chain().focus().run();
	});

	document.body.appendChild(dialog);
	dialog.showModal();
	idInput.focus();
	idInput.select();
}

function getAnchorId(editor) {
	const { $from } = editor.state.selection;
	for (let depth = $from.depth; depth >= 1; depth--) {
		const node = $from.node(depth);
		if (node.attrs.id) return node.attrs.id;
	}
	return null;
}

function escapeAttr(str) {
	return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
