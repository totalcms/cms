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

        // Whether the caller explicitly passed a url. Captured before the merge so
        // an explicit empty string ("" — a valid base path on a root install) can be
        // told apart from "no url provided". Without this, a form whose correct base
        // is "" falls through to the page-wide auto-detect below and wrongly adopts
        // another form's data-api (e.g. the SMTP save form, base "", picking up the
        // test form's "/api" and 404ing on /api/admin/settings/smtp).
        const urlProvided = Object.prototype.hasOwnProperty.call(options, "url");

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

        // Auto-detect API URL from a page form ONLY when no url was provided at all.
        // An explicitly provided url (including "") is authoritative.
        if (!urlProvided && !this.options.url) {
            const form = document.querySelector('form.totalform[data-api]');
            if (form) this.options.url = form.dataset.api;
        }

        // Configuration options that can be set for various CMS components
        this.config = this.options.config||{};

		// Set up logout listeners
		this.logoutListeners();
    }

	logoutListeners() {
		const logoutElements = document.querySelectorAll('.cms-logout');
		if (logoutElements.length === 0) return;
		logoutElements.forEach(el => {
			el.addEventListener('click', (e) => {
				e.preventDefault();
				window.location.href = this.buildApiQuery('/logout');
			});
		});
	}

	clearTwigCacheListeners() {
		// Idempotent: admin.js wires these buttons automatically now, but
		// pages published with older stacks still carry an inline script
		// that calls this too — mark each button so the second caller
		// doesn't double-bind (one click would clear the cache twice).
		const clearCacheButtons = Array.from(document.querySelectorAll("button.cms-clear-cache,a.cms-clear-cache,.cms-clear-cache a,.cms-clear-cache button"));
		clearCacheButtons.forEach(button => {
			if (button.dataset.tcmsCacheBound === "1") return;
			button.dataset.tcmsCacheBound = "1";
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

	// Read the CSRF token from the admin layout's <meta name="csrf-token"> tag.
	// Returns null if the tag is absent (e.g. public-facing pages where the
	// admin layout isn't rendered) — callers can omit the header in that case.
	// CSRFProtectionMiddleware is only mounted on the admin route group, so
	// public callers don't need the header anyway.
	getCsrfToken() {
		const tag = document.querySelector('meta[name="csrf-token"]');
		return tag ? tag.getAttribute('content') : null;
	}

	putAPI(api, data) {
		return this.postAPI(api, data, "PUT");
    }

	patchAPI(api, data) {
		return this.postAPI(api, data, "PATCH");
	}

	deleteAPI(api, data = {}) {
		return this.postAPI(api, data, "DELETE");
    }

    // AJAX Post to the Total CMS API
    postAPI(api, data, method = "POST") {
        // If the POST API sets new data, we should delete form storage it if it exists
        sessionStorage.removeItem(api);

		let headers = { "Content-Type":"application/json" };
		if (method !== "POST") headers["X-Http-Method-Override"] = method.toUpperCase();
		// CSRFProtectionMiddleware on the admin route group reads X-CSRF-Token
		// for non-cookie-credentialed requests. JSON bodies don't surface the
		// hidden csrf_token form field, so the header is the route in.
		const csrf = this.getCsrfToken();
		if (csrf) headers["X-CSRF-Token"] = csrf;

		// console.log(method, headers);

        return fetch(this.buildApiQuery(api), {
            method  : "POST",
            mode    : this.options.cors ? "cors" : "same-origin",
            headers : new Headers(headers),
            body: JSON.stringify(data)
        }).then(response => {
			if (!response) {
				throw new Error('No response received from server');
			}
			if (!response.ok) {
				return response.json().then(json => {
					// Handle both string errors and object errors with message property
					const errorMessage = typeof json.error === 'string' ? json.error : (json.error?.message || 'Unknown error');
					const error = new Error(errorMessage);
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
		// CSRF token for the admin route group — same rationale as postAPI.
		// Multipart bodies CAN include the csrf_token field, but using the
		// header keeps a single consistent path across both API methods.
		const csrf = this.getCsrfToken();
		if (csrf) headers["X-CSRF-Token"] = csrf;

		// console.log(method, headers);

        return fetch(this.buildApiQuery(api), {
            method  : "POST",
            mode    : this.options.cors ? "cors" : "same-origin",
            headers : new Headers(headers),
            body: data
        }).then(response => {
			if (!response) {
				throw new Error('No response received from server');
			}
			if (!response.ok) {
				return response.json().then(json => {
					// Handle both string errors and object errors with message property
					const errorMessage = typeof json.error === 'string' ? json.error : (json.error?.message || 'Unknown error');
					const error = new Error(errorMessage);
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
		// Add CSRF token only when this is being used to send a state-changing
		// method via X-Http-Method-Override (GET is exempt from CSRF check).
		if (method !== "GET") {
			const csrf = this.getCsrfToken();
			if (csrf) headers["X-CSRF-Token"] = csrf;
		}

		return fetch(this.buildApiQuery(api), {
            method  : "GET",
            mode    : this.options.cors ? "cors" : "same-origin",
            headers : new Headers(headers)
		}).then(response => {
			if (!response) {
				throw new Error('No response received from server');
			}
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
			// Return a mock response indicating "not found" on network errors
			// This allows the ID validation to continue gracefully
			return { ok: false, status: 0, networkError: true };
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
		let baseUrl = this.options.url || '';
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

	// Like buildApiQuery, but targets the unprefixed base path (no `/api`).
	// imageworks, download, and stream serve binary asset bytes whose URLs
	// get embedded in user-rendered HTML, so they live outside the `/api/...`
	// surface — use this builder for those endpoints.
	buildPublicQuery(path, params) {
		let baseUrl = (this.options.url || '').replace(/\/api$/, '');
		if (!baseUrl.includes(window.location.origin)) {
			baseUrl = window.location.origin + baseUrl;
		}
		const url = new URL(baseUrl + path);
		if (typeof params === "object") {
			const newParams = new URLSearchParams(params);
			for (const [key, value] of newParams) {
				url.searchParams.append(key, value);
			}
		}
		return url.toString();
	}
}
