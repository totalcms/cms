//-----------------------------------------------
// Total CMS Markdown Field
//-----------------------------------------------
class MarkdownField extends TotalField {

    constructor(container, options) {
        super(container, options);

        // get final options... defaultConfig() -> global window.totalcms options -> options from arguments
        this.options = Object.assign({}, this.defaultConfig(), window.totalcms.getConfig("styledtext"), this.options);

		// TODO: implement markdown editor
    }

    schema() {
        return {
            "type"  : "markdown",
            "input" : "textarea"
        };
    }
}

