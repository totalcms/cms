/**
 * VideoEmbed Extension
 * YouTube/Vimeo auto-embed and video upload support.
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
	dialog.className = 'ste-dialog';

	dialog.innerHTML = `
		<div class="ste-dialog-header">
			<h3>Insert Video</h3>
			<button type="button" class="ste-dialog-close" aria-label="Close">&times;</button>
		</div>
		<div class="ste-dialog-tabs">
			<button type="button" class="ste-dialog-tab is-active" data-tab="embed">Embed URL</button>
			<button type="button" class="ste-dialog-tab" data-tab="upload">Upload</button>
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
	dialog.querySelectorAll('.ste-dialog-tab').forEach(tab => {
		tab.addEventListener('click', () => {
			dialog.querySelectorAll('.ste-dialog-tab').forEach(t => t.classList.remove('is-active'));
			dialog.querySelectorAll('.ste-dialog-panel').forEach(p => p.classList.remove('is-active'));
			tab.classList.add('is-active');
			dialog.querySelector(`[data-panel="${tab.dataset.tab}"]`).classList.add('is-active');
		});
	});

	// File upload
	let uploadedUrl = null;
	const fileInput = dialog.querySelector('.ste-upload-input');
	const uploadZone = dialog.querySelector('.ste-upload-zone');
	const progress = dialog.querySelector('.ste-upload-progress');
	const progressBar = dialog.querySelector('.ste-upload-progress-bar');
	const progressText = dialog.querySelector('.ste-upload-progress-text');

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
		const activePanel = dialog.querySelector('.ste-dialog-panel.is-active').dataset.panel;

		if (activePanel === 'embed') {
			const url = dialog.querySelector('.ste-url-input').value;
			if (url) {
				// Try YouTube embed first
				if (url.match(/youtube\.com|youtu\.be/)) {
					editor.commands.setYoutubeVideo({ src: url });
				} else if (url.match(/vimeo\.com/)) {
					// Vimeo embed as iframe
					const vimeoId = url.match(/vimeo\.com\/(\d+)/)?.[1];
					if (vimeoId) {
						editor.chain().focus().insertContent(
							`<div class="cms-video-embed"><iframe src="https://player.vimeo.com/video/${vimeoId}" frameborder="0" allowfullscreen></iframe></div>`
						).run();
					}
				} else {
					// Generic video URL as HTML5 video
					editor.chain().focus().insertContent(
						`<video src="${url}" controls class="cms-video-embed"></video>`
					).run();
				}
			}
		} else if (activePanel === 'upload' && uploadedUrl) {
			editor.chain().focus().insertContent(
				`<video src="${uploadedUrl}" controls class="cms-video-embed"></video>`
			).run();
		}

		close();
	});

	return overlay;
}

export { Youtube, createVideoDialog };
