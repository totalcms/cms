/**
 * Total CMS Macro Builder
 *
 * Build a Total CMS macro
 *
 * <button class="cms-clip-button" data-clip="#nodeid">Copy to Clipboard</button>
 *
**/
export default class MacroBuilder {

	static imagePath(data)
	{
		return this.buildMacro("imagePath", "image", "image", data);
	}

	static galleryPath(data)
	{
		return this.buildSetMacro("galleryPath", "gallery", "gallery", data);
	}

	static download(data)
	{
		delete data.filename;
		return this.buildMacro("download", "file", "file", data);
	}

	static depotDownload(data)
	{
		data.name = data.filename;
		return this.buildSetMacro("depotDownload", "depot", "depot", data);
	}

	static buildMacro(method, defaultCollection, defaultProperty, data)
	{
		const id         = data.id;
		const collection = data.collection;
		const property   = data.property;

		const options = this.buildOptions(data);

		let macro = `{{ cms.${method}('${id}'${options}, {collection:'${collection}',property:'${property}'}) }}`;
		if (property === defaultProperty) {
			macro = `{{ cms.${method}('${id}'${options}, {collection:'${collection}'}) }}`;

			if (collection === defaultCollection) {
				macro = `{{ cms.${method}('${id}'${options}) }}`;
			}
		}

		return macro;
	}

	// This is for macros that need to access a specific item in a set like depot and gallery
	static buildSetMacro(method, defaultCollection, defaultProperty, data)
	{
		if (!data.name) {
			console.warn("Name is required for this macro", method, defaultCollection, defaultProperty, data);
			return '';
		}

		const id         = data.id;
		const collection = data.collection;
		const property   = data.property;
		const name       = data.name;

		const options = this.buildOptions(data);

		let macro = `{{ cms.${method}('${id}', '${name}'${options}, {collection:'${collection}',property:'${property}'}) }}`;
		if (property === defaultProperty) {
			macro = `{{ cms.${method}('${id}', '${name}'${options}, {collection:'${collection}'}) }}`;

			if (collection === defaultCollection) {
				macro = `{{ cms.${method}('${id}', '${name}'${options}) }}`;
			}
		}

		return macro;
	}

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
}

// const generateTwigMacro = (data) => {
// 	const macroContent = document.getElementById("twig-macro");
// 	macroContent.textContent = MacroBuilder.imageMacro(data);
// }
