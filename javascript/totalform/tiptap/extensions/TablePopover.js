/**
 * TablePopover - Floating popover for table management.
 * Shows add/delete row/column controls and header toggle when cursor is inside a table.
 */

import { Extension } from '@tiptap/core';
import { Plugin, PluginKey } from '@tiptap/pm/state';

const TABLE_POPOVER_KEY = new PluginKey('tablePopover');

function createTablePopoverPlugin(editor) {
	let popoverEl = null;

	function buildPopover() {
		const el = document.createElement('div');
		el.className = 'ste-table-popover';

		el.innerHTML = `
			<div class="ste-table-popover__row">
				<span class="ste-table-popover__label">Row</span>
				<button type="button" class="ste-table-popover__icon-btn" data-action="addRowBefore" title="Add row above" style="--btn-icon: var(--icon-ste-add-top)"></button>
				<button type="button" class="ste-table-popover__icon-btn" data-action="addRowAfter" title="Add row below" style="--btn-icon: var(--icon-ste-add-bottom)"></button>
				<button type="button" class="ste-table-popover__icon-btn ste-table-popover__icon-btn--danger" data-action="deleteRow" title="Delete row" style="--btn-icon: var(--icon-ste-trash)"></button>
			</div>
			<div class="ste-table-popover__row">
				<span class="ste-table-popover__label">Col</span>
				<button type="button" class="ste-table-popover__icon-btn" data-action="addColBefore" title="Add column left" style="--btn-icon: var(--icon-ste-add-left)"></button>
				<button type="button" class="ste-table-popover__icon-btn" data-action="addColAfter" title="Add column right" style="--btn-icon: var(--icon-ste-add-right)"></button>
				<button type="button" class="ste-table-popover__icon-btn ste-table-popover__icon-btn--danger" data-action="deleteCol" title="Delete column" style="--btn-icon: var(--icon-ste-trash)"></button>
			</div>
			<div class="ste-table-popover__sep-h"></div>
			<div class="ste-table-popover__row">
				<span class="ste-table-popover__label">Header</span>
				<button type="button" class="ste-table-popover__icon-btn" data-action="toggleHeaderRow" title="Toggle header row" style="--btn-icon: var(--icon-ste-header-row)"></button>
				<button type="button" class="ste-table-popover__icon-btn" data-action="toggleHeaderCol" title="Toggle header column" style="--btn-icon: var(--icon-ste-header-col)"></button>
			</div>
		`;

		// Prevent mousedown from stealing focus from the editor
		el.addEventListener('mousedown', (e) => {
			e.preventDefault();
		});

		// Button actions
		el.addEventListener('click', (e) => {
			const btn = e.target.closest('[data-action]');
			if (!btn) return;

			const action = btn.dataset.action;
			switch (action) {
				case 'addRowBefore':
					editor.chain().focus().addRowBefore().run();
					break;
				case 'addRowAfter':
					editor.chain().focus().addRowAfter().run();
					break;
				case 'deleteRow':
					editor.chain().focus().deleteRow().run();
					break;
				case 'addColBefore':
					editor.chain().focus().addColumnBefore().run();
					break;
				case 'addColAfter':
					editor.chain().focus().addColumnAfter().run();
					break;
				case 'deleteCol':
					editor.chain().focus().deleteColumn().run();
					break;
				case 'toggleHeaderRow':
					editor.chain().focus().toggleHeaderRow().run();
					break;
				case 'toggleHeaderCol':
					editor.chain().focus().toggleHeaderColumn().run();
					break;
			}
		});

		return el;
	}

	function show(view) {
		if (!popoverEl) {
			popoverEl = buildPopover();
		}

		const wrapper = view.dom.closest('.ste-editor-wrapper');
		if (!wrapper) return;

		if (!popoverEl.parentElement) {
			wrapper.appendChild(popoverEl);
		}

		updatePosition(view);
	}

	function hide() {
		if (popoverEl && popoverEl.parentElement) {
			popoverEl.remove();
		}
	}

	function updatePosition(view) {
		if (!popoverEl) return;

		const wrapper = view.dom.closest('.ste-editor-wrapper');
		if (!wrapper) return;

		// Find the table DOM element
		const { state } = view;
		const { $from } = state.selection;
		let tableDepth = null;

		for (let d = $from.depth; d >= 0; d--) {
			if ($from.node(d).type.name === 'table') {
				tableDepth = d;
				break;
			}
		}

		if (tableDepth === null) {
			hide();
			return;
		}

		const tableStart = $from.start(tableDepth) - 1;
		const tableNode = view.nodeDOM(tableStart);
		if (!tableNode) return;

		const tableRect = tableNode.getBoundingClientRect();
		const wrapperRect = wrapper.getBoundingClientRect();

		// Position below the table, centered
		const top = tableRect.bottom - wrapperRect.top + wrapper.scrollTop + 4;
		let left = (tableRect.left + tableRect.width / 2) - wrapperRect.left;

		popoverEl.style.top = `${top}px`;
		popoverEl.style.left = `${left}px`;
		popoverEl.style.transform = 'translateX(-50%)';

		// Update header toggle state
		updateHeaderState(view);
	}

	function updateHeaderState(view) {
		if (!popoverEl) return;
		const rowBtn = popoverEl.querySelector('[data-action="toggleHeaderRow"]');
		const colBtn = popoverEl.querySelector('[data-action="toggleHeaderCol"]');

		const { state } = view;
		const { $from } = state.selection;

		for (let d = $from.depth; d >= 0; d--) {
			if ($from.node(d).type.name === 'table') {
				const table = $from.node(d);
				const firstRow = table.child(0);

				// Header row: first row's first cell is a tableHeader
				if (rowBtn) {
					rowBtn.classList.toggle('is-active', firstRow.child(0).type.name === 'tableHeader');
				}

				// Header col: second row's first cell is a tableHeader (if exists)
				if (colBtn && table.childCount > 1) {
					colBtn.classList.toggle('is-active', table.child(1).child(0).type.name === 'tableHeader');
				}

				break;
			}
		}
	}

	return new Plugin({
		key: TABLE_POPOVER_KEY,
		view() {
			return {
				update(view) {
					const { state } = view;
					const isInTable = editor.isActive('table');

					if (isInTable) {
						show(view);
					} else {
						hide();
					}
				},
				destroy() {
					hide();
					popoverEl = null;
				},
			};
		},
	});
}

const TablePopover = Extension.create({
	name: 'tablePopover',

	addProseMirrorPlugins() {
		return [createTablePopoverPlugin(this.editor)];
	},
});

export default TablePopover;
export { createTablePopoverPlugin };
