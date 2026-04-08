import MacroBuilder from "./macro-builder";
import Details from "./totalform/details";
import Dialog from "./totalform/dialog";

if (window.self !== window.top) {
	// The page is in an iframe
	document.body.classList.add('in-iframe');
}

document.addEventListener("DOMContentLoaded", event => {

	const previewImage = document.querySelector(".image-preview img");
	const imageUrl = new URL(previewImage.src);
	const originalExtension = imageUrl.pathname.split('.').pop();

	// Get the page URL and its search parameters
	const imageParams = imageUrl.searchParams;
	const datadir = imageParams.get('datadir');
	const route = imageParams.get('route');

	const details = Array.from(document.querySelectorAll("details"));
	for (const detail of details) {
		const accordion = new Details(detail, {openFirst:true});
	}

	const fitModal = new Dialog(document.getElementById("fit-modal"));
	const fitButtons = Array.from(document.getElementsByClassName("open-fit-docs"));
	fitButtons.forEach(button => {
		button.addEventListener("click", event => {
			event.preventDefault();
			fitModal.open();
		});
	});

	const generateTwigMacros = (data) => {
		const pathMacro   = document.getElementById("twig-macro");
		const renderMacro = document.getElementById("twig-render-macro");

		if (data.name?.length > 0) {
			pathMacro.textContent   = MacroBuilder.galleryPath({...data});
			renderMacro.textContent = MacroBuilder.renderGalleryImage({...data});
			return;
		}
		pathMacro.textContent   = MacroBuilder.imagePath({...data});
		renderMacro.textContent = MacroBuilder.renderImage({...data});
	}

	const filesize = document.getElementById('filesize');
	const dimensions = document.getElementById('dimensions');

	const bytesToSize = bytes => {
		const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
		if (bytes === 0) return '0 Bytes';
		// Use 1000 (decimal) to match Mac/browser display, not 1024 (binary)
		const i = Math.floor(Math.log(bytes) / Math.log(1000));
		const value = bytes / Math.pow(1000, i);
		// No decimals for Bytes or KB, 1 decimal for MB+
		if (i <= 1) {
			return Math.round(value) + ' ' + sizes[i];
		}
		return value.toFixed(1) + ' ' + sizes[i];
	}

	const getImageSize = () => {
		const img = new Image();
		img.onload = function() {
			dimensions.textContent = `(${this.width}x${this.height})`;
		};
		img.src = previewImage.src;

		fetch(previewImage.src).then(response => {
			if (response.ok) {
				const contentLength = response.headers.get('Content-Length');
				if (contentLength) {
					filesize.textContent = bytesToSize(contentLength);
					return;
				}
				// Content-Length missing (chunked transfer or compression) — read the body
				console.warn('Content-Length header missing, reading blob size instead');
				return response.blob().then(blob => {
					filesize.textContent = bytesToSize(blob.size);
				});
			} else {
				filesize.textContent = 'Error';
				console.warn('Image size fetch failed:', response.status);
			}
		});
	};
	getImageSize();

	const getFormData = () => {
		const form     = document.querySelector("form");
		const formData = Object.fromEntries(new FormData(form));

		const data = { cache : Date.now() };
		for (const [key, value] of Object.entries(formData)) {
			if (value !== '') {
				// Convert literal \n in marktext to actual newlines
				if (key === 'marktext') {
					data[key] = value.replace(/\\n/g, '\n');
				} else {
					data[key] = value;
				}
			}
		}
		return data;
	};

	const downloadButton = document.getElementById("download-image");
	const refreshButton = document.getElementById("refresh-image");
	refreshButton.addEventListener("click", event => {
		event.preventDefault();

		// get the form data and append it to the URL as search params
		const data = getFormData();

		// Determine extension: explicit fm > preset fm > original extension
		let extension = originalExtension;
		if (data.fm) {
			extension = data.fm;
		} else if (data.p && window.imageworksPresets?.[data.p]?.fm) {
			extension = window.imageworksPresets[data.p].fm;
		}

		// Replace the extension in imageUrl.pathname
		imageUrl.pathname = imageUrl.pathname.replace(/\.[^/.]+$/, "." + extension);

		const params = new URLSearchParams(data);

		// If 'datadir' and 'route' parameters exist, add them to the image parameters
		// This is used in Stacks PHP Preview Server
		if (datadir !== null) {
			params.set('datadir', datadir);
		}
		if (route !== null) {
			params.set('route', route);
		}

		// Update the image URL search parameters
		imageUrl.search = params.toString();

		const img = new Image();
		img.onload = () => {
			const originalText = refreshButton.textContent;
			refreshButton.textContent = "Done!";

			setTimeout(() => {
				refreshButton.textContent = originalText;
			}, 2000);
		};
		img.src = imageUrl.href;

		// update the preview image
		previewImage.src = imageUrl.href;
		downloadButton.href = imageUrl.href;

		getImageSize();
		generateTwigMacros(data);
	});
	generateTwigMacros(getFormData());
});
