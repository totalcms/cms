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

	const generateTwigMacro = (data) => {
		const macroContent = document.getElementById("twig-macro");
		if (data.name?.length > 0) {
			macroContent.textContent = MacroBuilder.galleryPath(data);
			return;
		}
		macroContent.textContent = MacroBuilder.imagePath(data);
	}

	const filesize = document.getElementById('filesize');
	const dimensions = document.getElementById('dimensions');

	const bytesToSize = bytes => {
		const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
		if (bytes === 0) return '0 Byte';
		const i = parseInt(Math.floor(Math.log(bytes) / Math.log(1024)));
		return Math.round(bytes / Math.pow(1024, i), 2) + ' ' + sizes[i];
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
				filesize.textContent = 'Unknown';
				console.warn('Image Content-Length header missing:', response.headers);


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

		const extension = data.fm ?? originalExtension;

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
		generateTwigMacro(data);
	});
	generateTwigMacro(getFormData());
});
