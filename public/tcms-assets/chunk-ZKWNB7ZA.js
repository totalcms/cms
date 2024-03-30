var __defProp = Object.defineProperty;
var __name = (target, value) => __defProp(target, "name", { value, configurable: true });

// javascript/totalcms.js
var TotalCMS = class {
  // Creates an instance of TotalCMS.
  constructor(options = {}) {
    this.collection = null;
    const defaults = {
      passport: null,
      cache: true,
      cors: false,
      locale: "en",
      localizeStrings: {},
      config: {},
      uri: "/rw_common/plugins/stacks/dynamics/public.php"
    };
    const globals = typeof window.totalcms === "object" ? window.totalcms.options : {};
    this.options = Object.assign({}, defaults, globals, options);
    this.cache = this.options.cache;
    this.config = this.options.config || {};
  }
  // Set, Get, Update config values
  setConfig(key, value) {
    this.config[key] = value;
  }
  getConfig(key) {
    return this.config[key] || {};
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
    sessionStorage.removeItem(api);
    return fetch(this.options.uri + api, {
      method: "POST",
      mode: this.options.cors ? "cors" : "same-origin",
      headers: new Headers({
        "X-Http-Method-Override": method,
        "Content-Type": "application/json"
      }),
      body: JSON.stringify(data)
    }).then((response) => {
      if (!response.ok) {
        response.json().then((json) => console.error("postAPI Error", json));
        throw Error(response.statusText);
      }
      return response.json();
    }).catch((error) => {
      console.error("POST API Request Failed", error);
    });
  }
  // AJAX GET from the Total CMS API
  fetchAPI(api) {
    if (this.cache && sessionStorage.getItem(api)) {
      return new Promise((resolve, reject) => {
        resolve(sessionStorage.getItem(api));
      });
    }
    return fetch(this.options.uri + api).then((response) => {
      if (!response.ok) {
        response.json().then((json) => console.error("fetchAPI Error", json));
        throw Error(response.statusText);
      }
      return response.json();
    }).then((json) => {
      if (Array.isArray(json)) {
        json = json.map((object) => this.addObjectHelpers(object));
      } else {
        json = this.addObjectHelpers(json);
      }
      sessionStorage.setItem(api, json);
    }).catch((error) => {
      console.error("API Request Failed", error);
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
    return string.replace(/\s+/g, "").split(",").filter(Boolean);
  }
  listToArray(list) {
    return list.trim().replace(/,/g, " ").replace(/\s+/g, ",").split(",");
  }
  // This is a utility method to get a parameter from the url query string
  getUrlParameter(name) {
    const params = new URLSearchParams(window.location.search);
    return params.get(name) || false;
  }
  // Build a URL with a query string
  buildApiQuery(api, params) {
    const baseapi = this.options.uri + api;
    if (typeof params !== "object")
      return baseapi;
    const queryString = new URLSearchParams(params).toString();
    return `${baseapi}?${queryString}`;
  }
};
__name(TotalCMS, "TotalCMS");

export {
  __name,
  TotalCMS
};
//# sourceMappingURL=chunk-ZKWNB7ZA.js.map
