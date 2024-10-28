/**
 * Total CMS Macro Builder
 *
 * Build a Total CMS macro
 *
 * <button class="cms-clip-button" data-clip="#nodeid">Copy to Clipboard</button>
 *
**/
export default class MacroBuilder {

	static buildOptions(data)
	{
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

		return options;
	}

	static imageMacro(data)
	{
		if (data.name) return this.galleryPathMacro(data);
		return this.imagePathMacro(data);
	}

	static imagePathMacro(data)
	{
		if (data.name) return this.galleryPathMacro(data);

		const id         = data.id;
		const collection = data.collection;
		const property   = data.property;

		const options = this.buildOptions(data);

		let macro = `{{ cms.imagePath('${id}'${options}, {collection:'${collection}',property:'${property}'}) }}`;
		if (property === "image") {
			macro = `{{ cms.imagePath('${id}'${options}, {collection:'${collection}'}) }}`;

			if (collection === "image") {
				macro = `{{ cms.imagePath('${id}'${options}) }}`;
			}
		}

		return macro;
	}

	static galleryPathMacro(data)
	{
		const id         = data.id;
		const collection = data.collection;
		const property   = data.property;
		const name       = data.name;

		const options = this.buildOptions(data);

		let macro = `{{ cms.galleryPath('${id}', '${name}'${options}, {collection:'${collection}',property:'${property}'}) }}`;
		if (property === "gallery") {
			macro = `{{ cms.galleryPath('${id}', '${name}'${options}, {collection:'${collection}'}) }}`;

			if (collection === "gallery") {
				macro = `{{ cms.galleryPath('${id}', '${name}'${options}) }}`;
			}
		}

		return macro;
	}

	static fileDownloadMacro(data)
	{
		const id         = data.id;
		const collection = data.collection;
		const property   = data.property;

		const options = this.buildOptions(data);

		let macro = `{{ cms.download('${id}'${options}, {collection:'${collection}',property:'${property}'}) }}`;
		if (property === "file") {
			macro = `{{ cms.download('${id}'${options}, {collection:'${collection}'}) }}`;

			if (collection === "file") {
				macro = `{{ cms.download('${id}'${options}) }}`;
			}
		}

		return macro;
	}

}

// document.addEventListener("DOMContentLoaded", event => {

// 	generateTwigMacro(getFormData());
// });
