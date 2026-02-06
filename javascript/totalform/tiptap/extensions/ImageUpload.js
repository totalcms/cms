/**
 * ImageUpload Extension
 * Custom image node with upload dialog, drag-drop, paste support.
 * Uses existing /upload/ API routes.
 */

import Image from '@tiptap/extension-image';
import { Plugin } from '@tiptap/pm/state';

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
		<div class="ste-dialog-tabs">
			<button type="button" class="ste-dialog-tab is-active" data-tab="upload">Upload</button>
			<button type="button" class="ste-dialog-tab" data-tab="url">URL</button>
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
			</div>
			<div class="ste-dialog-panel" data-panel="url">
				<input type="url" class="ste-url-input" placeholder="https://example.com/image.jpg" />
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
	dialog.querySelectorAll('.ste-dialog-tab').forEach(tab => {
		tab.addEventListener('click', () => {
			dialog.querySelectorAll('.ste-dialog-tab').forEach(t => t.classList.remove('is-active'));
			dialog.querySelectorAll('.ste-dialog-panel').forEach(p => p.classList.remove('is-active'));
			tab.classList.add('is-active');
			dialog.querySelector(`[data-panel="${tab.dataset.tab}"]`).classList.add('is-active');
		});
	});

	// File input
	const fileInput = dialog.querySelector('.ste-upload-input');
	const uploadZone = dialog.querySelector('.ste-upload-zone');
	let uploadedUrl = null;

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
			uploadFile(file);
		}
	});

	fileInput.addEventListener('change', () => {
		if (fileInput.files[0]) {
			uploadFile(fileInput.files[0]);
		}
	});

	function uploadFile(file) {
		const url = typeof uploadConfig.url === 'function' ? uploadConfig.url() : uploadConfig.url;
		if (!url) {
			console.warn('No upload URL configured');
			return;
		}

		const formData = new FormData();
		formData.append(uploadConfig.imageParam || 'image', file);

		const progress = dialog.querySelector('.ste-upload-progress');
		const progressBar = dialog.querySelector('.ste-upload-progress-bar');
		const progressText = dialog.querySelector('.ste-upload-progress-text');
		progress.style.display = '';

		const xhr = new XMLHttpRequest();
		xhr.open('POST', url);

		xhr.upload.addEventListener('progress', (e) => {
			if (e.lengthComputable) {
				const percent = Math.round((e.loaded / e.total) * 100);
				progressBar.style.width = `${percent}%`;
				progressText.textContent = `Uploading... ${percent}%`;
			}
		});

		xhr.addEventListener('load', () => {
			progress.style.display = 'none';
			if (xhr.status >= 200 && xhr.status < 300) {
				try {
					const data = JSON.parse(xhr.responseText);
					uploadedUrl = data.link;
					uploadZone.innerHTML = `<p>Uploaded: ${file.name}</p>`;
				} catch {
					console.error('Failed to parse upload response');
				}
			} else {
				progressText.textContent = 'Upload failed';
			}
		});

		xhr.addEventListener('error', () => {
			progress.style.display = 'none';
			progressText.textContent = 'Upload failed';
		});

		xhr.send(formData);
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
		const activePanel = dialog.querySelector('.ste-dialog-panel.is-active').dataset.panel;

		let src;
		if (activePanel === 'upload') {
			src = uploadedUrl;
		} else {
			src = dialog.querySelector('.ste-url-input').value;
		}

		if (src) {
			editor.chain().focus().setImage({ src, alt: alt || undefined }).run();
		}
		close();
	});

	return overlay;
}

/**
 * Upload a file directly (for paste/drop into editor)
 */
function uploadImageDirect(file, uploadConfig) {
	return new Promise((resolve, reject) => {
		const url = typeof uploadConfig.url === 'function' ? uploadConfig.url() : uploadConfig.url;
		if (!url) {
			reject(new Error('No upload URL'));
			return;
		}

		const formData = new FormData();
		formData.append(uploadConfig.imageParam || 'image', file);

		const xhr = new XMLHttpRequest();
		xhr.open('POST', url);

		xhr.addEventListener('load', () => {
			if (xhr.status >= 200 && xhr.status < 300) {
				try {
					const data = JSON.parse(xhr.responseText);
					resolve(data.link);
				} catch {
					reject(new Error('Failed to parse response'));
				}
			} else {
				reject(new Error(`Upload failed: ${xhr.status}`));
			}
		});

		xhr.addEventListener('error', () => reject(new Error('Upload error')));
		xhr.send(formData);
	});
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

		return [
			...(this.parent?.() || []),
			new Plugin({
				props: {
					handleDrop: (view, event) => {
						if (!event.dataTransfer?.files?.length) return false;

						const file = event.dataTransfer.files[0];
						if (!file.type.startsWith('image/')) return false;

						event.preventDefault();

						uploadImageDirect(file, uploadConfig).then(src => {
							const { schema } = view.state;
							const node = schema.nodes.image.create({ src });
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

								uploadImageDirect(file, uploadConfig).then(src => {
									const { schema } = view.state;
									const node = schema.nodes.image.create({ src });
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
export { createImageDialog, uploadImageDirect };
