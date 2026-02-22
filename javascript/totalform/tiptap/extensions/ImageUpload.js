/**
 * ImageUpload Extension
 * Custom image node with upload dialog, drag-drop, paste support.
 * Uses DropletTestSet for client-side rule validation.
 * Includes Image Manager tab for browsing/deleting uploaded images.
 */

import Image from '@tiptap/extension-image';
import { mergeAttributes } from '@tiptap/core';
import { Plugin } from '@tiptap/pm/state';
import { getUploadUrl, uploadFile, uploadFileWithProgress, validateFile } from '../upload.js';
import { createImagePopoverPlugin } from './ImagePopover.js';

/**
 * Creates an image upload dialog element with Upload and Images tabs
 */
function createImageDialog(editor, uploadConfig) {
	const overlay = document.createElement('div');
	overlay.className = 'ste-dialog-overlay';

	const dialog = document.createElement('div');
	dialog.className = 'ste-dialog ste-dialog--image-manager';

	dialog.innerHTML = `
		<div class="ste-dialog-header">
			<h3>Insert Image</h3>
			<button type="button" class="ste-dialog-close" aria-label="Close">&times;</button>
		</div>
		<div class="ste-dialog-tabs">
			<button type="button" class="ste-dialog-tab is-active" data-tab="upload">Upload</button>
			<button type="button" class="ste-dialog-tab" data-tab="images">Images</button>
		</div>
		<div class="ste-dialog-body">
			<div class="ste-dialog-panel is-active" data-panel="upload">
				<div class="ste-upload-zone">
					<input type="file" accept="image/*" class="ste-upload-input" />
					<p>Click or drag an image here to upload</p>
				</div>
				<div class="ste-upload-progress" style="display:none;">
					<div class="ste-upload-progress-bar"></div>
					<span class="ste-upload-progress-text">Uploading...</span>
				</div>
				<div class="ste-upload-error" style="display:none;"></div>
			</div>
			<div class="ste-dialog-panel" data-panel="images">
				<div class="ste-image-loading">Loading images...</div>
				<div class="ste-image-grid" style="display:none;"></div>
				<div class="ste-image-empty" style="display:none;">No images uploaded yet</div>
			</div>
		</div>
		<div class="ste-dialog-footer">
			<input type="text" class="ste-alt-input" placeholder="Alt text (optional)" />
			<div class="ste-dialog-actions">
				<button type="button" class="ste-dialog-btn ste-dialog-btn--cancel">Cancel</button>
				<button type="button" class="ste-dialog-btn ste-dialog-btn--insert">Insert</button>
			</div>
		</div>
	`;

	overlay.appendChild(dialog);

	// Tab switching
	let imagesLoaded = false;
	dialog.querySelectorAll('.ste-dialog-tab').forEach(tab => {
		tab.addEventListener('click', () => {
			dialog.querySelectorAll('.ste-dialog-tab').forEach(t => t.classList.remove('is-active'));
			dialog.querySelectorAll('.ste-dialog-panel').forEach(p => p.classList.remove('is-active'));
			tab.classList.add('is-active');
			dialog.querySelector(`[data-panel="${tab.dataset.tab}"]`).classList.add('is-active');

			if (tab.dataset.tab === 'images') {
				loadImages();
			}
		});
	});

	const fileInput = dialog.querySelector('.ste-upload-input');
	const uploadZone = dialog.querySelector('.ste-upload-zone');
	const progress = dialog.querySelector('.ste-upload-progress');
	const progressBar = dialog.querySelector('.ste-upload-progress-bar');
	const progressText = dialog.querySelector('.ste-upload-progress-text');
	const errorEl = dialog.querySelector('.ste-upload-error');
	const imageGrid = dialog.querySelector('.ste-image-grid');
	const imageLoading = dialog.querySelector('.ste-image-loading');
	const imageEmpty = dialog.querySelector('.ste-image-empty');
	const rules = uploadConfig.rules || {};
	let uploadedUrl = null;
	let selectedImageUrl = null;

	function showError(messages) {
		const list = Array.isArray(messages) ? messages : [messages];
		errorEl.textContent = list.join('. ');
		errorEl.style.display = '';
	}

	function clearError() {
		errorEl.textContent = '';
		errorEl.style.display = 'none';
	}

	// Drag and drop on the zone
	uploadZone.addEventListener('dragover', (e) => {
		e.preventDefault();
		uploadZone.classList.add('is-dragover');
	});
	uploadZone.addEventListener('dragleave', () => {
		uploadZone.classList.remove('is-dragover');
	});
	uploadZone.addEventListener('drop', (e) => {
		e.preventDefault();
		uploadZone.classList.remove('is-dragover');
		const file = e.dataTransfer.files[0];
		if (file && file.type.startsWith('image/')) {
			handleUpload(file);
		}
	});

	fileInput.addEventListener('change', () => {
		if (fileInput.files[0]) handleUpload(fileInput.files[0]);
	});

	async function handleUpload(file) {
		clearError();

		// Validate against rules before uploading
		const result = await validateFile(file, rules);
		if (!result.valid) {
			showError(result.errors);
			return;
		}

		const url = getUploadUrl(uploadConfig);
		progress.style.display = '';

		uploadFileWithProgress(file, url, uploadConfig, {
			onProgress(percent) {
				progressBar.style.width = `${percent}%`;
				progressText.textContent = `Uploading... ${percent}%`;
			},
			onSuccess(data) {
				progress.style.display = 'none';
				uploadedUrl = data.link;
				const preview = URL.createObjectURL(file);
				uploadZone.innerHTML = `<img class="ste-upload-preview" src="${preview}" alt="${file.name}" />`;
				// Mark images as needing refresh
				imagesLoaded = false;
			},
			onError(msg) {
				progress.style.display = 'none';
				showError(msg);
			},
		});
	}

	// Image Manager: load images from the API
	async function loadImages(force = false) {
		if (imagesLoaded && !force) return;

		imageLoading.style.display = '';
		imageGrid.style.display = 'none';
		imageEmpty.style.display = 'none';
		selectedImageUrl = null;

		const listUrl = getUploadUrl(uploadConfig);
		if (!listUrl) return;

		// Append type filter and preset as query params
		const params = new URLSearchParams();
		params.set('type', 'image');
		if (uploadConfig.imagePreset) {
			params.set('preset', uploadConfig.imagePreset);
		}
		const fetchUrl = `${listUrl}?${params}`;

		try {
			const resp = await fetch(fetchUrl, { method: 'GET' });
			if (!resp.ok) throw new Error(`Failed to load images: ${resp.status}`);
			const data = await resp.json();
			const files = data.files || [];

			imageLoading.style.display = 'none';

			if (files.length === 0) {
				imageEmpty.style.display = '';
				imageGrid.style.display = 'none';
			} else {
				imageEmpty.style.display = 'none';
				imageGrid.style.display = '';
				renderImageGrid(files);
			}

			imagesLoaded = true;
		} catch (err) {
			imageLoading.style.display = 'none';
			imageEmpty.textContent = 'Failed to load images';
			imageEmpty.style.display = '';
			console.error('Image manager load error:', err);
		}
	}

	function renderImageGrid(files) {
		imageGrid.innerHTML = '';
		selectedImageUrl = null;

		files.forEach(file => {
			const card = document.createElement('div');
			card.className = 'ste-image-card';
			card.dataset.url = file.url;
			card.dataset.name = file.name;

			card.innerHTML = `
				<div class="ste-image-card__thumb-wrap">
					<img class="ste-image-card__thumb" src="${file.thumbnail}" alt="${file.name}" loading="lazy" />
					<button type="button" class="ste-image-card__delete" aria-label="Delete image" title="Delete">✕</button>
				</div>
				<span class="ste-image-card__name" title="${file.name}">${file.name}</span>
			`;

			// Click card to select
			card.addEventListener('click', (e) => {
				if (e.target.closest('.ste-image-card__delete')) return;
				imageGrid.querySelectorAll('.ste-image-card').forEach(c => c.classList.remove('is-selected'));
				card.classList.add('is-selected');
				selectedImageUrl = file.url;
			});

			// Double-click for quick insert
			card.addEventListener('dblclick', (e) => {
				if (e.target.closest('.ste-image-card__delete')) return;
				selectedImageUrl = file.url;
				insertImage();
			});

			// Delete button
			card.querySelector('.ste-image-card__delete').addEventListener('click', (e) => {
				e.stopPropagation();
				handleDeleteImage(file.name, card);
			});

			imageGrid.appendChild(card);
		});
	}

	function handleDeleteImage(filename, card) {
		// Check if this image is currently used in the editor content
		const editorHtml = editor.getHTML();
		const inUse = editorHtml.includes(filename);

		const message = inUse
			? `This image is currently used in the content. Deleting it will break the reference. Continue?`
			: `Delete this image?`;

		if (!confirm(message)) return;

		const listUrl = getUploadUrl(uploadConfig);
		const deleteUrl = `${listUrl}/${encodeURIComponent(filename)}`;

		fetch(deleteUrl, { method: 'DELETE' })
			.then(resp => {
				if (!resp.ok) throw new Error(`Delete failed: ${resp.status}`);
				card.remove();
				// Show empty message if no cards left
				if (imageGrid.children.length === 0) {
					imageGrid.style.display = 'none';
					imageEmpty.textContent = 'No images uploaded yet';
					imageEmpty.style.display = '';
				}
				if (selectedImageUrl && card.dataset.url === selectedImageUrl) {
					selectedImageUrl = null;
				}
			})
			.catch(err => {
				console.error('Delete image error:', err);
				alert('Failed to delete image');
			});
	}

	function insertImage() {
		const alt = dialog.querySelector('.ste-alt-input').value;
		const activePanel = dialog.querySelector('.ste-dialog-panel.is-active')?.dataset.panel;

		if (activePanel === 'upload' && uploadedUrl) {
			editor.chain().focus().setImage({ src: uploadedUrl, alt: alt || undefined }).run();
			close();
		} else if (activePanel === 'images' && selectedImageUrl) {
			editor.chain().focus().setImage({ src: selectedImageUrl, alt: alt || undefined }).run();
			close();
		}
	}

	// Close handlers
	const close = () => overlay.remove();
	dialog.querySelector('.ste-dialog-close').addEventListener('click', close);
	dialog.querySelector('.ste-dialog-btn--cancel').addEventListener('click', close);
	overlay.addEventListener('click', (e) => {
		if (e.target === overlay) close();
	});

	// Insert handler
	dialog.querySelector('.ste-dialog-btn--insert').addEventListener('click', insertImage);

	return overlay;
}

