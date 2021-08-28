//-----------------------------------------------
// Total CMS Permalink Field Automation
//-----------------------------------------------
class Permalink extends Fieldset {

    constructor(container, options) {
        super(container, options);

        // Define option defaults
        const defaults = {
            autogen: "title",
        };
        this.options = Object.assign({}, this.options, defaults, options);

        this.titleNode = this.form.find(`[name=${this.options.autogen}]`);

        if (this.input && this.titleNode) {
            this.onChangeEvents();
        }
        else {
            console.error("Unable to find permalink and title fields");
        }

        this.id = this.permalinkValue();
    }

    onFieldChange(field, callback) {
        field.addEventListener("change", event => {
            if (!this.input.classList.contains("locked")) {
                this.input.value = callback();
                this.checkPermalink();
            }
        });
    }

    onChangeEvents() {
        if (this.input.classList.contains("mustache")) {
            // process mustache tempalte for ID
            const fields = this.form.findAll("input,textarea,select");
            fields.forEach(field => this.onFieldChange(field, () => this.templateTitle()));
        }
        else {
            // Populate Permalink based on title
            this.onFieldChange(this.titleNode, () => this.urlifyTitle(this.titleNode.value));
        }

        // Check Permalink changes directly
        this.input.addEventListener("change", event => {
            this.input.classList.add("locked");
            this.checkPermalink();
        });
    }

    templateTitle(){
        const title = Mustache.render(this.input.dataset.template, this.form.generateData());
        return this.urlifyTitle(title);
    }

    urlifyTitle(title){
        return slugify(title).toLowerCase();
        // return title.trim().replace(/\s+/g,"-").replace(/[^a-zA-Z0-9\u00C0-\u017F-]/ig,"").toLowerCase();
    }

    permalinkExists() {
        this.log.info("Permalink Exists");
        this.input.classList.remove("saving", "success");
        this.input.classList.add("error");
    }

    permalinkAvailable() {
        this.log.info("Permalink Available");
        this.input.classList.remove("saving", "error");
        this.input.classList.add("success");
    }

    processCheck(response) {
        this.log.debug("Permalink Check", response);
        if (response === true) {
            this.permalinkExists();
        }
        else {
            this.permalinkAvailable();
        }
        const event = new Event("permalinkChange");
        this.input.dispatchEvent(event);
    }

    permalinkValue(){
        let permalinkValue = this.input.value;

        // No blank permalinks
        if (permalinkValue.length === 0) {
            console.warn("Permalink cannot be empty");
            return false;
        }

        // Add Suffix to the permalink if configured to
        if (this.input.dataset.suffix) {
            permalinkValue = `${permalinkValue}-${this.input.dataset.suffix}`;
        }

        // if the value has changed, then update it and trigger an event
        if (this.input.value !== permalinkValue) {
            this.input.value = permalinkValue;
        }

        this.id = permalinkValue;
        return this.id;
    }

    checkPermalink(){
        this.permalinkValue();
        // Check that the permalink exists on the server or not
        this.api.fetchAPI(`/collections/${this.form.collection}/${this.id}/exists`)
            .then(response => this.processCheck(response));
    }
}