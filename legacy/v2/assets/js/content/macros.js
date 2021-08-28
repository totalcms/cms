//-----------------------------------------------
// Total CMS Macros
//-----------------------------------------------
//
// Samples:
//      <cms type="text" id="title"></cms>
//      <cms type="image" collection="products" prop="banner" options="w:500,h:500"></cms>
//      <cms type="image" collection="products" prop="screenshots" file="edit-mode1" options="w:500,h:500"></cms>
//      <cms type="text"  collection="products" prop="name"></cms>
//
// Tag Attributes:
//      type: The type of data: text, image, date
//      collection: The collection to query the CMS for
//      id: The ID of the CMS Object that you want to query
//      prop: The property of the object to query for
//      options: options to pass to the API query
//
//-----------------------------------------------

class TotalMacro extends TotalCMS {

    constructor(node) {
        super();

        this.node  = node;
        this.type  = node.getAttribute("type")||"text";

        // Get the macro collection/id/prop
        this.collection = this.getCollection();
        this.id         = this.getObjectId();
        this.prop       = this.getProperty();
        this.file       = this.getFilename();

        // Exit if all 3 of these do not exist
        if (!(this.collection && this.id && this.prop)) return;

        // Can't use this.options because that is used in the Total CMS class
        const settings = node.getAttribute("options")||"";
        this.settings = this.settingsToJson(settings);
    }

    settingsToJson(settings) {
        const json = settings.trim()
            .replace(/(['"])?(\w+)(['"])?\s*:\s*/g,"\"$2\":") // Wrap the key in double quotes
            .replace(/:\s*(['"])?(\S+?)(['"])?\s*(,|$)/g,":\"$2\",") // Warp the value in quotes
            .replace(/,$/,""); // Remove the trailing comma added above
        return JSON.parse(`{${json}}`);
    }

    populateMacro() {
        // Make the API call
        this.fetchCachedAPI(`/collections/${this.collection}/${this.id}`).then(object => {
            // populate based on type
            switch (this.type) {
                case "text":
                    this.populateTextMacro(object);
                    break;

                case "image":
                    this.populateImageMacro(object);
                    break;
            }
        });
    }

    removeMacro() {
        this.node.parentNode.removeChild(this.node);
    }

    populateTextMacro(object) {
        this.node.insertAdjacentHTML("afterend", object[this.prop]);
        this.removeMacro();
    }

    populateImageMacro(object) {
        const image = document.createElement("img");
        const imageWorks = new ImageWorks({
            collection : this.collection,
            id         : this.id,
            property   : this.prop,
            file       : this.file,
        });
        // add the date to the settings so that its appened to the ImageWorks URL
        this.settings.date = object[this.prop]["uploadDate"];
        image.src = imageWorks.buildQuery(this.settings);
        image.setAttribute("alt", object[this.prop]["alt"]);

        this.node.parentNode.insertBefore(image,this.node);

        // this.node.insertAdjacentHTML("afterend", image.innerHTML);
        this.removeMacro();
    }

    getFilename(){
        return this.node.getAttribute("file");
    }

    getProperty(){
        const property = this.locateValue("prop");
        if (property) return property;

        // global scope where type and prop are the same
        return this.type;
    }

    getCollection() {
        // locate value in default locations
        const collection = this.locateValue("collection");
        if (collection) return collection;

        // global scope where type and collection are the same
        return this.type;
    }

    // getID() {
    //     const id = this.locateValue("id");
    //     if (id) return id;

    //     console.warn("Unable to locate ID for macro. Skipping...", this.node);
    //     return null;
    // }

    locateValue(property) {
        // If property is defined in the tag, return that
        const attribute = this.node.getAttribute(property);
        if (attribute) return attribute;

        // Look for the property in the URL parameters
        const param = this.getUrlParameter(property);
        if (param) return param;

        // Look at global preview variable for macros
        if (window.totalPreview && window.totalPreview.hasOwnProperty(property)) {
            return window.totalPreview[property];
        }

        return null;
    }
}