/**
 * Extended Image extension with upload support
 */
const ImageUpload = Image.extend({
	name: 'image',

	addAttributes() {
		return {
			...this.parent?.(),
			'data-preset': { default: null },
			width: { default: null },
			'data-float': {
				default: null,
				parseHTML: el => el.getAttribute('data-float'),
				renderHTML: attrs => attrs['data-float'] ? { 'data-float': attrs['data-float'] } : {},
			},
			'data-size': {
				default: null,
				parseHTML: el => el.getAttribute('data-size'),
				renderHTML: attrs => attrs['data-size'] ? { 'data-size': attrs['data-size'] } : {},
			},
		};
	},

	renderHTML({ HTMLAttributes }) {
		const classes = [];
		if (HTMLAttributes['data-float']) classes.push(`ste-img--float-${HTMLAttributes['data-float']}`);
		if (HTMLAttributes['data-size']) classes.push(`ste-img--${HTMLAttributes['data-size']}`);

		const attrs = { ...HTMLAttributes };
		attrs.class = classes.length ? classes.join(' ') : null;

		return ['img', mergeAttributes(this.options.HTMLAttributes, attrs)];
	},

	addCommands() {
		return {
			...this.parent?.(),
			openImageDialog: () => ({ editor }) => {
				const uploadConfig = editor.options.imageUploadConfig || {};
				const dialog = createImageDialog(editor, uploadConfig);
				document.body.appendChild(dialog);
				return true;
			},
		};
	},

	addProseMirrorPlugins() {
		const uploadConfig = this.editor.options.imageUploadConfig || {};
		const rules = uploadConfig.rules || {};

		return [
			...(this.parent?.() || []),
			new Plugin({
				props: {
					handleDrop: (view, event) => {
						if (!event.dataTransfer?.files?.length) return false;

						const file = event.dataTransfer.files[0];
						if (!file.type.startsWith('image/')) return false;

						event.preventDefault();
						const url = getUploadUrl(uploadConfig);

						validateFile(file, rules).then(result => {
							if (!result.valid) {
								console.warn('Image drop rejected:', result.errors);
								return;
							}
							return uploadFile(file, url, uploadConfig);
						}).then(data => {
							if (!data) return;
							const { schema } = view.state;
							const node = schema.nodes.image.create({ src: data.link });
							const pos = view.posAtCoords({ left: event.clientX, top: event.clientY });
							if (pos) {
								const tr = view.state.tr.insert(pos.pos, node);
								view.dispatch(tr);
							}
						}).catch(err => console.error('Image drop upload failed:', err));

						return true;
					},
					handlePaste: (view, event) => {
						const items = event.clipboardData?.items;
						if (!items) return false;

						for (const item of items) {
							if (item.type.startsWith('image/')) {
								event.preventDefault();
								const file = item.getAsFile();
								if (!file) continue;

								const url = getUploadUrl(uploadConfig);

								validateFile(file, rules).then(result => {
									if (!result.valid) {
										console.warn('Image paste rejected:', result.errors);
										return;
									}
									return uploadFile(file, url, uploadConfig);
								}).then(data => {
									if (!data) return;
									const { schema } = view.state;
									const node = schema.nodes.image.create({ src: data.link });
									const tr = view.state.tr.replaceSelectionWith(node);
									view.dispatch(tr);
								}).catch(err => console.error('Image paste upload failed:', err));

								return true;
							}
						}
						return false;
					},
				},
			}),
			createImagePopoverPlugin(this.editor),
		];
	},
});

export default ImageUpload;
export { createImageDialog };
