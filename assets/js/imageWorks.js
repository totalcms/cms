"use strict";
/**
 * Total CMS ImageWorks API
 *
 * This class makes it easy to create ImageWorks API calls
 * requires window.totalcms
 *
 */
class ImageWorks extends TotalCMS {

    constructor(options) {
        super(options);

        const defaults = {
            collection : "defaultCollection",
            id         : "defaultId",
            property   : "defaultImage",
            file       : null,
            date       : "",
        };
        this.options = Object.assign({}, defaults, window.totalcms.options, options);

        // This is only for image. Need to update for gallery
        if (this.options.file === null) this.options.file = this.options.property;

        // create the logger
        this.log = new Logger({loglevel:this.options.loglevel, group:"imageworks"});
    }

    // Build an imageWorks API query URL
    buildQuery(params) {
        let api = `/imageworks/${this.options.collection}/${this.options.id}/${this.options.property}/${this.options.file}`;
        // add the image format to the image name so that CF can cache. Auto not supported
        if (params.format && params.format !== "auto") api += `.${params.format}`;
        // append timestamep to URL for cache busting!
        if (this.options.date) params.date = this.options.date;
        const query = this.buildUrlQuery(api,params);
        this.log.debug("ImageWorks", query);
        return query;
    }
}

/*
 * Image Works Query Parameters
 *
 * /imageworks/myobject/two-images/image/image.jpg?w=300&h=300&fit=crop-43-50&sharp=5&border=10,5000,overlay
 *
 *
 * Orientation          or	        Rotates the image. (auto, 0, 90, 180 or 270, default: auto - uses exif)
 * Flip	                flip	    Flip the image.	(v, h and both)
 * Crop	                crop	    Crops the image to specific dimensions. (format: width,height,x,y)
 * Width	            w	        Sets the width of the image, in pixels.
 * Height	            h	        Sets the height of the image, in pixels.
 * Fit	                fit	        Sets how the image is fitted to its target dimensions. (contain, max, fill, stretch, crop-top-left, crop-top, crop-top-right, crop-left, crop-center, crop-right, crop-bottom-left, crop-bottom or crop-bottom-right)
 * pixel ratio          dpr	        Multiples the overall image size. (value: 1-8, default: 1)
 * Brightness	        bri	        Adjusts the image brightness. (-100 to 100, default: 0)
 * Contrast	            con	        Adjusts the image contrast. (-100 to 100, default: 0)
 * Gamma	            gam	        Adjusts the image gamma. (0.1 to 9.99)
 * Sharpen	            sharp	    Sharpen the image. (0 to 100)
 * Blur	                blur	    Adds a blur effect to the image. (0 to 100)
 * Pixelate	            pixel	    Applies a pixelation effect to the image. (0 to 1000)
 * Filter	            filt	    Applies a filter effect to the image. (greyscale or sepia)
 * Watermark Path	    mark	    Adds a watermark to the image. (CMS ID of watermark image)
 * Watermark Width	    markw	    Sets the width of the watermark.
 * Watermark Height	    markh	    Sets the height of the watermark.
 * Watermark X-offset	markx	    Sets the watermark distance from left/right edges.
 * Watermark Y-offset	marky	    Sets the watermark distance from top/bottom edges.
 * Watermark Fit	    markfit	    Same as the Fit setting above.
 * Watermark Padding	markpad	    Sets the watermark distance from the edges.
 * Watermark Position	markpos	    Sets where the watermark is positioned.
 * Watermark Alpha	    markalpha	Sets the watermark opacity. (0 to 100)
 * Background	        bg	        Sets the background color of the image. (Hex color value: cccccc )
 * Border	            border	    Add a border to the image. (width,color,method) methods: overlay, shrink, expand
 * Quality	            q	        Defines the quality of the image. (0 to 100)
 * Format	            fm	        Encodes the image to a specific format. (jpg, pjpg (progressive jpeg), png or gif)
 *
 */
