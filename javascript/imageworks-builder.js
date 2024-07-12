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

	const copyMacroButton = document.getElementById("copy-macro");
	copyMacroButton.addEventListener("click", event => {
		event.preventDefault();
		const macroContent = document.getElementById("twig-macro");
		navigator.clipboard.writeText(macroContent.textContent).then(() => {
			setTimeout(() => {
				const originalText = copyMacroButton.textContent;
				copyMacroButton.style.width = `${copyMacroButton.offsetWidth}px`;
				copyMacroButton.classList.add("copied");
				copyMacroButton.textContent = "Copied!";

				setTimeout(() => {
					copyMacroButton.classList.remove("copied");
					copyMacroButton.textContent = originalText;
					copyMacroButton.style.width = "";
				}, 2000);
			}, 200);
		})
		.catch(err => {
			console.warn('Could not copy macro: ', err);
		});
	});

	const generateTwigMacro = (data) => {
		const id         = data.id;
		const collection = data.collection;
		const property   = data.property;
		const name       = data.name;

		// Delete the keys from data
		delete data.id;
		delete data.collection;
		delete data.property;
		delete data.cache;
		delete data.name;

		// Convert string numbers to actual numbers
		data = Object.entries(data).reduce((acc, [key, value]) => {
			acc[key] = isNaN(value) ? value : parseFloat(value);
			return acc;
		}, {});

		let options = '';

		if (Object.keys(data).length > 0) {
			options = JSON.stringify(data);
			options = options.replace(/"(\w+)"\s*:/g, '$1:').trim();
			options = `, '${options}'`;
		}

		let macro = `{{ cms.imagePath('${id}'${options}, {collection:'${collection}',property:'${property}'}) }}`;
		if (property === "image") {
			macro = `{{ cms.imagePath('${id}'${options}, {collection:'${collection}'}) }}`;

			if (collection === "image") {
				macro = `{{ cms.imagePath('${id}'${options}) }}`;
			}
		}

		if (name) {
			macro = `{{ cms.galleryPath('${id}', '${name}'${options}, {collection:'${collection}',property:'${property}'}) }}`;
			if (property === "gallery") {
				macro = `{{ cms.galleryPath('${id}', '${name}'${options}, {collection:'${collection}'}) }}`;

				if (collection === "gallery") {
					macro = `{{ cms.galleryPath('${id}', '${name}'${options}) }}`;
				}
			}
		}

		const macroContent = document.getElementById("twig-macro");
		macroContent.textContent = macro;
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
				data[key] = value;
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
