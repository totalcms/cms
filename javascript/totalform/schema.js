//-----------------------------------------------
// Total CMS Form Schema
//-----------------------------------------------
export default class Schema {

    constructor(form) {
        // Create global element references
        this.form = form;
        this.collection = this.form.collection;

        this.baseapi = `/collections/${this.collection}`;

        this.type       = "object";
        this.index      = this.namesByAttribute("data-index");
        this.required   = this.namesByAttribute("data-required");
        this.properties = this.processFieldsets();

        // Compare server and local schema
        // This needs to only happen on custom objects
        this.getServerSchema().then(serverSchema => this.compareSchema(serverSchema));
    }

    compareSchema(serverSchema) {
        // Don't save schema on preview
        if (window.localpreview === true) return;

        // Compare the serverSummary with the localSummary
        if (JSON.stringify(this.generateLocalSchema()) !== JSON.stringify(serverSchema)) {
            this.form.log.info("Need to save local schema to server");
            this.saveSchema();
            // Auto rebuild the index data json?
        }
    }

    getServerSchema() {
        // AJAX call to get the schema
        return this.form.api.fetchCachedAPI(`${this.baseapi}/schema`);
    }

    namesByAttribute(attr) {
        // Find all inputs with attribute
        const fields = this.form.fieldsets.filter(field => field.getAttribute(attr) !== null);
        // extract the name attributes
        return fields.map(field => field.dataset.name);
    }

    processFieldsets() {
        const properties = {};
        for (const name in this.form.fieldObjects) {
            properties[name] = this.form.fieldObjects[name].schema();
        }
        return properties;
    }

    generateLocalSchema() {
        return {
            "index"      : this.index,
            "required"   : this.required,
            "type"       : this.type,
            "title"      : this.collection,
            "properties" : this.properties
        };
    }

    saveSchema() {
        // Save Schema data
        this.form.api.postAPI(`${this.baseapi}/schema`, this.generateLocalSchema());
    }
}
