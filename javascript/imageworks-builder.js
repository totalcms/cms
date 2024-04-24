import Details from "./totalform/details";
import Dialog from "./totalform/dialog";

if (window.self !== window.top) {
	// The page is in an iframe
	document.body.classList.add('in-iframe');
}

document.addEventListener("DOMContentLoaded", event => {
	// const form = Array.from(document.querySelector("form.totalform"));
	// const totalform = new TotalForm(form);

	const previewImage = document.querySelector(".image-preview img");
	const imageUrl = new URL(previewImage.src);

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

	const button = document.querySelector("button");
	button.addEventListener("click", event => {
		event.preventDefault();

		// get the form data and append it to the URL as search params
		const data = getFormData();

		const params = new URLSearchParams(data);
		imageUrl.search = params.toString();

		// update the preview image
		previewImage.src = imageUrl.href;

		// TODO: create a twig statement to display under the image.
	});
});
