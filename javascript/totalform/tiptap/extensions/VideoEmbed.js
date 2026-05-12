/**
 * VideoEmbed Extension
 * YouTube/Vimeo auto-embed, video upload, and audio upload support.
 * Includes Media tab for managing previously uploaded video/audio files.
 */

import Youtube from '@tiptap/extension-youtube';
import { getUploadUrl, getListUrl, uploadFileWithProgress } from '../upload.js';
import tcmsConfirm from '../../../confirm-dialog';
import { t } from '../../../i18n';

const AUDIO_EXTENSIONS = ['mp3', 'wav', 'ogg', 'aac', 'flac', 'm4a', 'wma', 'opus'];

/**
 * Check if a filename or URL is an audio file.
 */
function isAudioFile(nameOrUrl) {
	const ext = nameOrUrl.split('.').pop()?.toLowerCase() || '';
	return AUDIO_EXTENSIONS.includes(ext);
}

/**
 * Creates a media dialog (video + audio). Mounts and shows itself as a
 * native <dialog> so it stacks correctly above other modals (e.g., a deck
 * dialog hosting the styledtext field).
 */
function createVideoDialog(editor, uploadConfig) {
	const dialog = document.createElement('dialog');
	dialog.className = 'ste-dialog ste-dialog--file-manager';

	dialog.innerHTML = `
		<div class="ste-dialog-header">
			<h3>Insert Media</h3>
			<button type="button" class="ste-dialog-close" aria-label="Close">&times;</button>
		</div>
		<div class="ste-dialog-tabs">
			<button type="button" class="ste-dialog-tab is-active" data-tab="embed">Embed URL</button>
			<button type="button" class="ste-dialog-tab" data-tab="upload">Upload</button>
			<button type="button" class="ste-dialog-tab" data-tab="media">Media</button>
		</div>
		<div class="ste-dialog-body">
			<div class="ste-dialog-panel is-active" data-panel="embed">
				<input type="url" class="ste-url-input" placeholder="https://youtube.com/watch?v=... or https://vimeo.com/..." />
				<p class="ste-dialog-hint">Paste a YouTube or Vimeo URL</p>
			</div>
			<div class="ste-dialog-panel" data-panel="upload">
				<div class="ste-upload-zone">
					<input type="file" accept="video/*,audio/*" class="ste-upload-input" />
					<p>Click or drag a video or audio file here</p>
				</div>
				<div class="ste-upload-progress" style="display:none;">
					<div class="ste-upload-progress-bar"></div>
					<span class="ste-upload-progress-text">Uploading...</span>
				</div>
				<div class="ste-upload-error" style="display:none;"></div>
			</div>
			<div class="ste-dialog-panel" data-panel="media">
				<div class="ste-file-loading">Loading media...</div>
				<div class="ste-file-list" style="display:none;"></div>
				<div class="ste-file-empty" style="display:none;">No media uploaded yet</div>
			</div>
		</div>
		<div class="ste-dialog-footer">
			<div class="ste-dialog-actions">
				<button type="button" class="ste-dialog-btn ste-dialog-btn--cancel">Cancel</button>
				<button type="button" class="ste-dialog-btn ste-dialog-btn--insert">Insert</button>
			</div>
		</div>
	`;

	// Tab switching
	let mediaLoaded = false;
	dialog.querySelectorAll('.ste-dialog-tab').forEach(tab => {
		tab.addEventListener('click', () => {
			dialog.querySelectorAll('.ste-dialog-tab').forEach(t => t.classList.remove('is-active'));
			dialog.querySelectorAll('.ste-dialog-panel').forEach(p => p.classList.remove('is-active'));
			tab.classList.add('is-active');
			dialog.querySelector(`[data-panel="${tab.dataset.tab}"]`).classList.add('is-active');

			if (tab.dataset.tab === 'media') {
				loadMedia();
			}
		});
	});

	// File upload
	let uploadedUrl = null;
	let uploadedIsAudio = false;
	let selectedFileUrl = null;
	let selectedIsAudio = false;
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
		if (file && (file.type.startsWith('video/') || file.type.startsWith('audio/'))) {
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
				uploadedIsAudio = file.type.startsWith('audio/');
				uploadZone.innerHTML = `<p>Uploaded: ${file.name}</p>`;
				mediaLoaded = false;
			},
			onError(msg) {
				progress.style.display = 'none';
				errorEl.textContent = msg;
				errorEl.style.display = '';
			},
		});
	}

	// Media Manager: load video + audio files from the API
	async function loadMedia(force = false) {
		if (mediaLoaded && !force) return;

		fileLoading.style.display = '';
		fileList.style.display = 'none';
		fileEmpty.style.display = 'none';
		selectedFileUrl = null;

		const listInfo = getListUrl(uploadConfig);
		if (!listInfo) return;

		listInfo.params.set('type', 'media');
		const fetchUrl = `${listInfo.url}?${listInfo.params}`;

		try {
			const resp = await fetch(fetchUrl, { method: 'GET' });
			if (!resp.ok) throw new Error(`Failed to load media: ${resp.status}`);
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

			mediaLoaded = true;
		} catch (err) {
			fileLoading.style.display = 'none';
			fileEmpty.textContent = 'Failed to load media';
			fileEmpty.style.display = '';
			console.error('Media manager load error:', err);
		}
	}

	function renderFileList(files) {
		fileList.innerHTML = '';
		selectedFileUrl = null;

		files.forEach(file => {
			const row = document.createElement('div');
			row.className = 'ste-file-row';
			row.dataset.url = file.url;
			row.dataset.name = file.name;

			const audio = isAudioFile(file.name);
			const typeLabel = audio ? '♪' : '▶';

			row.innerHTML = `
				<span class="ste-file-row__type" title="${audio ? 'Audio' : 'Video'}">${typeLabel}</span>
				<span class="ste-file-row__name" title="${file.name}">${file.name}</span>
				<button type="button" class="ste-file-row__delete" aria-label="Delete" title="Delete">✕</button>
			`;

			row.addEventListener('click', (e) => {
				if (e.target.closest('.ste-file-row__delete')) return;
				fileList.querySelectorAll('.ste-file-row').forEach(r => r.classList.remove('is-selected'));
				row.classList.add('is-selected');
				selectedFileUrl = file.url;
				selectedIsAudio = audio;
			});

			row.addEventListener('dblclick', (e) => {
				if (e.target.closest('.ste-file-row__delete')) return;
				selectedFileUrl = file.url;
				selectedIsAudio = audio;
				insertMedia();
			});

			row.querySelector('.ste-file-row__delete').addEventListener('click', (e) => {
				e.stopPropagation();
				handleDeleteFile(file.name, row, audio ? 'audio file' : 'video');
			});

			fileList.appendChild(row);
		});
	}

	async function handleDeleteFile(filename, row, label) {
		const editorHtml = editor.getHTML();
		const inUse = editorHtml.includes(filename);

		const message = inUse
			? t("confirm.video_in_use", {label})
			: t("confirm.delete_label", {label});

		if (!(await tcmsConfirm({ message }))) return;

		const listUrl = getUploadUrl(uploadConfig);
		const deleteUrl = `${listUrl}/${encodeURIComponent(filename)}`;

		fetch(deleteUrl, { method: 'DELETE' })
			.then(resp => {
				if (!resp.ok) throw new Error(`Delete failed: ${resp.status}`);
				row.remove();
				if (fileList.children.length === 0) {
					fileList.style.display = 'none';
					fileEmpty.textContent = 'No media uploaded yet';
					fileEmpty.style.display = '';
				}
				if (selectedFileUrl && row.dataset.url === selectedFileUrl) {
					selectedFileUrl = null;
				}
			})
			.catch(err => {
				console.error('Delete error:', err);
				alert(t("error.delete_label", {label}));
			});
	}

	function insertMedia() {
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
				} else if (isAudioFile(url)) {
					editor.chain().focus().setAudio({ src: url }).run();
				} else {
					editor.chain().focus().setVideo({ src: url }).run();
				}
			}
		} else if (activePanel === 'upload' && uploadedUrl) {
			if (uploadedIsAudio) {
				editor.chain().focus().setAudio({ src: uploadedUrl }).run();
			} else {
				editor.chain().focus().setVideo({ src: uploadedUrl }).run();
			}
		} else if (activePanel === 'media' && selectedFileUrl) {
			if (selectedIsAudio) {
				editor.chain().focus().setAudio({ src: selectedFileUrl }).run();
			} else {
				editor.chain().focus().setVideo({ src: selectedFileUrl }).run();
			}
		}

		close();
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
	dialog.querySelector('.ste-dialog-btn--insert').addEventListener('click', insertMedia);

	dialog.addEventListener('close', () => dialog.remove());

	document.body.appendChild(dialog);
	dialog.showModal();
}

export { Youtube, createVideoDialog };
