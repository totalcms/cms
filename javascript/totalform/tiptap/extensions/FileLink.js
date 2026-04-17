/**
 * FileLink Extension
 * File upload that inserts download links.
 * Includes Files tab for managing previously uploaded files.
 */

import { getUploadUrl, uploadFileWithProgress } from '../upload.js';
import tcmsConfirm from '../../../confirm-dialog';
import { t } from '../../../i18n';

/**
 * Creates a file upload dialog
 */
function createFileDialog(editor, uploadConfig) {
	const overlay = document.createElement('div');
	overlay.className = 'ste-dialog-overlay';

	const dialog = document.createElement('div');
	dialog.className = 'ste-dialog ste-dialog--file-manager';

	dialog.innerHTML = `
		<div class="ste-dialog-header">
			<h3>Insert File Link</h3>
			<button type="button" class="ste-dialog-close" aria-label="Close">&times;</button>
		</div>
		<div class="ste-dialog-tabs">
			<button type="button" class="ste-dialog-tab is-active" data-tab="upload">Upload</button>
			<button type="button" class="ste-dialog-tab" data-tab="files">Files</button>
		</div>
		<div class="ste-dialog-body">
			<div class="ste-dialog-panel is-active" data-panel="upload">
				<div class="ste-upload-zone">
					<input type="file" class="ste-upload-input" />
					<p>Click or drag a file here to upload</p>
				</div>
				<div class="ste-upload-progress" style="display:none;">
					<div class="ste-upload-progress-bar"></div>
					<span class="ste-upload-progress-text">Uploading...</span>
				</div>
				<div class="ste-upload-error" style="display:none;"></div>
			</div>
			<div class="ste-dialog-panel" data-panel="files">
				<div class="ste-file-loading">Loading files...</div>
				<div class="ste-file-list" style="display:none;"></div>
				<div class="ste-file-empty" style="display:none;">No files uploaded yet</div>
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

	// Tab switching
	let filesLoaded = false;
	dialog.querySelectorAll('.ste-dialog-tab').forEach(tab => {
		tab.addEventListener('click', () => {
			dialog.querySelectorAll('.ste-dialog-tab').forEach(t => t.classList.remove('is-active'));
			dialog.querySelectorAll('.ste-dialog-panel').forEach(p => p.classList.remove('is-active'));
			tab.classList.add('is-active');
			dialog.querySelector(`[data-panel="${tab.dataset.tab}"]`).classList.add('is-active');

			if (tab.dataset.tab === 'files') {
				loadFiles();
			}
		});
	});

	let uploadedUrl = null;
	let uploadedName = null;
	let selectedFileUrl = null;
	let selectedFileName = null;
	const fileInput = dialog.querySelector('.ste-upload-input');
	const uploadZone = dialog.querySelector('.ste-upload-zone');
	const progress = dialog.querySelector('.ste-upload-progress');
	const progressBar = dialog.querySelector('.ste-upload-progress-bar');
	const progressText = dialog.querySelector('.ste-upload-progress-text');
	const errorEl = dialog.querySelector('.ste-upload-error');
	const fileListEl = dialog.querySelector('.ste-file-list');
	const fileLoading = dialog.querySelector('.ste-file-loading');
	const fileEmpty = dialog.querySelector('.ste-file-empty');

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
		errorEl.style.display = 'none';
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
				filesLoaded = false;
			},
			onError(msg) {
				progress.style.display = 'none';
				errorEl.textContent = msg;
				errorEl.style.display = '';
			},
		});
	}

	// File Manager: load files from the API
	async function loadFiles(force = false) {
		if (filesLoaded && !force) return;

		fileLoading.style.display = '';
		fileListEl.style.display = 'none';
		fileEmpty.style.display = 'none';
		selectedFileUrl = null;
		selectedFileName = null;

		const listUrl = getUploadUrl(uploadConfig);
		if (!listUrl) return;

		const fetchUrl = `${listUrl}?type=file`;

		try {
			const resp = await fetch(fetchUrl, { method: 'GET' });
			if (!resp.ok) throw new Error(`Failed to load files: ${resp.status}`);
			const data = await resp.json();
			const files = data.files || [];

			fileLoading.style.display = 'none';

			if (files.length === 0) {
				fileEmpty.style.display = '';
				fileListEl.style.display = 'none';
			} else {
				fileEmpty.style.display = 'none';
				fileListEl.style.display = '';
				renderFileList(files);
			}

			filesLoaded = true;
		} catch (err) {
			fileLoading.style.display = 'none';
			fileEmpty.textContent = 'Failed to load files';
			fileEmpty.style.display = '';
			console.error('File manager load error:', err);
		}
	}

	function renderFileList(files) {
		fileListEl.innerHTML = '';
		selectedFileUrl = null;
		selectedFileName = null;

		files.forEach(file => {
			const row = document.createElement('div');
			row.className = 'ste-file-row';
			row.dataset.url = file.url;
			row.dataset.name = file.name;

			row.innerHTML = `
				<span class="ste-file-row__name" title="${file.name}">${file.name}</span>
				<button type="button" class="ste-file-row__delete" aria-label="Delete" title="Delete">✕</button>
			`;

			row.addEventListener('click', (e) => {
				if (e.target.closest('.ste-file-row__delete')) return;
				fileListEl.querySelectorAll('.ste-file-row').forEach(r => r.classList.remove('is-selected'));
				row.classList.add('is-selected');
				selectedFileUrl = file.url;
				selectedFileName = file.name;
			});

			row.addEventListener('dblclick', (e) => {
				if (e.target.closest('.ste-file-row__delete')) return;
				selectedFileUrl = file.url;
				selectedFileName = file.name;
				insertFile();
			});

			row.querySelector('.ste-file-row__delete').addEventListener('click', (e) => {
				e.stopPropagation();
				handleDeleteFile(file.name, row);
			});

			fileListEl.appendChild(row);
		});
	}

	async function handleDeleteFile(filename, row) {
		const editorHtml = editor.getHTML();
		const inUse = editorHtml.includes(filename);

		const message = inUse
			? t("confirm.file_in_use")
			: t("confirm.delete_label", {label: "file"});

		if (!(await tcmsConfirm({ message }))) return;

		const listUrl = getUploadUrl(uploadConfig);
		const deleteUrl = `${listUrl}/${encodeURIComponent(filename)}`;

		fetch(deleteUrl, { method: 'DELETE' })
			.then(resp => {
				if (!resp.ok) throw new Error(`Delete failed: ${resp.status}`);
				row.remove();
				if (fileListEl.children.length === 0) {
					fileListEl.style.display = 'none';
					fileEmpty.textContent = 'No files uploaded yet';
					fileEmpty.style.display = '';
				}
				if (selectedFileUrl && row.dataset.url === selectedFileUrl) {
					selectedFileUrl = null;
					selectedFileName = null;
				}
			})
			.catch(err => {
				console.error('Delete file error:', err);
				alert(t("error.delete_file"));
			});
	}

	function insertFile() {
		const activePanel = dialog.querySelector('.ste-dialog-panel.is-active')?.dataset.panel;
		const linkTextInput = dialog.querySelector('.ste-link-text-input');

		if (activePanel === 'upload' && uploadedUrl) {
			const linkText = linkTextInput.value || uploadedName || 'Download file';
			editor.chain().focus().insertContent(
				`<a href="${uploadedUrl}" target="_blank" rel="noopener">${linkText}</a>`
			).run();
			close();
		} else if (activePanel === 'files' && selectedFileUrl) {
			const linkText = linkTextInput.value || selectedFileName || 'Download file';
			editor.chain().focus().insertContent(
				`<a href="${selectedFileUrl}" target="_blank" rel="noopener">${linkText}</a>`
			).run();
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
	dialog.querySelector('.ste-dialog-btn--insert').addEventListener('click', insertFile);

	return overlay;
}

export { createFileDialog };
