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
 *       url: "http://localhost:8000/api.php",
 * });
 * </pre>
 *
**/
export default class TotalCMS {

    // Creates an instance of TotalCMS.
    constructor(options={}) {
        // Create global element references
        this.collection = null;

        const defaults = {
            passport        : null,
            cache           : true,
            cors            : false,
            locale          : "en",
            localizeStrings : {},
            config          : {},
            url             : ""
        };
        // get the global options and merge with defaults/arguments
        const globals = typeof window.totalcms === "object" ? window.totalcms.options : {};
        this.options  = Object.assign({}, defaults, globals, options);

        this.cache = this.options.cache;

        // Configuration options that can be set for various CMS components
        this.config = this.options.config||{};
    }

	clearTwigCacheListeners() {
		const clearCacheButtons = Array.from(document.querySelectorAll("button.cms-clear-cache,a.cms-clear-cache,.cms-clear-cache a,.cms-clear-cache button"));
		clearCacheButtons.forEach(button => {
			button.addEventListener("click", event => {
				event.preventDefault();
				this.clearTwigCache(event.target);
			});
		});
	}

	clearTwigCache(button) {
		this.postAPI('/cache', {}, "DELETE").then(response => {
			this.toggleButtonContent(button, "✓");
			console.log("Cache Cleared", response);
		});
	}

	toggleButtonContent(button, newContent, toggleClass, timeout = 2000) {
		const originalText = button.textContent;
		button.style.width = `${button.offsetWidth}px`;
		if (toggleClass) button.classList.add(toggleClass);
		button.textContent = newContent;

		setTimeout(() => {
			if (toggleClass) button.classList.remove(toggleClass);
			button.textContent = originalText;
			button.style.width = "";
		}, timeout);
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

	clearCache() {
        sessionStorage.clear();
    }

    // AJAX Post to the Total CMS API
    postAPI(api, data, method = "POST") {
        // If the POST API sets new data, we should delete form storage it if it exists
        sessionStorage.removeItem(api);

		let headers = { "Content-Type":"application/json" };
		if (method !== "POST") headers["X-Http-Method-Override"] = method.toUpperCase();

		// console.log(method, headers);

        return fetch(this.buildApiQuery(api), {
            method  : "POST",
            mode    : this.options.cors ? "cors" : "same-origin",
            headers : new Headers(headers),
            body: JSON.stringify(data)
        }).then(response => {
			if (!response.ok) {
				return response.json().then(json => {
					const error = new Error(json.error.message);
					error.data = json;
					throw error;
				});
			}
			return response.json();
        });
    }

    postFileAPI(api, data, method = "POST") {

		let headers = {};
		if (method !== "POST") headers["X-Http-Method-Override"] = method.toUpperCase();

		// console.log(method, headers);

        return fetch(this.buildApiQuery(api), {
            method  : "POST",
            mode    : this.options.cors ? "cors" : "same-origin",
            headers : new Headers(headers),
            body: data
        }).then(response => {
			if (!response.ok) {
				return response.json().then(json => {
					const error = new Error(json.error.message);
					error.data = json;
					throw error;
				});
			}
			return response.json();
        });
    }

	// Cached API fetch
	fetchCachedAPI(api) {
        if (this.cache && sessionStorage.getItem(api)) {
            return new Promise((resolve, reject) => {
                resolve(sessionStorage.getItem(api));
            });
        }
		return this.fetchAPI(api);
    }

    // GET from the Total CMS API
    fetchAPI(api, method = "GET") {
		let headers = {};
		if (method !== "GET") headers["X-Http-Method-Override"] = method.toUpperCase();

		return fetch(this.buildApiQuery(api), {
            method  : "GET",
            mode    : this.options.cors ? "cors" : "same-origin",
            headers : new Headers(headers)
		}).then(response => {
            if (!response.ok) {
                response.json().then(json => console.error("fetchAPI Error",json));
                throw Error(response.statusText);
            }
            // Cache response in storage
            const json = response.json();
            sessionStorage.setItem(api, json);

			return json;
        }).catch(error => {
            console.error("API Request Failed", error);
        });
    }

	// HEAD from the Total CMS API
	existsAPI(api) {
		return fetch(this.buildApiQuery(api), {
			method  : "GET",
			mode    : this.options.cors ? "cors" : "same-origin",
			headers : new Headers({
				"X-Http-Method-Override" : "HEAD"
			})
		}).catch(error => {
			console.error("Exists API Request Failed", error);
		});
	}

	// Utility mathod to figure out if we are on a touch device
    isTouch() {
        return "ontouchstart" in window || window.DocumentTouch && document instanceof DocumentTouch || false;
    }
    // Returns the basename of a filenaame string
	basename(str) {
		const base = str.substring(str.lastIndexOf("/") + 1);
		const dotIndex = base.lastIndexOf(".");
		if (dotIndex !== -1) {
			return base.substring(0, dotIndex);
		}
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
    // This is a utility method to get a parameter from the url query string
	getUrlParameter(name) {
		const params = new URLSearchParams(window.location.search);
		return params.get(name) || false;
	}
    // Build a URL with a query string
	buildApiQuery(api, params) {
		let baseUrl = this.options.url;
		if (!baseUrl.includes(window.location.origin)) {
			baseUrl = window.location.origin + baseUrl;
		}
		const url = new URL(baseUrl + api);
		if (typeof params === "object") {
			const newParams = new URLSearchParams(params);
			for (const [key, value] of newParams) {
				url.searchParams.append(key, value);
			}
		}
		return url.toString();
	}
}
