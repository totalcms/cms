const slugify = require('slugify')

export default class SlugifyInput {

    constructor(input) {
        this.input = input;
		this.slugify();
		this.input.addEventListener("change", e => this.slugify());
	}

    slugify() {
		const value = this.input.value.replace('@', '-at-').replace('.', '-');
        this.input.value = slugify(value, {
			replacement : '-', // replace spaces with replacement character, defaults to `-`
			remove      : /[*+~.()'"!:@]/g, // remove characters that match regex, defaults to `undefined`
			lower       : true, // convert to lower case, defaults to `false`
			strict      : false, // strip special characters except replacement, defaults to `false`
			trim        : true, // trim leading and trailing replacement chars, defaults to `true`
			// locale      : 'vi', // language code of the locale to use
		});
    }

}
