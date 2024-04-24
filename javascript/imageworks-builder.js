import Details from "./totalform/details";

document.addEventListener("DOMContentLoaded", event => {
	// const form = Array.from(document.querySelector("form.totalform"));
	// const totalform = new TotalForm(form);

	const previewImage = document.querySelector(".image-preview img");
	const imageUrl = new URL(previewImage.src);

	const details = Array.from(document.querySelectorAll("details"));
	for (const detail of details) {
		const accordion = new Details(detail, {openFirst:false});
	}

	const getFormData = () => {
		const form     = document.querySelector("form");
		const formData = Object.fromEntries(new FormData(form));

		const data = Array.from(formData).reduce((acc, [key, value]) => {
			if (value !== '') {
				acc[key] = value;
			}
			return acc;
		}, {});
		return data;
	};

	const button = document.querySelector("form button");
	button.addEventListener("click", event => {
		event.preventDefault();

		// get the form data and append it to the URL as search params
		const data = getFormData();

		if (Object.keys(data).length > 0) {
			const params = new URLSearchParams(data);
			imageUrl.search = params.toString();

			// update the preview image
			previewImage.src = imageUrl.href;
		}
	});
});
