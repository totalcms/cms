/**
 * Shared upload utility for TipTap extensions.
 * All uploads use a single 'file' form param.
 */

import DropletTestSet from '../droplet-testset.js';

/**
 * Get the upload URL from config (supports function or string).
 */
export function getUploadUrl(uploadConfig) {
	const url = uploadConfig?.url;
	return typeof url === 'function' ? url() : url;
}

/**
 * Load image dimensions from a File object.
 * Returns { width, height } or { width: 0, height: 0 } on error.
 */
function getImageDimensions(file) {
	return new Promise((resolve) => {
		const img = new Image();
		const url = URL.createObjectURL(file);
		img.onload = () => {
			resolve({ width: img.width, height: img.height });
			URL.revokeObjectURL(url);
		};
		img.onerror = () => {
			resolve({ width: 0, height: 0 });
			URL.revokeObjectURL(url);
		};
		img.src = url;
	});
}

/**
 * Validate a file against upload rules using DropletTestSet.
 * For images, loads the file to check dimensions.
 * Returns { valid: true } or { valid: false, errors: [...] }.
 */
export async function validateFile(file, rules) {
	if (!rules || Object.keys(rules).length === 0) {
		return { valid: true };
	}

	// Add image dimensions to the file object for validation
	if (file.type.startsWith('image/')) {
		const dims = await getImageDimensions(file);
		file.width = dims.width;
		file.height = dims.height;
	}

	const testSet = new DropletTestSet(rules);
	const pass = testSet.processRules(file, 0);

	return pass ? { valid: true } : { valid: false, errors: testSet.errors };
}

/**
 * Build FormData with the file and any extra params from config.
 * If config has imagePreset set, it is sent as the 'p' param
 * so ImageWorks processes the image with that preset.
 */
function buildFormData(file, uploadConfig) {
	const formData = new FormData();
	formData.append('file', file);

	if (uploadConfig?.imagePreset) {
		formData.append('p', uploadConfig.imagePreset);
	}

	return formData;
}

/**
 * Upload a file via XHR.
 * Resolves with the parsed JSON response data.
 */
export function uploadFile(file, url, uploadConfig) {
	return new Promise((resolve, reject) => {
		if (!url) {
			reject(new Error('No upload URL configured'));
			return;
		}

		const xhr = new XMLHttpRequest();
		xhr.open('POST', url);

		xhr.addEventListener('load', () => {
			if (xhr.status >= 200 && xhr.status < 300) {
				try {
					resolve(JSON.parse(xhr.responseText));
				} catch {
					reject(new Error('Failed to parse upload response'));
				}
			} else {
				reject(new Error(`Upload failed: ${xhr.status}`));
			}
		});

		xhr.addEventListener('error', () => reject(new Error('Upload error')));
		xhr.send(buildFormData(file, uploadConfig));
	});
}

/**
 * Upload a file with progress callbacks for dialog UI.
 * Calls onProgress(percent), onSuccess(data), onError(message).
 */
export function uploadFileWithProgress(file, url, uploadConfig, { onProgress, onSuccess, onError }) {
	if (!url) {
		onError?.('No upload URL configured');
		return;
	}

	const xhr = new XMLHttpRequest();
	xhr.open('POST', url);

	xhr.upload.addEventListener('progress', (e) => {
		if (e.lengthComputable) {
			onProgress?.(Math.round((e.loaded / e.total) * 100));
		}
	});

	xhr.addEventListener('load', () => {
		if (xhr.status >= 200 && xhr.status < 300) {
			try {
				onSuccess?.(JSON.parse(xhr.responseText));
			} catch {
				onError?.('Failed to parse upload response');
			}
		} else {
			onError?.(`Upload failed: ${xhr.status}`);
		}
	});

	xhr.addEventListener('error', () => onError?.('Upload error'));
	xhr.send(buildFormData(file, uploadConfig));
}
