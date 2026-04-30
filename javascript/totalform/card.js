import TotalField from "./totalfield";

//-----------------------------------------------
// Total CMS Card Field
//
// A card is a single-instance deck — one nested object whose shape comes from
// another schema (referenced via `schemaref`). Sub-fields render inline in the
// parent form. On save, the card's value is the nested object built from those
// sub-fields, keyed by their property names.
//-----------------------------------------------
export default class CardField extends TotalField {

    constructor(container, settings) {
        super(container, settings);

        this.schemaref = container.dataset.schemaref || container.dataset.deckref || '';
    }

    //-------------------------
    // Locate sub-fields scoped to this card. Sub-fields rendered inside the
    // card's container are .form-field elements; we collect their TotalField
    // instances so we can read their values.
    //-------------------------
    subFields() {
        const fields = [];
        const containers = this.container.querySelectorAll('.form-field');
        containers.forEach(el => {
            if (el === this.container) return;
            if (el.totalfield) fields.push(el.totalfield);
        });
        return fields;
    }

    //-------------------------
    // Card value is the nested object of sub-field values, keyed by property name.
    // Empty string values are kept (consumers can decide whether to omit them).
    //-------------------------
    getValue() {
        const value = {};
        this.subFields().forEach(sub => {
            if (!sub.property) return;
            value[sub.property] = sub.getValue();
        });
        return value;
    }

    //-------------------------
    // Setting a card value populates each sub-field from the given object.
    //-------------------------
    setValue(value) {
        if (!value || typeof value !== 'object') return;
        this.subFields().forEach(sub => {
            if (!sub.property || !(sub.property in value)) return;
            sub.setValue(value[sub.property]);
        });
        this.changed();
    }

    schema() {
        return {
            type: "card",
            fieldset: this.type,
            schemaref: this.schemaref
        };
    }
}
