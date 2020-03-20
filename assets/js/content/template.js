//-----------------------------------------------
// Total CMS Layout Template
//-----------------------------------------------
class TotalTemplate extends TotalCMS {

    constructor(options) {
        super(...arguments);

        // Define option defaults
        const defaults = {
            query      : {},
            template   : null,
            layout     : null,
        };
        this.options = Object.assign({}, this.options, defaults, options);

        this.query       = new TotalQuery(this.options.query);
        this.collection  = this.query.collection || null;
        this.template    = this.options.template;
        this.layout      = this.options.layout;
        this.static      = Object.keys(this.options.query).length === 0;

        // Disable search for property level queries
        this.search = this.query.scope !== "property" ? new LunrSearch(this.options) : null;

        if (!this.collection) {
            console.warn("Unable to find collection name inside query");
        }
    }

    processMacros(node) {
        const macros = Array.from(node.querySelectorAll("cms"));
        macros.forEach(macro => new TotalMacro(macro).populateMacro());
    }

    processImageWorks(node) {
        const images = Array.from(node.querySelectorAll("img.imageworks"));
        for (const image of images) {
            const imageWorks = new ImageWorks({
                collection : this.collection,
                id         : image.dataset.id,
                property   : image.dataset.property,
                file       : image.dataset.file||null
            });
            const rules = JSON.parse(image.dataset.imageworks);
            image.src   = imageWorks.buildQuery(rules);
        }
    }

    processItemBoxGallery(node) {
        // Launch gallery specific to this node
        const galleries = Array.from(node.querySelectorAll(".boxgallery"));
        galleries.forEach(gallery => new BoxGallery(gallery));
    }

    enhanceItem(node) {
        // enhance layout with more dynamic data
        this.processImageWorks(node);
        this.processMacros(node);
        this.processItemBoxGallery(node);
    }

    processLayoutBoxGallery() {
        // Launch a gallery where this node is one piece of the layout gallery
        const items = Array.from(this.layout.querySelectorAll(".boxgallery-item"));
        if (items.length > 0) new BoxGallery(this.layout);
    }

    enhanceLayout() {
        // enhance layout with more dynamic data
        this.processLayoutBoxGallery();
    }

    insertIntoLayout(object) {
        // if the object already exists in the layout, append it
        // appending it maintains the order of the data
        // Insert all images though
        if (!this.isImageObject(object) && this.objectExistsInLayout(object)) {
            // item = this.objectNode(object);
            return;
        }

        // New Item
        const item = this.processTemplate(object, this.template.innerHTML);

        // enhnace layout item
        this.enhanceItem(item);

        // Insert this item into the layout
        this.layout.appendChild(item);
        // Add image load transition to the item just added
        this.imageLoadTransition(this.layout.children[this.layout.children.length-1]);
    }

    populateTemplate(page, options={}) {
        // if there is no query, just insert the item
        // only insert when page 1 for static content
        if (this.static) {
            if (page === 1) this.insertIntoLayout({});
            return new Promise((resolve,reject) => resolve(true));
        }

        const queryPromise = this.query.fetchQueryData(page,options);
        queryPromise.then(data => {
            data.forEach(object => this.insertIntoLayout(object));
            // build the search index
            if (this.search) this.search.index();
            // enhnace layout after objects have been added
            this.enhanceLayout();
        });
        return queryPromise;
    }

    objectExistsInLayout(object) {
        return (this.objectNode(object) !== null);
    }

    isImageObject(object) {
        return typeof object.exif === "object";
    }

    objectNode(object) {
        return this.layout.querySelector(`[data-id="${object.id}"]`);
    }

    getExistingObjects() {
        const objects = [];
        const items = Array.from(this.layout.children);
        items.forEach(item => {
            if (item.dataset.id) objects.push(item.dataset.id);
        });
        return objects;
    }

    removeLayoutObject(object) {
        const node = this.objectNode(object);
        if (node) this.layout.removeChild(node);
    }

    removeStaticTemplates() {
        const templates = Array.from(this.layout.getElementsByClassName("grid-item-static"));
        templates.forEach(template => this.layout.removeChild(template));
    }

    searchLayout(query) {
        if (!this.search) {
            console.warn("Unable to search layout. No Search defined");
            return;
        }

        // remove static templates from search results
        if (this.static) return this.removeStaticTemplates();

        // remove all children
        if (!query) return this.clearSearch();

        // this.query.fetchQueryData() could be used so that search indexes all items not just initial items

        // Get existing layout objects
        this.query.fetchQueryData().then(layoutObjects => {
            // Run search and get the objects to display
            const results = this.search.search(query);
            // get the the actual obejcts from the search results
            const objects = results.map(result => layoutObjects.find(obj => obj.id === result.ref)).filter(object => typeof object === "object");

            // hide objects that are not in search results
            const remove = this.query.queryData.filter(lo => objects.find(ro => ro.id === lo.id) === undefined);
            remove.forEach(object => this.removeLayoutObject(object));

            // add all objects to results
            objects.forEach(object => this.insertIntoLayout(object));
        });
    }
}