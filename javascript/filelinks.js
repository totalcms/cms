import Details from "./totalform/details";
import Dialog from "./totalform/dialog";

if (window.self !== window.top) {
	// The page is in an iframe
	document.body.classList.add('in-iframe');
}

document.addEventListener("DOMContentLoaded", event => {

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
			options = `, ${options}`;
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
	generateTwigMacro(getFormData());
});
