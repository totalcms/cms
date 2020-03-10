//-----------------------------------------------
// Total CMS Bento Grid
//-----------------------------------------------
class BentoLayout extends TotalLayout {

    constructor(layout) {
        super(...arguments);

        // a set is a group of items defined by a template
        this.sets  = [];
    }

    isNumber(n) {
        //we check isFinite first since it will weed out most of the non-numbers
        //like mixed numbers and strings, which parseFloat happily accepts
        return isFinite(n) && !isNaN(parseFloat(n));
    }

    getNumberRange(stringNumbers) {
        const entries = stringNumbers.trim().replace(/,/g," ").replace(/\s+/g,",").split(",");
        const nums = [];

        for (const entry of entries) {
            if (this.isNumber(entry)) {
                // It’s a number, store it
                nums.push(+entry);
                continue;
            }
            // Must be a range, split it
            const range = entry.split("-");

            //check if what we split are both numbers, else skip
            if (!this.isNumber(range[0]) || !this.isNumber(range[1])) continue;

            // force both to be numbers
            let low = +range[0];
            const high = +range[1];

            // from low, we push up to high
            while (low <= high) {
                nums.push(low++);
            }
        }
        // return numbers sorted
        return nums.sort((a,b)=>a-b);
    }

    buildLayout() {
        const templates = Array.from(document.querySelectorAll(`template[data-for=${this.layout.id}]`));
        this.log.debug("Templates", templates);

        // Create dummy elements
        const dummies = {};
        // Get the dummy templates for each item
        for (const template of templates) {
            const templateItems = this.getNumberRange(template.dataset.items);
            templateItems.map(num => dummies[num] = template.dataset.dummy);
        }
        // insert the dummy templates into the grid (sorted numberically)
        for (const key of Object.keys(dummies).sort((a,b)=>a-b)) {
            this.processTemplate(key, dummies[key], this.layout);
        }

        for (const template of templates) {
            let query = {};

            if (template.getAttribute("data-static") === null) {
                if (template.dataset.query) {
                    // Get the query for the template
                    query = JSON.parse(template.dataset.query);
                    // Use the main query if template scope is set to inherit
                    if (query.scope === "inherit") query = JSON.parse(this.layout.dataset.query);
                } else {
                    query = JSON.parse(this.layout.dataset.query);
                }
                this.log.debug("Bento Query", query);
            }

            const set = new BentoTemplate({
                items      : this.getNumberRange(template.dataset.items),
                query      : query,
                template   : template,
                layout     : this.layout,
            });

            this.log.debug("populateTemplate for "+template.dataset.items);
            set.populateTemplate().then(() => {
                this.log.debug("populateTemplate complete for "+template.dataset.items);
            });
            this.sets.push(set);
        }
    }
}
