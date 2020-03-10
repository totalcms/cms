//-----------------------------------------------
// Total CMS Select Field
//-----------------------------------------------
class SelectField extends Fieldset {

    constructor(container, options) {
        super(...arguments);

        this.select    = container.querySelector("select");
        this.templates = Array.from(this.select.querySelectorAll("template"));

        if (this.templates) this.processTemplates();
    }

    sort() {
        const tmpAry = new Array();
        for (let i=0;i<this.select.options.length;i++) {
            tmpAry[i] = new Array();
            tmpAry[i][0] = this.select.options[i].text;
            tmpAry[i][1] = this.select.options[i].value;
        }
        tmpAry.sort();
        while (this.select.options.length > 0) {
            this.select.options[0] = null;
        }
        for (let i=0;i<tmpAry.length;i++) {
            const op = new Option(tmpAry[i][0], tmpAry[i][1]);
            this.select.options[i] = op;
        }
        return;
    }

    processTemplates() {
        this.templates.forEach(template => {
            const collection = template.dataset.collection;
            if (!collection) {
                console.warn("No collection defined for select template");
                return;
            }
            this.api.fetchAPI(`/collections/${collection}`).then(data => {
                data.map(object => this.api.processTemplate(object, template.innerHTML, this.select));
                this.sort();
            });
        });
    }

    setValue(value) {
        this.input.value = value;
        // Select Options
        const options = Array.from(this.input.getElementsByTagName("option"));
        for (const option of options) {
            if (option.value.trim() === value.trim()) {
                option.selected = true;
                break;
            }
        }
        this.changed();
    }

    schema() {
        return {
            "type":"string",
            "fieldset":"select"
        };
    }
}
