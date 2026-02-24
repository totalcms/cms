//-----------------------------------------------
// Total CMS Markdown Field
//-----------------------------------------------
class MarkdownField extends TotalField {

    constructor(container, settings) {
        super(container, settings);

        // get final settings... defaultConfig() -> global window.totalcms settings -> settings from arguments
        this.settings = Object.assign({}, this.defaultConfig(), window.totalcms.getConfig("styledtext"), this.settings);

		// TODO: implement markdown editor
    }

    schema() {
        return {
            "type"  : "markdown",
            "input" : "textarea"
        };
    }
}

