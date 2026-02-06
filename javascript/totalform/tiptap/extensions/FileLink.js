/**
 * FileLink Extension
 * File upload that inserts download links.
 */

import { getUploadUrl, uploadFileWithProgress } from '../upload.js';

/**
 * Creates a file upload dialog
 */
function createFileDialog(editor, uploadConfig) {
	const overlay = document.createElement('div');
	overlay.className = 'ste-dialog-overlay';

	const dialog = document.createElement('div');
	dialog.className = 'ste-dialog';

	dialog.innerHTML = `
		<div class="ste-dialog-header">
			<h3>Insert File Link</h3>
			<button type="button" class="ste-dialog-close" aria-label="Close">&times;</button>
		</div>
		<div class="ste-dialog-body">
			<div class="ste-upload-zone">
				<input type="file" class="ste-upload-input" />
				<p>Click or drag a file here to upload</p>
			</div>
			<div class="ste-upload-progress" style="display:none;">
				<div class="ste-upload-progress-bar"></div>
				<span class="ste-upload-progress-text">Uploading...</span>
			</div>
		</div>
		<div class="ste-dialog-footer">
			<input type="text" class="ste-link-text-input" placeholder="Link text (optional)" />
			<div class="ste-dialog-actions">
				<button type="button" class="ste-dialog-btn ste-dialog-btn--cancel">Cancel</button>
				<button type="button" class="ste-dialog-btn ste-dialog-btn--insert">Insert</button>
			</div>
		</div>
	`;

	overlay.appendChild(dialog);

	let uploadedUrl = null;
	let uploadedName = null;
	const fileInput = dialog.querySelector('.ste-upload-input');
	const uploadZone = dialog.querySelector('.ste-upload-zone');
	const progress = dialog.querySelector('.ste-upload-progress');
	const progressBar = dialog.querySelector('.ste-upload-progress-bar');
	const progressText = dialog.querySelector('.ste-upload-progress-text');

	// Drag and drop
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
		if (e.dataTransfer.files[0]) {
			handleUpload(e.dataTransfer.files[0]);
		}
	});

	fileInput.addEventListener('change', () => {
		if (fileInput.files[0]) handleUpload(fileInput.files[0]);
	});

	function handleUpload(file) {
		const url = getUploadUrl(uploadConfig);
		uploadedName = file.name;
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
				progressText.textContent = msg;
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
		if (uploadedUrl) {
			const linkText = dialog.querySelector('.ste-link-text-input').value || uploadedName || 'Download file';
			editor.chain().focus().insertContent(
				`<a href="${uploadedUrl}" target="_blank" rel="noopener">${linkText}</a>`
			).run();
		}
		close();
	});

	return overlay;
}

export { createFileDialog };
