/**
 * AnchorDialog - Creates and manages the anchor ID dialog.
 * Uses the same ste-dialog pattern as LinkDialog.
 */

export function createAnchorDialog(editor) {
	const currentId = getAnchorId(editor);
	const isEditing = !!currentId;

	const overlay = document.createElement('div');
	overlay.className = 'ste-dialog-overlay';

	const dialog = document.createElement('div');
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

	overlay.appendChild(dialog);

	const idInput = dialog.querySelector('#ste-anchor-id');
	const insertBtn = dialog.querySelector('.ste-dialog-btn--insert');
	const removeBtn = dialog.querySelector('.ste-dialog-btn--remove');

	function close() {
		overlay.remove();
		editor.chain().focus().run();
	}

	function applyAnchor() {
		const id = idInput.value.trim().replace(/\s+/g, '-');
		if (id) {
			editor.chain().focus().setAnchorId(id).run();
		} else {
			editor.chain().focus().removeAnchorId().run();
		}
		overlay.remove();
	}

	insertBtn.addEventListener('click', applyAnchor);

	if (removeBtn) {
		removeBtn.addEventListener('click', () => {
			editor.chain().focus().removeAnchorId().run();
			overlay.remove();
		});
	}

	dialog.querySelector('.ste-dialog-close').addEventListener('click', close);
	dialog.querySelector('.ste-dialog-btn--cancel').addEventListener('click', close);
	overlay.addEventListener('click', (e) => {
		if (e.target === overlay) close();
	});

	idInput.addEventListener('keydown', (e) => {
		if (e.key === 'Enter') {
			e.preventDefault();
			applyAnchor();
		}
		if (e.key === 'Escape') {
			e.preventDefault();
			close();
		}
	});

	document.body.appendChild(overlay);
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
