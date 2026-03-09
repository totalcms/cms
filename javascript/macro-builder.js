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
		return this.buildMacro("media", "imagePath", "image", "image", data);
	}

	static galleryPath(data)
	{
		return this.buildSetMacro("media", "galleryPath", "gallery", "gallery", data);
	}

	static download(data)
	{
		delete data.filename;
		return this.buildMacro("media", "download", "file", "file", data);
	}

	static depotDownload(data)
	{
		delete data.filename;
		return this.buildSetMacro("media", "depotDownload", "depot", "depot", data);
	}

	static renderImage(data)
	{
		return this.buildMacro("render", "image", "image", "image", data);
	}

	static renderGalleryImage(data)
	{
		return this.buildSetMacro("render", "galleryImage", "gallery", "gallery", data);
	}

	static dataImage(data)
	{
		return this.buildDataMacro("image", "image", "image", data);
	}

	static dataGallery(data)
	{
		return this.buildDataMacro("gallery", "gallery", "gallery", data);
	}

	static dataFile(data)
	{
		return this.buildDataMacro("file", "file", "file", data);
	}

	static dataDepot(data)
	{
		return this.buildDataMacro("depot", "depot", "depot", data);
	}

	static buildMacro(namespace, method, defaultCollection, defaultProperty, data)
	{
		const id         = data.id;
		const collection = data.collection;
		const property   = data.property;

		const options = this.buildOptions(data);
		const optionsOrEmpty = options || ', {}';

		let macro = `{{ cms.${namespace}.${method}('${id}'${optionsOrEmpty}, {collection:'${collection}',property:'${property}'}) }}`;
		if (property === defaultProperty) {
			macro = `{{ cms.${namespace}.${method}('${id}'${optionsOrEmpty}, {collection:'${collection}'}) }}`;

			if (collection === defaultCollection) {
				macro = `{{ cms.${namespace}.${method}('${id}'${options}) }}`;
			}
		}

		return macro;
	}

	// This is for macros that need to access a specific item in a set like depot and gallery
	static buildSetMacro(namespace, method, defaultCollection, defaultProperty, data)
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
		const optionsOrEmpty = options || ', {}';

		let macro = `{{ cms.${namespace}.${method}('${id}', '${name}'${optionsOrEmpty}, {collection:'${collection}',property:'${property}'}) }}`;
		if (property === defaultProperty) {
			macro = `{{ cms.${namespace}.${method}('${id}', '${name}'${optionsOrEmpty}, {collection:'${collection}'}) }}`;

			if (collection === defaultCollection) {
				macro = `{{ cms.${namespace}.${method}('${id}', '${name}'${options}) }}`;
			}
		}

		return macro;
	}

	// Build a simple data accessor macro (no imageworks options)
	static buildDataMacro(method, defaultCollection, defaultProperty, data)
	{
		const id         = data.id;
		const collection = data.collection;
		const property   = data.property;

		let macro = `{{ cms.data.${method}('${id}', {collection:'${collection}',property:'${property}'}) }}`;
		if (property === defaultProperty) {
			macro = `{{ cms.data.${method}('${id}', {collection:'${collection}'}) }}`;

			if (collection === defaultCollection) {
				macro = `{{ cms.data.${method}('${id}') }}`;
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
			options = options.replace(/\"/g, `'`); // swap double quotes for single quotes
			options = `, ${options}`;
		}

		return options;
	}
}
