/**
 * VideoEmbed Extension
 * YouTube/Vimeo auto-embed and video upload support.
 * Includes Videos tab for managing previously uploaded videos.
 */

import Youtube from '@tiptap/extension-youtube';
import { getUploadUrl, uploadFileWithProgress } from '../upload.js';

/**
 * Creates a video embed dialog
 */
function createVideoDialog(editor, uploadConfig) {
	const overlay = document.createElement('div');
	overlay.className = 'ste-dialog-overlay';

	const dialog = document.createElement('div');
	dialog.className = 'ste-dialog ste-dialog--file-manager';

	dialog.innerHTML = `
		<div class="ste-dialog-header">
			<h3>Insert Video</h3>
			<button type="button" class="ste-dialog-close" aria-label="Close">&times;</button>
		</div>
		<div class="ste-dialog-tabs">
			<button type="button" class="ste-dialog-tab is-active" data-tab="embed">Embed URL</button>
			<button type="button" class="ste-dialog-tab" data-tab="upload">Upload</button>
			<button type="button" class="ste-dialog-tab" data-tab="videos">Videos</button>
		</div>
		<div class="ste-dialog-body">
			<div class="ste-dialog-panel is-active" data-panel="embed">
				<input type="url" class="ste-url-input" placeholder="https://youtube.com/watch?v=... or https://vimeo.com/..." />
				<p class="ste-dialog-hint">Paste a YouTube or Vimeo URL</p>
			</div>
			<div class="ste-dialog-panel" data-panel="upload">
				<div class="ste-upload-zone">
					<input type="file" accept="video/*" class="ste-upload-input" />
					<p>Click or drag a video file here</p>
				</div>
				<div class="ste-upload-progress" style="display:none;">
					<div class="ste-upload-progress-bar"></div>
					<span class="ste-upload-progress-text">Uploading...</span>
				</div>
				<div class="ste-upload-error" style="display:none;"></div>
			</div>
			<div class="ste-dialog-panel" data-panel="videos">
				<div class="ste-file-loading">Loading videos...</div>
				<div class="ste-file-list" style="display:none;"></div>
				<div class="ste-file-empty" style="display:none;">No videos uploaded yet</div>
			</div>
		</div>
		<div class="ste-dialog-footer">
			<div class="ste-dialog-actions">
				<button type="button" class="ste-dialog-btn ste-dialog-btn--cancel">Cancel</button>
				<button type="button" class="ste-dialog-btn ste-dialog-btn--insert">Insert</button>
			</div>
		</div>
	`;

	overlay.appendChild(dialog);

	// Tab switching
	let videosLoaded = false;
	dialog.querySelectorAll('.ste-dialog-tab').forEach(tab => {
		tab.addEventListener('click', () => {
			dialog.querySelectorAll('.ste-dialog-tab').forEach(t => t.classList.remove('is-active'));
			dialog.querySelectorAll('.ste-dialog-panel').forEach(p => p.classList.remove('is-active'));
			tab.classList.add('is-active');
			dialog.querySelector(`[data-panel="${tab.dataset.tab}"]`).classList.add('is-active');

			if (tab.dataset.tab === 'videos') {
				loadVideos();
			}
		});
	});

	// File upload
	let uploadedUrl = null;
	let selectedVideoUrl = null;
	const fileInput = dialog.querySelector('.ste-upload-input');
	const uploadZone = dialog.querySelector('.ste-upload-zone');
	const progress = dialog.querySelector('.ste-upload-progress');
	const progressBar = dialog.querySelector('.ste-upload-progress-bar');
	const progressText = dialog.querySelector('.ste-upload-progress-text');
	const errorEl = dialog.querySelector('.ste-upload-error');
	const fileList = dialog.querySelector('.ste-file-list');
	const fileLoading = dialog.querySelector('.ste-file-loading');
	const fileEmpty = dialog.querySelector('.ste-file-empty');

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
		if (file && file.type.startsWith('video/')) {
			handleUpload(file);
		}
	});

	fileInput.addEventListener('change', () => {
		if (fileInput.files[0]) handleUpload(fileInput.files[0]);
	});

	function handleUpload(file) {
		const url = getUploadUrl(uploadConfig);
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
				videosLoaded = false;
			},
			onError(msg) {
				progress.style.display = 'none';
				errorEl.textContent = msg;
				errorEl.style.display = '';
			},
		});
	}

	// Video Manager: load videos from the API
	async function loadVideos(force = false) {
		if (videosLoaded && !force) return;

		fileLoading.style.display = '';
		fileList.style.display = 'none';
		fileEmpty.style.display = 'none';
		selectedVideoUrl = null;

		const listUrl = getUploadUrl(uploadConfig);
		if (!listUrl) return;

		const fetchUrl = `${listUrl}?type=video`;

		try {
			const resp = await fetch(fetchUrl, { method: 'GET' });
			if (!resp.ok) throw new Error(`Failed to load videos: ${resp.status}`);
			const data = await resp.json();
			const files = data.files || [];

			fileLoading.style.display = 'none';

			if (files.length === 0) {
				fileEmpty.style.display = '';
				fileList.style.display = 'none';
			} else {
				fileEmpty.style.display = 'none';
				fileList.style.display = '';
				renderFileList(files);
			}

			videosLoaded = true;
		} catch (err) {
			fileLoading.style.display = 'none';
			fileEmpty.textContent = 'Failed to load videos';
			fileEmpty.style.display = '';
			console.error('Video manager load error:', err);
		}
	}

	function renderFileList(files) {
		fileList.innerHTML = '';
		selectedVideoUrl = null;

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
				fileList.querySelectorAll('.ste-file-row').forEach(r => r.classList.remove('is-selected'));
				row.classList.add('is-selected');
				selectedVideoUrl = file.url;
			});

			row.addEventListener('dblclick', (e) => {
				if (e.target.closest('.ste-file-row__delete')) return;
				selectedVideoUrl = file.url;
				insertVideo();
			});

			row.querySelector('.ste-file-row__delete').addEventListener('click', (e) => {
				e.stopPropagation();
				handleDeleteFile(file.name, row, 'video');
			});

			fileList.appendChild(row);
		});
	}

	function handleDeleteFile(filename, row, label) {
		const editorHtml = editor.getHTML();
		const inUse = editorHtml.includes(filename);

		const message = inUse
			? `This ${label} is currently used in the content. Deleting it will break the reference. Continue?`
			: `Delete this ${label}?`;

		if (!confirm(message)) return;

		const listUrl = getUploadUrl(uploadConfig);
		const deleteUrl = `${listUrl}/${encodeURIComponent(filename)}`;

		fetch(deleteUrl, { method: 'DELETE' })
			.then(resp => {
				if (!resp.ok) throw new Error(`Delete failed: ${resp.status}`);
				row.remove();
				if (fileList.children.length === 0) {
					fileList.style.display = 'none';
					fileEmpty.textContent = 'No videos uploaded yet';
					fileEmpty.style.display = '';
				}
				if (selectedVideoUrl && row.dataset.url === selectedVideoUrl) {
					selectedVideoUrl = null;
				}
			})
			.catch(err => {
				console.error('Delete error:', err);
				alert(`Failed to delete ${label}`);
			});
	}

	function insertVideo() {
		const activePanel = dialog.querySelector('.ste-dialog-panel.is-active').dataset.panel;

		if (activePanel === 'embed') {
			const url = dialog.querySelector('.ste-url-input').value;
			if (url) {
				if (url.match(/youtube\.com|youtu\.be/)) {
					editor.commands.setYoutubeVideo({ src: url });
				} else if (url.match(/vimeo\.com/)) {
					const vimeoId = url.match(/vimeo\.com\/(\d+)/)?.[1];
					if (vimeoId) {
						editor.chain().focus().insertContent(
							`<div class="cms-video-embed"><iframe src="https://player.vimeo.com/video/${vimeoId}" frameborder="0" allowfullscreen></iframe></div>`
						).run();
					}
				} else {
					editor.chain().focus().setVideo({ src: url }).run();
				}
			}
		} else if (activePanel === 'upload' && uploadedUrl) {
			editor.chain().focus().setVideo({ src: uploadedUrl }).run();
		} else if (activePanel === 'videos' && selectedVideoUrl) {
			editor.chain().focus().setVideo({ src: selectedVideoUrl }).run();
		}

		close();
	}

	// Close handlers
	const close = () => overlay.remove();
	dialog.querySelector('.ste-dialog-close').addEventListener('click', close);
	dialog.querySelector('.ste-dialog-btn--cancel').addEventListener('click', close);
	overlay.addEventListener('click', (e) => {
		if (e.target === overlay) close();
	});

	// Insert handler
	dialog.querySelector('.ste-dialog-btn--insert').addEventListener('click', insertVideo);

	return overlay;
}

export { Youtube, createVideoDialog };
