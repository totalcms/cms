/**
 * ImageUpload Extension
 * Custom image node with upload dialog, drag-drop, paste support.
 * Uses DropletTestSet for client-side rule validation.
 */

import Image from '@tiptap/extension-image';
import { Plugin } from '@tiptap/pm/state';
import { getUploadUrl, uploadFile, uploadFileWithProgress, validateFile } from '../upload.js';

/**
 * Creates an image upload dialog element
 */
function createImageDialog(editor, uploadConfig) {
	const overlay = document.createElement('div');
	overlay.className = 'ste-dialog-overlay';

	const dialog = document.createElement('div');
	dialog.className = 'ste-dialog';

	dialog.innerHTML = `
		<div class="ste-dialog-header">
			<h3>Insert Image</h3>
			<button type="button" class="ste-dialog-close" aria-label="Close">&times;</button>
		</div>
		<div class="ste-dialog-body">
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
		<div class="ste-dialog-footer">
			<input type="text" class="ste-alt-input" placeholder="Alt text (optional)" />
			<div class="ste-dialog-actions">
				<button type="button" class="ste-dialog-btn ste-dialog-btn--cancel">Cancel</button>
				<button type="button" class="ste-dialog-btn ste-dialog-btn--insert">Insert</button>
			</div>
		</div>
	`;

	overlay.appendChild(dialog);

	const fileInput = dialog.querySelector('.ste-upload-input');
	const uploadZone = dialog.querySelector('.ste-upload-zone');
	const progress = dialog.querySelector('.ste-upload-progress');
	const progressBar = dialog.querySelector('.ste-upload-progress-bar');
	const progressText = dialog.querySelector('.ste-upload-progress-text');
	const errorEl = dialog.querySelector('.ste-upload-error');
	const rules = uploadConfig.rules || {};
	let uploadedUrl = null;

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
				uploadZone.innerHTML = `<p>Uploaded: ${file.name}</p>`;
			},
			onError(msg) {
				progress.style.display = 'none';
				showError(msg);
			},
		});
	}

	// Close handlers
	const close = () => overlay.remove();
	dialog.querySelector('.ste-dialog-close').addEventListener('click', close);
	dialog.querySelector('.ste-dialog-btn--cancel').addEventListener('click', close);
	overlay.addEventListener('click', (e) => {
		if (e.target === overlay) close();
	});

	// Insert handler
	dialog.querySelector('.ste-dialog-btn--insert').addEventListener('click', () => {
		const alt = dialog.querySelector('.ste-alt-input').value;

		if (uploadedUrl) {
			editor.chain().focus().setImage({ src: uploadedUrl, alt: alt || undefined }).run();
		}
		close();
	});

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
			class: { default: null },
		};
	},

	addCommands() {
		return {
			...this.parent?.(),
			openImageDialog: () => ({ editor }) => {
				const uploadConfig = editor.options.uploadConfig || {};
				const dialog = createImageDialog(editor, uploadConfig);
				document.body.appendChild(dialog);
				return true;
			},
		};
	},

	addProseMirrorPlugins() {
		const uploadConfig = this.editor.options.uploadConfig || {};
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
		];
	},
});

export default ImageUpload;
export { createImageDialog };
