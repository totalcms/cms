/**
 * BlockAttributesDialog - Dialog for editing class and id on the current block node.
 * Uses the same ste-dialog pattern as AnchorDialog and LinkDialog.
 */

export function createBlockAttributesDialog(editor, blockClasses) {
	const blockInfo = getBlockAttributes(editor);
	if (!blockInfo) return;

	const overlay = document.createElement('div');
	overlay.className = 'ste-dialog-overlay';

	const dialog = document.createElement('div');
	dialog.className = 'ste-dialog';

	const datalistId = 'ste-block-class-suggestions';
	const hasClasses = Array.isArray(blockClasses) && blockClasses.length > 0;
	const datalistHtml = hasClasses
		? `<datalist id="${datalistId}">${blockClasses.map(c => `<option value="${escapeAttr(c)}">`).join('')}</datalist>`
		: '';
	const listAttr = hasClasses ? ` list="${datalistId}"` : '';

	dialog.innerHTML = `
		<div class="ste-dialog-header">
			<h3>Block Attributes &mdash; &lt;${escapeHtml(blockInfo.tagName)}&gt;</h3>
			<button type="button" class="ste-dialog-close" aria-label="Close">&times;</button>
		</div>
		<div class="ste-dialog-body">
			<div class="ste-link-field">
				<label class="ste-link-label" for="ste-block-class">Class</label>
				<input type="text" id="ste-block-class" class="ste-url-input" placeholder="my-class another-class" value="${escapeAttr(blockInfo.class || '')}" spellcheck="false"${listAttr}>
				${datalistHtml}
			</div>
			<div class="ste-link-field">
				<label class="ste-link-label" for="ste-block-id">ID</label>
				<input type="text" id="ste-block-id" class="ste-url-input" placeholder="my-id" value="${escapeAttr(blockInfo.id || '')}" spellcheck="false">
			</div>
		</div>
		<div class="ste-dialog-footer">
			${(blockInfo.class || blockInfo.id) ? '<button type="button" class="ste-dialog-btn ste-dialog-btn--remove ste-dialog-btn--icon" style="--btn-icon: var(--icon-ste-trash)" aria-label="Remove Attributes" title="Remove Attributes"></button>' : ''}
			<div class="ste-dialog-actions">
				<button type="button" class="ste-dialog-btn ste-dialog-btn--cancel">Cancel</button>
				<button type="button" class="ste-dialog-btn ste-dialog-btn--insert">Apply</button>
			</div>
		</div>
	`;

	overlay.appendChild(dialog);

	const classInput = dialog.querySelector('#ste-block-class');
	const idInput = dialog.querySelector('#ste-block-id');
	const applyBtn = dialog.querySelector('.ste-dialog-btn--insert');
	const removeBtn = dialog.querySelector('.ste-dialog-btn--remove');

	function close() {
		overlay.remove();
		editor.chain().focus().run();
	}

	function apply() {
		const cls = classInput.value.trim() || null;
		const id = idInput.value.trim().replace(/\s+/g, '-') || null;
		setBlockAttributes(editor, blockInfo.pos, { class: cls, id: id });
		overlay.remove();
	}

	applyBtn.addEventListener('click', apply);

	if (removeBtn) {
		removeBtn.addEventListener('click', () => {
			setBlockAttributes(editor, blockInfo.pos, { class: null, id: null });
			overlay.remove();
		});
	}

	dialog.querySelector('.ste-dialog-close').addEventListener('click', close);
	dialog.querySelector('.ste-dialog-btn--cancel').addEventListener('click', close);
	overlay.addEventListener('click', (e) => {
		if (e.target === overlay) close();
	});

	const handleKeydown = (e) => {
		if (e.key === 'Enter') {
			e.preventDefault();
			apply();
		}
		if (e.key === 'Escape') {
			e.preventDefault();
			close();
		}
	};

	classInput.addEventListener('keydown', handleKeydown);
	idInput.addEventListener('keydown', handleKeydown);

	document.body.appendChild(overlay);
	classInput.focus();
}

/**
 * Find the nearest block node at the cursor and return its attributes.
 */
function getBlockAttributes(editor) {
	const { $from } = editor.state.selection;
	const blockTypes = ['heading', 'paragraph', 'blockquote', 'listItem', 'bulletList', 'orderedList', 'codeBlock'];

	for (let depth = $from.depth; depth >= 1; depth--) {
		const node = $from.node(depth);

		// Skip paragraphs inside list items — target the li instead
		if (node.type.name === 'paragraph' && depth > 1 && $from.node(depth - 1).type.name === 'listItem') {
			continue;
		}

		if (blockTypes.includes(node.type.name)) {
			const tagMap = {
				heading: `h${node.attrs.level || 2}`,
				paragraph: 'p',
				blockquote: 'blockquote',
				listItem: 'li',
				bulletList: 'ul',
				orderedList: 'ol',
				codeBlock: 'pre',
			};
			return {
				pos: $from.before(depth),
				class: node.attrs.class || null,
				id: node.attrs.id || null,
				tagName: tagMap[node.type.name] || node.type.name,
			};
		}
	}
	return null;
}

/**
 * Set attributes on a block node at the given position.
 */
function setBlockAttributes(editor, pos, attrs) {
	const { tr } = editor.state;
	const node = tr.doc.nodeAt(pos);
	if (!node) return;

	tr.setNodeMarkup(pos, undefined, { ...node.attrs, ...attrs });
	editor.view.dispatch(tr);
}

function escapeAttr(str) {
	return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function escapeHtml(str) {
	return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
