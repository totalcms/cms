//-----------------------------------------------
// Total CMS Bento Grid Template
//-----------------------------------------------
class BentoTemplate extends TotalTemplate {

    constructor(options) {
        super(...arguments);

        // Define option defaults
        const defaults = {
            items: [1],
        };
        this.options = Object.assign({}, this.options, defaults, options);

        this.items = this.options.items;
        this.count = this.items.length;
    }

    replaceItemInGrid(newItem) {
        // This checks to see if the item already exists and replaces it
        const newNode  = newItem.querySelector(".grid-item");
        const nodeId   = newNode.getAttribute("id");
        const existing = document.getElementById(nodeId);

        if (existing) {
            this.layout.replaceChild(newItem, existing);
            const node = document.getElementById(nodeId);
            this.imageLoadTransition(node);
            return true;
        }
        return false;
    }

    insertItemInOrder(newItem, itemNumber) {
        // This item gets inserted into the layout at the proper ordered location based on the item number
        const layoutItems = Array.from(this.layout.getElementsByClassName("grid-item"));

        if (layoutItems.length > 0) {
            const newNode = newItem.querySelector(".grid-item");
            const nodeId  = newNode.getAttribute("id");

            for (const layoutItem of layoutItems) {
                if (layoutItem.dataset.item > itemNumber) {
                    this.layout.insertBefore(newItem, layoutItem);
                    const node = document.getElementById(nodeId);
                    this.imageLoadTransition(node);
                    return;
                }
            }
        }

        this.layout.appendChild(newItem);
        this.imageLoadTransition(this.layout.children[this.layout.children.length-1]);
    }

    insertItemIntoGrid(object, itemNumber) {
        const newItem = this.processTemplate(object, this.template.innerHTML);

        // process all imageworks images
        this.processImageWorks(newItem);
        this.processMacros(newItem);

        // Insert this item into the layout
        if (!this.replaceItemInGrid(newItem)) {
            this.insertItemInOrder(newItem, itemNumber);
        }
    }

    populateTemplate() {
        // this method could be refactored better so that the fetchData calls a callback
        if (Object.keys(this.options.query).length === 0) {
            // if there is no query, just insert the item
            this.insertItemIntoGrid({}, this.items[0]);
            return new Promise((resolve,reject) => resolve(true));
        }

        return this.query.fetchQueryData().then(objects => {
            this.items.forEach(item => {
                for (const object of objects) {
                    // shift the fist element to the back so that the next iteration looks at the next object
                    objects.push(objects.shift());

                    // make sure that this does not already exist in the layout
                    if (!this.objectExistsInLayout(object)) {
                        // Set the item number so that its used in the template
                        object.item = item;
                        // process the template
                        this.insertItemIntoGrid(object, item);
                        // Go to the next item
                        break;
                    }
                }
            });
        });
    }
}