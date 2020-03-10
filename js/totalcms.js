// bling.js does not work well with jQuery on the page
// window.$ = document.querySelectorAll.bind(document);
// Node.prototype.on = window.on = function (name, fn) {
//     this.addEventListener(name, fn);
// };
// NodeList.prototype.__proto__ = Array.prototype;
// NodeList.prototype.on = NodeList.prototype.addEventListener = function (name, fn) {
//     this.forEach(function (elem, i) {
//         elem.on(name, fn);
//     });
// };

"use strict";
/**
 * Total CMS API constructor
 *
 * This class serves as a good base class for other Total CMS
 * object classes to extend. It handles all of the standard interfaces for
 * communicating with Total CMS.
 *
 * Create global instance that will house global settings
 *
 * <pre>
 * const totalcms = new TotalCMS({
 *       passport: "topsecret",
 *       uri: "http://localhost:8000/api.php",
 * });
 * </pre>
 *
**/
class TotalCMS {

    // Creates an instance of TotalCMS.
    constructor(options={}) {
        // Create global element references
        this.collection = null;

        const defaults = {
            passport        : null,
            cache           : true,
            cors            : false,
            loglevel        : 1,
            locale          : "en",
            expireStorage   : 30,
            rgbOffset       : 50,
            hslOffset       : 15,
            localizeStrings : {},
            config          : {},
            uri             : "/rw_common/plugins/stacks/dynamics/api.php"
        };
        // get the global options and merge with defaults/arguments
        const globals = typeof window.totalcms === "object" ? window.totalcms.options : {};
        this.options  = Object.assign({}, defaults, globals, options);

        this.cache = this.options.cache;

        // Configuration options that can be set for various CMS components
        this.config = this.options.config||{};

        // The amount of time to expire localstorage in milliseconds
        this.expireStorage = this.options.expireStorage*1000*60*60*24;
        this.expireKey = "expire";

        this.localStorage = Storages.localStorage;
        this.sessionStorage = Storages.sessionStorage;

        // create the logger
        this.log = new Logger({loglevel:this.options.loglevel, group:"totalcms"});
    }

    // Set, Get, Update config values
    setConfig(key, value) {
        this.config[key] = value;
    }

    getConfig(key) {
        return this.config[key]||{};
    }

    updateConfig(key, value) {
        this.config[key] = Object.assign({}, this.config[key], value);
    }

    disableCache() {
        this.cache = false;
    }

    // AJAX Post to the Total CMS API
    postAPI(api, data, method = "POST") {
        // If the POST API sets new data, we should delete form storage it if it exists
        this.localStorage.remove(api);
        this.localStorage.remove(this.expireKey+api);
        this.sessionStorage.remove(api);

        this.log.debug(`postAPI ${this.options.uri+api}`,data);

        return fetch(this.options.uri+api, {
            method: method,
            mode: this.options.cors ? "cors" : "same-origin",
            headers: new Headers({
                "Content-Type":"application/json"
            }),
            body: JSON.stringify(data)
        }).then(response => {
            if (!response.ok) {
                response.json().then(json => console.error("postAPI Error",json));
                throw Error(response.statusText);
            }
            return response.json();
        });
        // .catch(error => {
        //     console.error("POST API Request Failed", error);
        // });
    }

    // Get data from cache else do an AJAX GET from the Total CMS API
    fetchCachedAPI(api) {
        this.log.debug("fetchCachedAPI:"+api);
        // if the cache exists and its not expired, use that
        this.log.debug("localstorage expire:"+this.localStorage.get(this.expireKey+api));
        this.log.debug("now:"+Date.now());
        if (this.cache && this.localStorage.isSet(api) && this.localStorage.get(this.expireKey+api) > Date.now()) {
            this.log.debug("Using localstorage. returning promise");

            return new Promise((resolve, reject) => {
                // If this query was not made for this session, make it in the background so that the data gets refreshed
                if (!this.sessionStorage.isSet(api)) {
                    this.log.debug("Caching fresh data for api", api);
                    this.fetchAPI(api);
                }
                resolve(this.localStorage.get(api));
            });
        }
        return this.fetchAPI(api);
    }

    // AJAX GET from the Total CMS API
    fetchAPI(api) {
        this.log.debug("fetchAPI:"+api);
        const promise = fetch(this.options.uri+api).then(response => {
            if (!response.ok) {
                response.json().then(json => console.error("fetchAPI Error",json));
                throw Error(response.statusText);
            }
            return response.json();
        });

        promise.then(json => {
            this.log.info("API Request Succeeded", json);

            // Massage the API data with helpers for templating
            if (Array.isArray(json)) {
                json = json.map(object => this.addObjectHelpers(object));
            } else {
                json = this.addObjectHelpers(json);
            }

            // Cache response in storage
            this.localStorage.set(this.expireKey+api, Date.now()+this.expireStorage);
            this.localStorage.set(api, json);
            this.sessionStorage.set(api, true);

        }).catch(error => {
            console.error("API Request Failed", error);
        });

        return promise;
    }

