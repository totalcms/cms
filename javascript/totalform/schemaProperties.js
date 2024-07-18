import PropertiesField from "./properties";

//-----------------------------------------------
// Total CMS Properties
//-----------------------------------------------
export default class SchemaPropertiesField extends PropertiesField {

    constructor(container, options) {
		options.fieldClass = "schema-field";
        super(container, options);
    }

	schema() {
        return {
            type     : "schemaProperties",
            fieldset : this.type
        };
    }
}
