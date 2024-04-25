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
			console.log('Text copied to clipboard');
		})
		.catch(err => {
			console.warn('Could not copy text: ', err);
		});
	});

	const generateTwigMacro = (data) => {
		const id         = data.id;
		const collection = data.collection;
		const property   = data.property;

		// Delete the keys from data
		delete data.id;
		delete data.collection;
		delete data.property;
		delete data.cache;

		// Convert string numbers to actual numbers
		data = Object.entries(data).reduce((acc, [key, value]) => {
			acc[key] = isNaN(value) ? value : parseFloat(value);
			return acc;
		}, {});

		let options = JSON.stringify(data);
		options = options.replace(/"(\w+)"\s*:/g, '$1:');

		let macro = `{{ totalcms.image('${id}', '${options}', '${collection}', '${property}') }}`;
		if (property === "image") {
			macro = `{{ totalcms.image('${id}', '${options}', '${collection}') }}`;

			if (collection === "image") {
				macro = `{{ totalcms.image('${id}', '${options}') }}`;
			}
		}
		const macroContent = document.getElementById("twig-macro");
		macroContent.textContent = macro;
	}

	const filesize = document.getElementById('filesize');

	const bytesToSize = bytes => {
		const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
		if (bytes === 0) return '0 Byte';
		const i = parseInt(Math.floor(Math.log(bytes) / Math.log(1024)));
		return Math.round(bytes / Math.pow(1024, i), 2) + ' ' + sizes[i];
	}

	const getImageSize = () => {
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

	const refreshButton = document.getElementById("refresh-image");
	refreshButton.addEventListener("click", event => {
		event.preventDefault();

		// get the form data and append it to the URL as search params
		const data = getFormData();

		const extension = data.fm ?? originalExtension;

		// Replace the extension in imageUrl.pathname
		imageUrl.pathname = imageUrl.pathname.replace(/\.[^/.]+$/, "." + extension);

		const params = new URLSearchParams(data);
		imageUrl.search = params.toString();

		// update the preview image
		previewImage.src = imageUrl.href;

		getImageSize();
		generateTwigMacro(data);
	});

});
