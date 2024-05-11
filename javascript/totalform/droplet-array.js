//-----------------------------------------------
// Total CMS Array Droplet for Gallery and Depot
//-----------------------------------------------

// https://sortablejs.github.io/Sortable/

class ArrayDroplet extends Droplet {

    constructor(container, options) {
        super(...arguments);
        this.options.gallery = true;
    }

    schema() {
        return {
            type     : "array",
            fieldset : this.options.type
        };
    }

    getValue() {
        return this.input.value.length > 0 ? JSON.parse(this.input.value) : [];
    }

    setValue(gallery) {
        if (gallery === null || gallery.length === 0) {
            console.warn("No gallery images found", gallery);
            return;
        }

        // Set the value to the image object
        this.input.value = JSON.stringify(gallery);

        // Get all of the data needed to build the imageWorks query
        const rules      = JSON.parse(this.container.dataset.imageworks);
        const preview    = this.container.querySelectorAll(".total-preview").item(0);

        // Crop the images to fit the size
        rules.fit = "crop";

        for (const image of gallery) {
            // Create Image Works API for the preview image
            const imageWorks = new ImageWorks({
                collection : this.form.collection,
                id         : this.form.id,
                property   : this.name,
                file       : image.filename,
                date       : image.uploadDate
            });
            const imageQuery = imageWorks.buildQuery(rules);
            this.log.debug("image query",imageQuery);
            this.api.fetchCachedAPI("/templates/admin/image").then(json => {
                this.api.processTemplate({"image":imageQuery}, json.template, preview);
            });
        }
    }
}
