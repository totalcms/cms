/**
 * BlockAttributesDialog - Dialog for editing class, id, and data-* attributes
 * on the current block node.
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
			<h3>Element Attributes &mdash; &lt;${escapeHtml(blockInfo.tagName)}&gt;</h3>
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
			<div class="ste-block-data-section">
				<label class="ste-link-label">Data Attributes</label>
				<div class="ste-block-data-rows"></div>
				<button type="button" class="ste-dialog-btn ste-dialog-btn--add-data" title="Add Data Attribute" aria-label="Add Data Attribute">&#xFF0B;</button>
			</div>
		</div>
		<div class="ste-dialog-footer">
			${hasAttributes(blockInfo) ? '<button type="button" class="ste-dialog-btn ste-dialog-btn--remove ste-dialog-btn--icon" style="--btn-icon: var(--icon-ste-trash)" aria-label="Remove All Attributes" title="Remove All Attributes"></button>' : ''}
			<div class="ste-dialog-actions">
				<button type="button" class="ste-dialog-btn ste-dialog-btn--cancel">Cancel</button>
				<button type="button" class="ste-dialog-btn ste-dialog-btn--insert">Apply</button>
			</div>
		</div>
	`;

	overlay.appendChild(dialog);

	const classInput = dialog.querySelector('#ste-block-class');
	const idInput = dialog.querySelector('#ste-block-id');
	const dataRowsContainer = dialog.querySelector('.ste-block-data-rows');
	const addDataBtn = dialog.querySelector('.ste-dialog-btn--add-data');
	const applyBtn = dialog.querySelector('.ste-dialog-btn--insert');
	const removeBtn = dialog.querySelector('.ste-dialog-btn--remove');

	// Populate existing data-* attributes
	if (blockInfo.dataAttrs) {
		for (const [key, value] of Object.entries(blockInfo.dataAttrs)) {
			addDataRow(dataRowsContainer, key, value);
		}
	}

	addDataBtn.addEventListener('click', (e) => {
		e.preventDefault();
		addDataRow(dataRowsContainer, '', '');
	});

	function close() {
		overlay.remove();
		editor.chain().focus().run();
	}

	function apply() {
		const cls = classInput.value.trim() || null;
		const id = idInput.value.trim().replace(/\s+/g, '-') || null;
		const dataAttrs = collectDataAttrs(dataRowsContainer);
		setBlockAttributes(editor, blockInfo.pos, {
			class: cls,
			id: id,
			dataAttrs: dataAttrs,
		});
		overlay.remove();
	}

	applyBtn.addEventListener('click', apply);

	if (removeBtn) {
		removeBtn.addEventListener('click', () => {
			setBlockAttributes(editor, blockInfo.pos, { class: null, id: null, dataAttrs: null });
			overlay.remove();
		});
	}

	dialog.querySelector('.ste-dialog-close').addEventListener('click', close);
	dialog.querySelector('.ste-dialog-btn--cancel').addEventListener('click', close);
	overlay.addEventListener('click', (e) => {
		if (e.target === overlay) close();
	});

	dialog.addEventListener('keydown', (e) => {
		if (e.key === 'Escape') {
			e.preventDefault();
			close();
		}
	});

	document.body.appendChild(overlay);
	classInput.focus();
}

/**
 * Add a data attribute key/value row to the container.
 */
function addDataRow(container, key, value) {
	const row = document.createElement('div');
	row.className = 'ste-block-data-row';

	const keyInput = document.createElement('input');
	keyInput.type = 'text';
	keyInput.className = 'ste-url-input ste-block-data-key';
	keyInput.placeholder = 'data-name';
	keyInput.value = key;
	keyInput.spellcheck = false;

	const valueInput = document.createElement('input');
	valueInput.type = 'text';
	valueInput.className = 'ste-url-input ste-block-data-value';
	valueInput.placeholder = 'value';
	valueInput.value = value;
	valueInput.spellcheck = false;

	const removeBtn = document.createElement('button');
	removeBtn.type = 'button';
	removeBtn.className = 'ste-block-data-remove';
	removeBtn.textContent = '\u00d7';
	removeBtn.title = 'Remove';
	removeBtn.addEventListener('click', () => row.remove());

	// Auto-prefix data- if user forgets
	keyInput.addEventListener('blur', () => {
		const val = keyInput.value.trim();
		if (val && !val.startsWith('data-')) {
			keyInput.value = 'data-' + val;
		}
	});

	row.appendChild(keyInput);
	row.appendChild(valueInput);
	row.appendChild(removeBtn);
	container.appendChild(row);

	keyInput.focus();
}

/**
 * Collect all data attribute rows into a JSON string.
 */
function collectDataAttrs(container) {
	const rows = container.querySelectorAll('.ste-block-data-row');
	const data = {};

	for (const row of rows) {
		const key = row.querySelector('.ste-block-data-key').value.trim();
		const value = row.querySelector('.ste-block-data-value').value.trim();
		if (key && key.startsWith('data-')) {
			data[key] = value;
		}
	}

	return Object.keys(data).length > 0 ? JSON.stringify(data) : null;
}

/**
 * Check if a block has any attributes set.
 */
function hasAttributes(blockInfo) {
	return !!(blockInfo.class || blockInfo.id || (blockInfo.dataAttrs && Object.keys(blockInfo.dataAttrs).length > 0));
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

			let dataAttrs = null;
			if (node.attrs.dataAttrs) {
				try {
					dataAttrs = JSON.parse(node.attrs.dataAttrs);
				} catch {
					dataAttrs = null;
				}
			}

			return {
				pos: $from.before(depth),
				class: node.attrs.class || null,
				id: node.attrs.id || null,
				dataAttrs: dataAttrs,
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
