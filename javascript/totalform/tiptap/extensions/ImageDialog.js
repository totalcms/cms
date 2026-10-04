/**
 * Image upload dialog (Upload and Images tabs), shared by the styledtext
 * editor and the markdown source field. It lives outside ImageUpload.js so a
 * field that does not use Tiptap can open it. `target` is an insert target
 * (see insertTargets.js): { getContent(), insertImage({src, alt}) }.
 */

import { getUploadUrl, getListUrl, uploadFileWithProgress, validateFile, getCsrfToken } from '../upload.js';
import tcmsConfirm from '../../../confirm-dialog';
import { t } from '../../../i18n';
import { apiErrorMessage, rejectNonOk } from '../../../api-error';

/**
 * Creates an image upload dialog with Upload and Images tabs. Mounts and shows
 * itself as a native <dialog> so it stacks correctly above other modals (e.g.,
 * a deck dialog hosting the styledtext field).
 */
export function createImageDialog(target, uploadConfig) {
	const dialog = document.createElement('dialog');
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

		const listInfo = getListUrl(uploadConfig);
		if (!listInfo) return;

		// Append type filter and preset to whatever query params getListUrl returned (e.g., path=).
		const params = listInfo.params;
		params.set('type', 'image');
		if (uploadConfig.imagePreset) {
			params.set('preset', uploadConfig.imagePreset);
		}
		const fetchUrl = `${listInfo.url}?${params}`;

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

	async function handleDeleteImage(filename, card) {
		// Check if this image is currently used in the editor content
		const editorHtml = target.getContent();
		const inUse = editorHtml.includes(filename);

		const message = inUse
			? t("confirm.image_in_use")
			: t("confirm.delete_label", {label: "image"});

		if (!(await tcmsConfirm({ message }))) return;

		const listUrl = getUploadUrl(uploadConfig);
		const deleteUrl = `${listUrl}/${encodeURIComponent(filename)}`;

		fetch(deleteUrl, { method: 'DELETE', headers: getCsrfToken() ? { 'X-CSRF-Token': getCsrfToken() } : {} })
			.then(rejectNonOk)
			.then(resp => {
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
				alert(apiErrorMessage(err, "error.delete_image"));
			});
	}

	function insertImage() {
		const alt = dialog.querySelector('.ste-alt-input').value;
		const activePanel = dialog.querySelector('.ste-dialog-panel.is-active')?.dataset.panel;

		if (activePanel === 'upload' && uploadedUrl) {
			target.insertImage({ src: uploadedUrl, alt });
			close();
		} else if (activePanel === 'images' && selectedImageUrl) {
			target.insertImage({ src: selectedImageUrl, alt });
			close();
		}
	}

	// Close handlers
	const close = () => dialog.close();
	dialog.querySelector('.ste-dialog-close').addEventListener('click', close);
	dialog.querySelector('.ste-dialog-btn--cancel').addEventListener('click', close);

	// Backdrop click closes (Escape is handled natively)
	dialog.addEventListener('click', (e) => {
		if (e.target === dialog) close();
	});

	// Insert handler
	dialog.querySelector('.ste-dialog-btn--insert').addEventListener('click', insertImage);

	dialog.addEventListener('close', () => dialog.remove());

	document.body.appendChild(dialog);
	dialog.showModal();
}
