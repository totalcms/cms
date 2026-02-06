/**
 * ImagePopover
 * Floating popover that appears when an image or figure is selected.
 * Provides float, size, alt text, and caption controls.
 */

import { Plugin } from '@tiptap/pm/state';

/**
 * Creates the popover DOM element.
 */
function createPopoverElement() {
	const el = document.createElement('div');
	el.className = 'ste-image-popover';
	el.innerHTML = `
		<div class="ste-image-popover__row">
			<div class="ste-image-popover__group">
				<span class="ste-image-popover__label">Float</span>
				<button type="button" class="ste-image-popover__btn ste-image-popover__btn--icon" data-action="float" data-value="left" title="Float left" style="--btn-icon: var(--icon-ste-float-left)"></button>
				<button type="button" class="ste-image-popover__btn ste-image-popover__btn--icon" data-action="float" data-value="none" title="No float (block)" style="--btn-icon: var(--icon-ste-float-none)"></button>
				<button type="button" class="ste-image-popover__btn ste-image-popover__btn--icon" data-action="float" data-value="right" title="Float right" style="--btn-icon: var(--icon-ste-float-right)"></button>
			</div>
			<div class="ste-image-popover__sep"></div>
			<div class="ste-image-popover__group">
				<span class="ste-image-popover__label">Size</span>
				<button type="button" class="ste-image-popover__btn ste-image-popover__btn--text" data-action="size" data-value="small" title="25% width">S</button>
				<button type="button" class="ste-image-popover__btn ste-image-popover__btn--text" data-action="size" data-value="medium" title="50% width">M</button>
				<button type="button" class="ste-image-popover__btn ste-image-popover__btn--text" data-action="size" data-value="full" title="100% width">Full</button>
			</div>
		</div>
		<div class="ste-image-popover__fields">
			<div class="ste-image-popover__field">
				<label class="ste-image-popover__field-label">Alt text</label>
				<input type="text" class="ste-image-popover__input" data-field="alt" placeholder="Describe this image" />
			</div>
			<div class="ste-image-popover__field ste-image-popover__field--caption">
				<label class="ste-image-popover__checkbox-label">
					<input type="checkbox" data-field="caption-toggle" />
					Caption
				</label>
				<input type="text" class="ste-image-popover__input" data-field="caption" placeholder="Image caption" disabled />
			</div>
		</div>
	`;
	return el;
}

/**
 * Manages the image popover lifecycle.
 */
class ImagePopoverManager {
	constructor(editor) {
		this.editor = editor;
		this.popover = createPopoverElement();
		this.popover.style.display = 'none';
		this.currentNodePos = null;
		this.currentNodeType = null;
		this._skipNextUpdate = false;

		this.bindEvents();
	}

	bindEvents() {
		// Float buttons
		this.popover.querySelectorAll('[data-action="float"]').forEach(btn => {
			btn.addEventListener('click', () => {
				const value = btn.dataset.value === 'none' ? null : btn.dataset.value;
				this.applyAttrs({ 'data-float': value, float: value });
			});
		});

		// Size buttons
		this.popover.querySelectorAll('[data-action="size"]').forEach(btn => {
			btn.addEventListener('click', () => {
				const value = btn.dataset.value === 'full' ? null : btn.dataset.value;
				this.applyAttrs({ 'data-size': value, size: value });
			});
		});

		// Alt text
		const altInput = this.popover.querySelector('[data-field="alt"]');
		altInput.addEventListener('input', () => {
			this._skipNextUpdate = true;
			this.applyAttrs({ alt: altInput.value || null });
		});

		// Caption toggle
		const captionToggle = this.popover.querySelector('[data-field="caption-toggle"]');
		const captionInput = this.popover.querySelector('[data-field="caption"]');

		captionToggle.addEventListener('change', () => {
			if (captionToggle.checked) {
				captionInput.disabled = false;
				this.convertToFigure(captionInput.value || '');
			} else {
				captionInput.disabled = true;
				captionInput.value = '';
				this.convertToImage();
			}
		});

		captionInput.addEventListener('input', () => {
			this._skipNextUpdate = true;
			this.applyAttrs({ caption: captionInput.value || null });
		});

		// Prevent clicks inside popover from deselecting
		this.popover.addEventListener('mousedown', (e) => {
			e.preventDefault();
		});
	}

	applyAttrs(attrs) {
		const pos = this.currentNodePos;
		if (pos === null) return;

		const { tr, doc } = this.editor.state;
		const node = doc.nodeAt(pos);
		if (!node) return;

		const newAttrs = { ...node.attrs, ...attrs };
		this.editor.view.dispatch(tr.setNodeMarkup(pos, undefined, newAttrs));
		this.updateActiveStates(newAttrs);
	}

	convertToFigure(caption) {
		const pos = this.currentNodePos;
		if (pos === null) return;

		const { state } = this.editor;
		const node = state.doc.nodeAt(pos);
		if (!node) return;

		// Already a figure, just update caption
		if (node.type.name === 'figure') {
			this.applyAttrs({ caption: caption || null });
			return;
		}

		// Convert image -> figure
		const figureType = state.schema.nodes.figure;
		if (!figureType) return;

		const figureNode = figureType.create({
			src: node.attrs.src,
			alt: node.attrs.alt,
			title: node.attrs.title,
			'data-float': node.attrs['data-float'],
			float: node.attrs['data-float'],
			'data-size': node.attrs['data-size'],
			size: node.attrs['data-size'],
			caption: caption || null,
		});

		const { tr } = this.editor.state;
		tr.replaceWith(pos, pos + node.nodeSize, figureNode);
		this.editor.view.dispatch(tr);

		// Update tracking
		this.currentNodeType = 'figure';
	}

