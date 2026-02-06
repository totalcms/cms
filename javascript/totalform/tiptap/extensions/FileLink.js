/**
 * FileLink Extension
 * File upload that inserts download links using existing /upload/ API routes.
 */

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
			uploadFile(e.dataTransfer.files[0]);
		}
	});

	fileInput.addEventListener('change', () => {
		if (fileInput.files[0]) uploadFile(fileInput.files[0]);
	});

	function uploadFile(file) {
		const url = typeof uploadConfig.url === 'function' ? uploadConfig.url() : uploadConfig.url;
		if (!url) return;

		const formData = new FormData();
		formData.append(uploadConfig.fileParam || 'file', file);
		uploadedName = file.name;

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
