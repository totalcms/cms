"use strict";
/**
 * Total CMS Box Gallery
 *
 * This manages a CMS Box Gallery
 *
 */
class BoxGallery {

    constructor(node,options={}) {
        this.node = node;

        const defaults = {
            selector : ".boxgallery-item"
        };
        this.options = Object.assign({}, defaults, options);

        this.initLightGallery();
    }

    // init lightgallery - jquery :o(
    // Move to vanilla js version https://sachinchoolur.github.io/lightgallery.js/
    initLightGallery() {
        $(this.node).lightGallery({
            selector  : this.options.selector,
            thumbnail : true
        });
    }
}