	convertToImage() {
		const pos = this.currentNodePos;
		if (pos === null) return;

		const { state } = this.editor;
		const node = state.doc.nodeAt(pos);
		if (!node || node.type.name !== 'figure') return;

		const imageType = state.schema.nodes.image;
		if (!imageType) return;

		const imageNode = imageType.create({
			src: node.attrs.src,
			alt: node.attrs.alt,
			title: node.attrs.title,
			'data-float': node.attrs['data-float'] || node.attrs.float,
			'data-size': node.attrs['data-size'] || node.attrs.size,
		});

		const { tr } = this.editor.state;
		tr.replaceWith(pos, pos + node.nodeSize, imageNode);
		this.editor.view.dispatch(tr);

		this.currentNodeType = 'image';
	}

	show(node, pos, dom) {
		this.currentNodePos = pos;
		this.currentNodeType = node.type.name;
		this.popover.style.display = '';

		// Position the popover relative to the editor wrapper
		this.positionPopover(dom);

		// Populate controls
		this.populateFromNode(node);
	}

	hide() {
		this.popover.style.display = 'none';
		this.currentNodePos = null;
		this.currentNodeType = null;
	}

	positionPopover(dom) {
		const editorWrapper = this.editor.view.dom.closest('.ste-editor-wrapper');
		if (!editorWrapper) return;

		// Ensure popover is in the editor wrapper
		if (this.popover.parentElement !== editorWrapper) {
			editorWrapper.appendChild(this.popover);
		}

		const domRect = dom.getBoundingClientRect();
		const wrapperRect = editorWrapper.getBoundingClientRect();
		const popoverWidth = this.popover.offsetWidth;
		const popoverHeight = this.popover.offsetHeight;
		const wrapperWidth = editorWrapper.clientWidth;

		// Vertical: prefer above the image, fall back to below
		let top = domRect.top - wrapperRect.top + editorWrapper.scrollTop - popoverHeight - 8;
		if (top < 0) {
			top = domRect.bottom - wrapperRect.top + editorWrapper.scrollTop + 8;
		}

		// Horizontal: center on the image, then clamp to wrapper bounds
		let left = domRect.left - wrapperRect.left + (domRect.width / 2) - (popoverWidth / 2);
		left = Math.max(0, Math.min(left, wrapperWidth - popoverWidth));

		this.popover.style.top = `${top}px`;
		this.popover.style.left = `${left}px`;
		this.popover.style.transform = 'none';
	}

	populateFromNode(node) {
		const attrs = node.attrs;
		const floatVal = attrs['data-float'] || attrs.float || null;
		const sizeVal = attrs['data-size'] || attrs.size || null;
		const alt = attrs.alt || '';
		const caption = attrs.caption || '';
		const hasCaption = node.type.name === 'figure';

		// Alt input
		const altInput = this.popover.querySelector('[data-field="alt"]');
		if (!this._skipNextUpdate) {
			altInput.value = alt;
		}

		// Caption
		const captionToggle = this.popover.querySelector('[data-field="caption-toggle"]');
		const captionInput = this.popover.querySelector('[data-field="caption"]');
		captionToggle.checked = hasCaption;
		captionInput.disabled = !hasCaption;
		if (!this._skipNextUpdate) {
			captionInput.value = caption;
		}

		this._skipNextUpdate = false;

		this.updateActiveStates({ 'data-float': floatVal, 'data-size': sizeVal, float: floatVal, size: sizeVal });
	}

	updateActiveStates(attrs) {
		const floatVal = attrs['data-float'] || attrs.float || null;
		const sizeVal = attrs['data-size'] || attrs.size || null;

		// Float buttons
		this.popover.querySelectorAll('[data-action="float"]').forEach(btn => {
			const match = floatVal
				? btn.dataset.value === floatVal
				: btn.dataset.value === 'none';
			btn.classList.toggle('is-active', match);
		});

		// Size buttons
		this.popover.querySelectorAll('[data-action="size"]').forEach(btn => {
			const match = sizeVal
				? btn.dataset.value === sizeVal
				: btn.dataset.value === 'full';
			btn.classList.toggle('is-active', match);
		});
	}

	destroy() {
		this.popover.remove();
	}
}

/**
 * Creates a ProseMirror plugin that manages the image popover.
 */
function createImagePopoverPlugin(editor) {
	let manager = null;

	return new Plugin({
		view(editorView) {
			manager = new ImagePopoverManager(editor);

			return {
				update(view, prevState) {
					const { state } = view;
					const { selection } = state;
					const { $from } = selection;

					// Check if we're on an image or figure node
					let nodePos = null;
					let node = null;
					let dom = null;

					// NodeSelection — user clicked directly on the node
					if (selection.node) {
						const sel = selection;
						if (sel.node.type.name === 'image' || sel.node.type.name === 'figure') {
							node = sel.node;
							nodePos = sel.from;
							dom = view.nodeDOM(nodePos);
						}
					}

					if (node && dom && nodePos !== null) {
						manager.show(node, nodePos, dom);
					} else {
						manager.hide();
					}
				},
				destroy() {
					manager?.destroy();
				},
			};
		},
	});
}

export { createImagePopoverPlugin };