    locateHelperFields(object) {
        // ! This should be refactored so that it reads in the schema to find the actual
        // ! Fields required to do this. This code is fragile.
        // ! The drawback of using the schema is that its another API call.
        // ! Maybe return the schema along with every collection/object API call?
        const colors = [];
        const images = [];
        const galleries = [];

        for (const property in object) {
            if (!object[property]) continue;
            // images have a colors field
            if (object[property].colors) images.push(property);
            // colors have a hex field
            if (object[property].hex) colors.push(property);
            // galleries are arrays and have a colors field
            if (object[property][0] && object[property][0].colors) galleries.push(property);
        }
        return [colors, images, galleries];
    }
    maxColor(value, min = 0, max = 255) {
        return Math.min(Math.max(value, min), max);
    }
    rgbaString(color, offset = 0) {
        if (typeof color !== "object") {
            console.warn("Could not recognize color oject. Returning rgba(255,255,255,1)");
            return "rgba(255,255,255,1)";
        }
        const red   = this.maxColor(color.rgb[0] + offset);
        const green = this.maxColor(color.rgb[1] + offset);
        const blue  = this.maxColor(color.rgb[2] + offset);
        return `rgba(${red},${green},${blue},${color.alpha})`;
    }
    hslaString(color, offset = 0) {
        if (typeof color !== "object") {
            console.warn("Could not recognize color oject. Returning rgba(255,255,255,1)");
            return "rgba(255,255,255,1)";
        }
        const hue        = color.hsl[0];
        const saturation = color.hsl[1];
        const lightness  = this.maxColor(color.hsl[2] - offset, 0, 100);
        return `hsla(${hue},${saturation}%,${lightness}%,${color.alpha})`;
    }
    colorObjectHelpers(color) {
        return {
            rgba       : this.rgbaString(color),
            hsla       : this.hslaString(color),
            rgbaOffset : this.rgbaString(color, this.options.rgbOffset),
            hslaOffset : this.hslaString(color, this.options.hslOffset)
        };
    }
    // Augments data on objects
    addObjectHelpers(object) {
        if (typeof object !== "object") {
            console.warn("The API request does not contian an object. Not adding object helpers...");
            return;
        }
        object.collection = this.collection;

        // it would be nice if this looked at the collection schema but this works for now
        // this way is not efficient since it parses every object every time
        const [colors, images, galleries] = this.locateHelperFields(object);

        // augment color helpers on colors. Using this.colorHelpers the extend object[color]
        colors.forEach((colorName) => object[colorName] = Object.assign(this.colorObjectHelpers(object[colorName]), object[colorName]));

        // augment color helpers on image colors
        images.forEach((imageName) => {
            object[imageName]["colors"].forEach((color, colorIndex) => {
                object[imageName]["colors"][colorIndex] = Object.assign(this.colorObjectHelpers(color), color);
            });
        });

        // augment helpers on images in the galleries - 3 levels of forEach?!? Ugh.
        galleries.forEach((galleryName) => {
            object[galleryName].forEach((image, imageIndex) => {
                // add ID to each image this is so that gallery images can be processed in mustache
                // The ID is also used in ImageWorks in order to build the query
                object[galleryName][imageIndex]["id"] = object.id;
                object[galleryName][imageIndex]["collection"] = object.collection;
                // Add color helpers
                object[galleryName][imageIndex]["colors"].forEach((color, colorIndex) => {
                    object[galleryName][imageIndex]["colors"][colorIndex] = Object.assign(this.colorObjectHelpers(color), color);
                });
            });
        });

        // return the object
        return object;
    }
    // Utility mathod to figure out if we are on a touch device
    isTouch() {
        return "ontouchstart" in window || window.DocumentTouch && document instanceof DocumentTouch || false;
    }
    // Returns the basename of a filenaame string
    basename(str) {
        var base = str.substring(str.lastIndexOf("/") + 1);
        if (base.lastIndexOf(".") != -1) base = base.substring(0, base.lastIndexOf("."));
        return base;
    }
    // Convert a string of HTML and return the DOM node
    stringToElement(string) {
        return document.createRange().createContextualFragment(string);
    }
    // Convert a comma delimited string to an array
    stringToArray(string) {
        return string.replace(/\s+/g,"").split(",").filter(Boolean);
    }
    listToArray(list) {
        // accepts comma or space delimited lists
        return list.trim().replace(/,/g," ").replace(/\s+/g,",").split(",");
    }
    imageLoadTransition(node) {
        // Image Loading
        node.classList.add("template-layout", "template-loading");
        imagesLoaded(node, () => node.classList.remove("template-loading"));
    }
    // Process a mustache template
    processTemplate(data, template, dest) {
        template = template.replace(/(\r\n|\n|\r)+/gm," ");
        this.log.debug("processTemplate", data, template, dest);
        const output = Mustache.render(template, data);
        this.log.debug("Mustache output",output);
        const node = this.stringToElement(output);
        if (dest instanceof HTMLElement) {
            dest.appendChild(node);
            // Add image load transition to the item just added
            this.imageLoadTransition(dest.children[dest.children.length-1]);
        }
        return node;
    }
    // This is a utility method to get a parameter from the url query string
    getUrlParameter(name) {
        name = name.replace(/[[]/, "\\[").replace(/[\]]/, "\\]");
        const regex = new RegExp("[\\?&]" + name + "=([^&#]*)");
        const results = regex.exec(location.search);
        return results === null ? "" : decodeURIComponent(results[1].replace(/\+/g, " "));
    }
    // Find object ID
    getObjectId() {
        // Query for a single object's properties via query string
        const param = this.getUrlParameter("id");
        if (param) return param;

        // Look at global preview variable for macros
        if (window.totalPreview && window.totalPreview.hasOwnProperty("id")) {
            return window.totalPreview["id"];
        }

        // Pretty URL has ID at end of URL
        const prettyId = document.location.pathname.substr(document.location.pathname.lastIndexOf("/") + 1);
        // ignore if we find . = or ? since its probably just a URL
        if (!prettyId.match(/[.?=]/)) return prettyId;

        console.error("Unable to locate ID for query");
    }
    // Build a URL with a query string
    buildUrlQuery(api, params) {
        const baseapi = this.options.uri+api;
        if (typeof params !== "object") return baseapi;
        const queryString = Object.keys(params).map(key => key + "=" + params[key]).join("&");
        return `${baseapi}?${queryString}`;
    }
}
