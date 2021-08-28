(function(global, factory) {
    typeof exports === "object" && typeof module !== "undefined" ? module.exports = factory() : typeof define === "function" && define.amd ? define(factory) : (global = global || self, 
    global.Mustache = factory());
})(this, function() {
    "use strict";
    var objectToString = Object.prototype.toString;
    var isArray = Array.isArray || function isArrayPolyfill(object) {
        return objectToString.call(object) === "[object Array]";
    };
    function isFunction(object) {
        return typeof object === "function";
    }
    function typeStr(obj) {
        return isArray(obj) ? "array" : typeof obj;
    }
    function escapeRegExp(string) {
        return string.replace(/[\-\[\]{}()*+?.,\\\^$|#\s]/g, "\\$&");
    }
    function hasProperty(obj, propName) {
        return obj != null && typeof obj === "object" && propName in obj;
    }
    function primitiveHasOwnProperty(primitive, propName) {
        return primitive != null && typeof primitive !== "object" && primitive.hasOwnProperty && primitive.hasOwnProperty(propName);
    }
    var regExpTest = RegExp.prototype.test;
    function testRegExp(re, string) {
        return regExpTest.call(re, string);
    }
    var nonSpaceRe = /\S/;
    function isWhitespace(string) {
        return !testRegExp(nonSpaceRe, string);
    }
    var entityMap = {
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#39;",
        "/": "&#x2F;",
        "`": "&#x60;",
        "=": "&#x3D;"
    };
    function escapeHtml(string) {
        return String(string).replace(/[&<>"'`=\/]/g, function fromEntityMap(s) {
            return entityMap[s];
        });
    }
    var whiteRe = /\s*/;
    var spaceRe = /\s+/;
    var equalsRe = /\s*=/;
    var curlyRe = /\s*\}/;
    var tagRe = /#|\^|\/|>|\{|&|=|!/;
    function parseTemplate(template, tags) {
        if (!template) return [];
        var lineHasNonSpace = false;
        var sections = [];
        var tokens = [];
        var spaces = [];
        var hasTag = false;
        var nonSpace = false;
        var indentation = "";
        var tagIndex = 0;
        function stripSpace() {
            if (hasTag && !nonSpace) {
                while (spaces.length) delete tokens[spaces.pop()];
            } else {
                spaces = [];
            }
            hasTag = false;
            nonSpace = false;
        }
        var openingTagRe, closingTagRe, closingCurlyRe;
        function compileTags(tagsToCompile) {
            if (typeof tagsToCompile === "string") tagsToCompile = tagsToCompile.split(spaceRe, 2);
            if (!isArray(tagsToCompile) || tagsToCompile.length !== 2) throw new Error("Invalid tags: " + tagsToCompile);
            openingTagRe = new RegExp(escapeRegExp(tagsToCompile[0]) + "\\s*");
            closingTagRe = new RegExp("\\s*" + escapeRegExp(tagsToCompile[1]));
            closingCurlyRe = new RegExp("\\s*" + escapeRegExp("}" + tagsToCompile[1]));
        }
        compileTags(tags || mustache.tags);
        var scanner = new Scanner(template);
        var start, type, value, chr, token, openSection;
        while (!scanner.eos()) {
            start = scanner.pos;
            value = scanner.scanUntil(openingTagRe);
            if (value) {
                for (var i = 0, valueLength = value.length; i < valueLength; ++i) {
                    chr = value.charAt(i);
                    if (isWhitespace(chr)) {
                        spaces.push(tokens.length);
                        indentation += chr;
                    } else {
                        nonSpace = true;
                        lineHasNonSpace = true;
                        indentation += " ";
                    }
                    tokens.push([ "text", chr, start, start + 1 ]);
                    start += 1;
                    if (chr === "\n") {
                        stripSpace();
                        indentation = "";
                        tagIndex = 0;
                        lineHasNonSpace = false;
                    }
                }
            }
            if (!scanner.scan(openingTagRe)) break;
            hasTag = true;
            type = scanner.scan(tagRe) || "name";
            scanner.scan(whiteRe);
            if (type === "=") {
                value = scanner.scanUntil(equalsRe);
                scanner.scan(equalsRe);
                scanner.scanUntil(closingTagRe);
            } else if (type === "{") {
                value = scanner.scanUntil(closingCurlyRe);
                scanner.scan(curlyRe);
                scanner.scanUntil(closingTagRe);
                type = "&";
            } else {
                value = scanner.scanUntil(closingTagRe);
            }
            if (!scanner.scan(closingTagRe)) throw new Error("Unclosed tag at " + scanner.pos);
            if (type == ">") {
                token = [ type, value, start, scanner.pos, indentation, tagIndex, lineHasNonSpace ];
            } else {
                token = [ type, value, start, scanner.pos ];
            }
            tagIndex++;
            tokens.push(token);
            if (type === "#" || type === "^") {
                sections.push(token);
            } else if (type === "/") {
                openSection = sections.pop();
                if (!openSection) throw new Error('Unopened section "' + value + '" at ' + start);
                if (openSection[1] !== value) throw new Error('Unclosed section "' + openSection[1] + '" at ' + start);
            } else if (type === "name" || type === "{" || type === "&") {
                nonSpace = true;
            } else if (type === "=") {
                compileTags(value);
            }
        }
        stripSpace();
        openSection = sections.pop();
        if (openSection) throw new Error('Unclosed section "' + openSection[1] + '" at ' + scanner.pos);
        return nestTokens(squashTokens(tokens));
    }
    function squashTokens(tokens) {
        var squashedTokens = [];
        var token, lastToken;
        for (var i = 0, numTokens = tokens.length; i < numTokens; ++i) {
            token = tokens[i];
            if (token) {
                if (token[0] === "text" && lastToken && lastToken[0] === "text") {
                    lastToken[1] += token[1];
                    lastToken[3] = token[3];
                } else {
                    squashedTokens.push(token);
                    lastToken = token;
                }
            }
        }
        return squashedTokens;
    }
    function nestTokens(tokens) {
        var nestedTokens = [];
        var collector = nestedTokens;
        var sections = [];
        var token, section;
        for (var i = 0, numTokens = tokens.length; i < numTokens; ++i) {
            token = tokens[i];
            switch (token[0]) {
              case "#":
              case "^":
                collector.push(token);
                sections.push(token);
                collector = token[4] = [];
                break;

              case "/":
                section = sections.pop();
                section[5] = token[2];
                collector = sections.length > 0 ? sections[sections.length - 1][4] : nestedTokens;
                break;

              default:
                collector.push(token);
            }
        }
        return nestedTokens;
    }
    function Scanner(string) {
        this.string = string;
        this.tail = string;
        this.pos = 0;
    }
    Scanner.prototype.eos = function eos() {
        return this.tail === "";
    };
    Scanner.prototype.scan = function scan(re) {
        var match = this.tail.match(re);
        if (!match || match.index !== 0) return "";
        var string = match[0];
        this.tail = this.tail.substring(string.length);
        this.pos += string.length;
        return string;
    };
    Scanner.prototype.scanUntil = function scanUntil(re) {
        var index = this.tail.search(re), match;
        switch (index) {
          case -1:
            match = this.tail;
            this.tail = "";
            break;

          case 0:
            match = "";
            break;

          default:
            match = this.tail.substring(0, index);
            this.tail = this.tail.substring(index);
        }
        this.pos += match.length;
        return match;
    };
    function Context(view, parentContext) {
        this.view = view;
        this.cache = {
            ".": this.view
        };
        this.parent = parentContext;
    }
    Context.prototype.push = function push(view) {
        return new Context(view, this);
    };
    Context.prototype.lookup = function lookup(name) {
        var cache = this.cache;
        var value;
        if (cache.hasOwnProperty(name)) {
            value = cache[name];
        } else {
            var context = this, intermediateValue, names, index, lookupHit = false;
            while (context) {
                if (name.indexOf(".") > 0) {
                    intermediateValue = context.view;
                    names = name.split(".");
                    index = 0;
                    while (intermediateValue != null && index < names.length) {
                        if (index === names.length - 1) lookupHit = hasProperty(intermediateValue, names[index]) || primitiveHasOwnProperty(intermediateValue, names[index]);
                        intermediateValue = intermediateValue[names[index++]];
                    }
                } else {
                    intermediateValue = context.view[name];
                    lookupHit = hasProperty(context.view, name);
                }
                if (lookupHit) {
                    value = intermediateValue;
                    break;
                }
                context = context.parent;
            }
            cache[name] = value;
        }
        if (isFunction(value)) value = value.call(this.view);
        return value;
    };
    function Writer() {
        this.cache = {};
    }
    Writer.prototype.clearCache = function clearCache() {
        this.cache = {};
    };
    Writer.prototype.parse = function parse(template, tags) {
        var cache = this.cache;
        var cacheKey = template + ":" + (tags || mustache.tags).join(":");
        var tokens = cache[cacheKey];
        if (tokens == null) tokens = cache[cacheKey] = parseTemplate(template, tags);
        return tokens;
    };
    Writer.prototype.render = function render(template, view, partials, tags) {
        var tokens = this.parse(template, tags);
        var context = view instanceof Context ? view : new Context(view, undefined);
        return this.renderTokens(tokens, context, partials, template, tags);
    };
    Writer.prototype.renderTokens = function renderTokens(tokens, context, partials, originalTemplate, tags) {
        var buffer = "";
        var token, symbol, value;
        for (var i = 0, numTokens = tokens.length; i < numTokens; ++i) {
            value = undefined;
            token = tokens[i];
            symbol = token[0];
            if (symbol === "#") value = this.renderSection(token, context, partials, originalTemplate); else if (symbol === "^") value = this.renderInverted(token, context, partials, originalTemplate); else if (symbol === ">") value = this.renderPartial(token, context, partials, tags); else if (symbol === "&") value = this.unescapedValue(token, context); else if (symbol === "name") value = this.escapedValue(token, context); else if (symbol === "text") value = this.rawValue(token);
            if (value !== undefined) buffer += value;
        }
        return buffer;
    };
    Writer.prototype.renderSection = function renderSection(token, context, partials, originalTemplate) {
        var self = this;
        var buffer = "";
        var value = context.lookup(token[1]);
        function subRender(template) {
            return self.render(template, context, partials);
        }
        if (!value) return;
        if (isArray(value)) {
            for (var j = 0, valueLength = value.length; j < valueLength; ++j) {
                buffer += this.renderTokens(token[4], context.push(value[j]), partials, originalTemplate);
            }
        } else if (typeof value === "object" || typeof value === "string" || typeof value === "number") {
            buffer += this.renderTokens(token[4], context.push(value), partials, originalTemplate);
        } else if (isFunction(value)) {
            if (typeof originalTemplate !== "string") throw new Error("Cannot use higher-order sections without the original template");
            value = value.call(context.view, originalTemplate.slice(token[3], token[5]), subRender);
            if (value != null) buffer += value;
        } else {
            buffer += this.renderTokens(token[4], context, partials, originalTemplate);
        }
        return buffer;
    };
    Writer.prototype.renderInverted = function renderInverted(token, context, partials, originalTemplate) {
        var value = context.lookup(token[1]);
        if (!value || isArray(value) && value.length === 0) return this.renderTokens(token[4], context, partials, originalTemplate);
    };
    Writer.prototype.indentPartial = function indentPartial(partial, indentation, lineHasNonSpace) {
        var filteredIndentation = indentation.replace(/[^ \t]/g, "");
        var partialByNl = partial.split("\n");
        for (var i = 0; i < partialByNl.length; i++) {
            if (partialByNl[i].length && (i > 0 || !lineHasNonSpace)) {
                partialByNl[i] = filteredIndentation + partialByNl[i];
            }
        }
        return partialByNl.join("\n");
    };
    Writer.prototype.renderPartial = function renderPartial(token, context, partials, tags) {
        if (!partials) return;
        var value = isFunction(partials) ? partials(token[1]) : partials[token[1]];
        if (value != null) {
            var lineHasNonSpace = token[6];
            var tagIndex = token[5];
            var indentation = token[4];
            var indentedValue = value;
            if (tagIndex == 0 && indentation) {
                indentedValue = this.indentPartial(value, indentation, lineHasNonSpace);
            }
            return this.renderTokens(this.parse(indentedValue, tags), context, partials, indentedValue);
        }
    };
    Writer.prototype.unescapedValue = function unescapedValue(token, context) {
        var value = context.lookup(token[1]);
        if (value != null) return value;
    };
    Writer.prototype.escapedValue = function escapedValue(token, context) {
        var value = context.lookup(token[1]);
        if (value != null) return mustache.escape(value);
    };
    Writer.prototype.rawValue = function rawValue(token) {
        return token[1];
    };
    var mustache = {
        name: "mustache.js",
        version: "3.2.1",
        tags: [ "{{", "}}" ],
        clearCache: undefined,
        escape: undefined,
        parse: undefined,
        render: undefined,
        to_html: undefined,
        Scanner: undefined,
        Context: undefined,
        Writer: undefined
    };
    var defaultWriter = new Writer();
    mustache.clearCache = function clearCache() {
        return defaultWriter.clearCache();
    };
    mustache.parse = function parse(template, tags) {
        return defaultWriter.parse(template, tags);
    };
    mustache.render = function render(template, view, partials, tags) {
        if (typeof template !== "string") {
            throw new TypeError('Invalid template! Template should be a "string" ' + 'but "' + typeStr(template) + '" was given as the first ' + "argument for mustache#render(template, view, partials)");
        }
        return defaultWriter.render(template, view, partials, tags);
    };
    mustache.to_html = function to_html(template, view, partials, send) {
        var result = mustache.render(template, view, partials);
        if (isFunction(send)) {
            send(result);
        } else {
            return result;
        }
    };
    mustache.escape = escapeHtml;
    mustache.Scanner = Scanner;
    mustache.Context = Context;
    mustache.Writer = Writer;
    return mustache;
});

(function(factory) {
    var registeredInModuleLoader;
    if (typeof define === "function" && define.amd) {
        define(factory);
        registeredInModuleLoader = true;
    }
    if (typeof exports === "object") {
        module.exports = factory();
        registeredInModuleLoader = true;
    }
    if (!registeredInModuleLoader) {
        var OldCookies = window.Cookies;
        var api = window.Cookies = factory();
        api.noConflict = function() {
            window.Cookies = OldCookies;
            return api;
        };
    }
})(function() {
    function extend() {
        var i = 0;
        var result = {};
        for (;i < arguments.length; i++) {
            var attributes = arguments[i];
            for (var key in attributes) {
                result[key] = attributes[key];
            }
        }
        return result;
    }
    function decode(s) {
        return s.replace(/(%[0-9A-Z]{2})+/g, decodeURIComponent);
    }
    function init(converter) {
        function api() {}
        function set(key, value, attributes) {
            if (typeof document === "undefined") {
                return;
            }
            attributes = extend({
                path: "/"
            }, api.defaults, attributes);
            if (typeof attributes.expires === "number") {
                attributes.expires = new Date(new Date() * 1 + attributes.expires * 864e5);
            }
            attributes.expires = attributes.expires ? attributes.expires.toUTCString() : "";
            try {
                var result = JSON.stringify(value);
                if (/^[\{\[]/.test(result)) {
                    value = result;
                }
            } catch (e) {}
            value = converter.write ? converter.write(value, key) : encodeURIComponent(String(value)).replace(/%(23|24|26|2B|3A|3C|3E|3D|2F|3F|40|5B|5D|5E|60|7B|7D|7C)/g, decodeURIComponent);
            key = encodeURIComponent(String(key)).replace(/%(23|24|26|2B|5E|60|7C)/g, decodeURIComponent).replace(/[\(\)]/g, escape);
            var stringifiedAttributes = "";
            for (var attributeName in attributes) {
                if (!attributes[attributeName]) {
                    continue;
                }
                stringifiedAttributes += "; " + attributeName;
                if (attributes[attributeName] === true) {
                    continue;
                }
                stringifiedAttributes += "=" + attributes[attributeName].split(";")[0];
            }
            return document.cookie = key + "=" + value + stringifiedAttributes;
        }
        function get(key, json) {
            if (typeof document === "undefined") {
                return;
            }
            var jar = {};
            var cookies = document.cookie ? document.cookie.split("; ") : [];
            var i = 0;
            for (;i < cookies.length; i++) {
                var parts = cookies[i].split("=");
                var cookie = parts.slice(1).join("=");
                if (!json && cookie.charAt(0) === '"') {
                    cookie = cookie.slice(1, -1);
                }
                try {
                    var name = decode(parts[0]);
                    cookie = (converter.read || converter)(cookie, name) || decode(cookie);
                    if (json) {
                        try {
                            cookie = JSON.parse(cookie);
                        } catch (e) {}
                    }
                    jar[name] = cookie;
                    if (key === name) {
                        break;
                    }
                } catch (e) {}
            }
            return key ? jar[key] : jar;
        }
        api.set = set;
        api.get = function(key) {
            return get(key, false);
        };
        api.getJSON = function(key) {
            return get(key, true);
        };
        api.remove = function(key, attributes) {
            set(key, "", extend(attributes, {
                expires: -1
            }));
        };
        api.defaults = {};
        api.withConverter = init;
        return api;
    }
    return init(function() {});
});

(function(factory) {
    var registeredInModuleLoader = false;
    if (typeof define === "function" && define.amd) {
        define(factory);
        registeredInModuleLoader = true;
    }
    if (typeof exports === "object") {
        module.exports = factory();
        registeredInModuleLoader = true;
    }
    if (!registeredInModuleLoader) {
        var OldStorages = window.Storages;
        var api = window.Storages = factory();
        api.noConflict = function() {
            window.Storages = OldStorages;
            return api;
        };
    }
})(function() {
    var class2type = {};
    var toString = class2type.toString;
    var hasOwn = class2type.hasOwnProperty;
    var fnToString = hasOwn.toString;
    var ObjectFunctionString = fnToString.call(Object);
    var getProto = Object.getPrototypeOf;
    var apis = {};
    var cookie_local_prefix = "ls_";
    var cookie_session_prefix = "ss_";
    function _get() {
        var storage = this._type, l = arguments.length, s = window[storage], a = arguments, a0 = a[0], vi, ret, tmp, i, j;
        if (l < 1) {
            throw new Error("Minimum 1 argument must be given");
        } else if (Array.isArray(a0)) {
            ret = {};
            for (i in a0) {
                if (a0.hasOwnProperty(i)) {
                    vi = a0[i];
                    try {
                        ret[vi] = JSON.parse(s.getItem(vi));
                    } catch (e) {
                        ret[vi] = s.getItem(vi);
                    }
                }
            }
            return ret;
        } else if (l == 1) {
            try {
                return JSON.parse(s.getItem(a0));
            } catch (e) {
                return s.getItem(a0);
            }
        } else {
            try {
                ret = JSON.parse(s.getItem(a0));
                if (!ret) {
                    throw new ReferenceError(a0 + " is not defined in this storage");
                }
            } catch (e) {
                throw new ReferenceError(a0 + " is not defined in this storage");
            }
            for (i = 1; i < l - 1; i++) {
                ret = ret[a[i]];
                if (ret === undefined) {
                    throw new ReferenceError([].slice.call(a, 0, i + 1).join(".") + " is not defined in this storage");
                }
            }
            if (Array.isArray(a[i])) {
                tmp = ret;
                ret = {};
                for (j in a[i]) {
                    if (a[i].hasOwnProperty(j)) {
                        ret[a[i][j]] = tmp[a[i][j]];
                    }
                }
                return ret;
            } else {
                return ret[a[i]];
            }
        }
    }
    function _set() {
        var storage = this._type, l = arguments.length, s = window[storage], a = arguments, a0 = a[0], a1 = a[1], vi, to_store = isNaN(a1) ? {} : [], type, tmp, i;
        if (l < 1 || !_isPlainObject(a0) && l < 2) {
            throw new Error("Minimum 2 arguments must be given or first parameter must be an object");
        } else if (_isPlainObject(a0)) {
            for (i in a0) {
                if (a0.hasOwnProperty(i)) {
                    vi = a0[i];
                    if (!_isPlainObject(vi) && !this.alwaysUseJson) {
                        s.setItem(i, vi);
                    } else {
                        s.setItem(i, JSON.stringify(vi));
                    }
                }
            }
            return a0;
        } else if (l == 2) {
            if (typeof a1 === "object" || this.alwaysUseJson) {
                s.setItem(a0, JSON.stringify(a1));
            } else {
                s.setItem(a0, a1);
            }
            return a1;
        } else {
            try {
                tmp = s.getItem(a0);
                if (tmp != null) {
                    to_store = JSON.parse(tmp);
                }
            } catch (e) {}
            tmp = to_store;
            for (i = 1; i < l - 2; i++) {
                vi = a[i];
                type = isNaN(a[i + 1]) ? "object" : "array";
                if (!tmp[vi] || type == "object" && !_isPlainObject(tmp[vi]) || type == "array" && !Array.isArray(tmp[vi])) {
                    if (type == "array") tmp[vi] = []; else tmp[vi] = {};
                }
                tmp = tmp[vi];
            }
            tmp[a[i]] = a[i + 1];
            s.setItem(a0, JSON.stringify(to_store));
            return to_store;
        }
    }
    function _remove() {
        var storage = this._type, l = arguments.length, s = window[storage], a = arguments, a0 = a[0], to_store, tmp, i, j;
        if (l < 1) {
            throw new Error("Minimum 1 argument must be given");
        } else if (Array.isArray(a0)) {
            for (i in a0) {
                if (a0.hasOwnProperty(i)) {
                    s.removeItem(a0[i]);
                }
            }
            return true;
        } else if (l == 1) {
            s.removeItem(a0);
            return true;
        } else {
            try {
                to_store = tmp = JSON.parse(s.getItem(a0));
            } catch (e) {
                throw new ReferenceError(a0 + " is not defined in this storage");
            }
            for (i = 1; i < l - 1; i++) {
                tmp = tmp[a[i]];
                if (tmp === undefined) {
                    throw new ReferenceError([].slice.call(a, 1, i).join(".") + " is not defined in this storage");
                }
            }
            if (Array.isArray(a[i])) {
                for (j in a[i]) {
                    if (a[i].hasOwnProperty(j)) {
                        delete tmp[a[i][j]];
                    }
                }
            } else {
                delete tmp[a[i]];
            }
            s.setItem(a0, JSON.stringify(to_store));
            return true;
        }
    }
    function _removeAll(reinit_ns) {
        var keys = _keys.call(this), i;
        for (i in keys) {
            if (keys.hasOwnProperty(i)) {
                _remove.call(this, keys[i]);
            }
        }
        if (reinit_ns) {
            for (i in apis.namespaceStorages) {
                if (apis.namespaceStorages.hasOwnProperty(i)) {
                    _createNamespace(i);
                }
            }
        }
    }
    function _isEmpty() {
        var l = arguments.length, a = arguments, a0 = a[0], i;
        if (l == 0) {
            return _keys.call(this).length == 0;
        } else if (Array.isArray(a0)) {
            for (i = 0; i < a0.length; i++) {
                if (!_isEmpty.call(this, a0[i])) {
                    return false;
                }
            }
            return true;
        } else {
            try {
                var v = _get.apply(this, arguments);
                if (!Array.isArray(a[l - 1])) {
                    v = {
                        totest: v
                    };
                }
                for (i in v) {
                    if (v.hasOwnProperty(i) && !(_isPlainObject(v[i]) && _isEmptyObject(v[i]) || Array.isArray(v[i]) && !v[i].length || typeof v[i] !== "boolean" && !v[i])) {
                        return false;
                    }
                }
                return true;
            } catch (e) {
                return true;
            }
        }
    }
    function _isSet() {
        var l = arguments.length, a = arguments, a0 = a[0], i;
        if (l < 1) {
            throw new Error("Minimum 1 argument must be given");
        }
        if (Array.isArray(a0)) {
            for (i = 0; i < a0.length; i++) {
                if (!_isSet.call(this, a0[i])) {
                    return false;
                }
            }
            return true;
        } else {
            try {
                var v = _get.apply(this, arguments);
                if (!Array.isArray(a[l - 1])) {
                    v = {
                        totest: v
                    };
                }
                for (i in v) {
                    if (v.hasOwnProperty(i) && !(v[i] !== undefined && v[i] !== null)) {
                        return false;
                    }
                }
                return true;
            } catch (e) {
                return false;
            }
        }
    }
    function _keys() {
        var storage = this._type, l = arguments.length, s = window[storage], keys = [], o = {};
        if (l > 0) {
            o = _get.apply(this, arguments);
        } else {
            o = s;
        }
        if (o && o._cookie) {
            var cookies = Cookies.get();
            for (var key in cookies) {
                if (cookies.hasOwnProperty(key) && key != "") {
                    keys.push(key.replace(o._prefix, ""));
                }
            }
        } else {
            for (var i in o) {
                if (o.hasOwnProperty(i)) {
                    keys.push(i);
                }
            }
        }
        return keys;
    }
    function _createNamespace(name) {
        if (!name || typeof name != "string") {
            throw new Error("First parameter must be a string");
        }
        if (storage_available) {
            if (!window.localStorage.getItem(name)) {
                window.localStorage.setItem(name, "{}");
            }
            if (!window.sessionStorage.getItem(name)) {
                window.sessionStorage.setItem(name, "{}");
            }
        } else {
            if (!window.localCookieStorage.getItem(name)) {
                window.localCookieStorage.setItem(name, "{}");
            }
            if (!window.sessionCookieStorage.getItem(name)) {
                window.sessionCookieStorage.setItem(name, "{}");
            }
        }
        var ns = {
            localStorage: _extend({}, apis.localStorage, {
                _ns: name
            }),
            sessionStorage: _extend({}, apis.sessionStorage, {
                _ns: name
            })
        };
        if (cookies_available) {
            if (!window.cookieStorage.getItem(name)) {
                window.cookieStorage.setItem(name, "{}");
            }
            ns.cookieStorage = _extend({}, apis.cookieStorage, {
                _ns: name
            });
        }
        apis.namespaceStorages[name] = ns;
        return ns;
    }
    function _testStorage(name) {
        var foo = "jsapi";
        try {
            if (!window[name]) {
                return false;
            }
            window[name].setItem(foo, foo);
            window[name].removeItem(foo);
            return true;
        } catch (e) {
            return false;
        }
    }
    function _isPlainObject(obj) {
        var proto, Ctor;
        if (!obj || toString.call(obj) !== "[object Object]") {
            return false;
        }
        proto = getProto(obj);
        if (!proto) {
            return true;
        }
        Ctor = hasOwn.call(proto, "constructor") && proto.constructor;
        return typeof Ctor === "function" && fnToString.call(Ctor) === ObjectFunctionString;
    }
    function _isEmptyObject(obj) {
        var name;
        for (name in obj) {
            return false;
        }
        return true;
    }
    function _extend() {
        var i = 1;
        var result = arguments[0];
        for (;i < arguments.length; i++) {
            var attributes = arguments[i];
            for (var key in attributes) {
                if (attributes.hasOwnProperty(key)) {
                    result[key] = attributes[key];
                }
            }
        }
        return result;
    }
    var storage_available = _testStorage("localStorage");
    var cookies_available = typeof Cookies !== "undefined";
    var storage = {
        _type: "",
        _ns: "",
        _callMethod: function(f, a) {
            a = Array.prototype.slice.call(a);
            var p = [], a0 = a[0];
            if (this._ns) {
                p.push(this._ns);
            }
            if (typeof a0 === "string" && a0.indexOf(".") !== -1) {
                a.shift();
                [].unshift.apply(a, a0.split("."));
            }
            [].push.apply(p, a);
            return f.apply(this, p);
        },
        alwaysUseJson: false,
        get: function() {
            if (!storage_available && !cookies_available) {
                return null;
            }
            return this._callMethod(_get, arguments);
        },
        set: function() {
            var l = arguments.length, a = arguments, a0 = a[0];
            if (l < 1 || !_isPlainObject(a0) && l < 2) {
                throw new Error("Minimum 2 arguments must be given or first parameter must be an object");
            }
            if (!storage_available && !cookies_available) {
                return null;
            }
            if (_isPlainObject(a0) && this._ns) {
                for (var i in a0) {
                    if (a0.hasOwnProperty(i)) {
                        this._callMethod(_set, [ i, a0[i] ]);
                    }
                }
                return a0;
            } else {
                var r = this._callMethod(_set, a);
                if (this._ns) {
                    return r[a0.split(".")[0]];
                } else {
                    return r;
                }
            }
        },
        remove: function() {
            if (arguments.length < 1) {
                throw new Error("Minimum 1 argument must be given");
            }
            if (!storage_available && !cookies_available) {
                return null;
            }
            return this._callMethod(_remove, arguments);
        },
        removeAll: function(reinit_ns) {
            if (!storage_available && !cookies_available) {
                return null;
            }
            if (this._ns) {
                this._callMethod(_set, [ {} ]);
                return true;
            } else {
                return this._callMethod(_removeAll, [ reinit_ns ]);
            }
        },
        isEmpty: function() {
            if (!storage_available && !cookies_available) {
                return null;
            }
            return this._callMethod(_isEmpty, arguments);
        },
        isSet: function() {
            if (arguments.length < 1) {
                throw new Error("Minimum 1 argument must be given");
            }
            if (!storage_available && !cookies_available) {
                return null;
            }
            return this._callMethod(_isSet, arguments);
        },
        keys: function() {
            if (!storage_available && !cookies_available) {
                return null;
            }
            return this._callMethod(_keys, arguments);
        }
    };
    if (cookies_available) {
        if (!window.name) {
            window.name = Math.floor(Math.random() * 1e8);
        }
        var cookie_storage = {
            _cookie: true,
            _prefix: "",
            _expires: null,
            _path: null,
            _domain: null,
            _secure: false,
            setItem: function(n, v) {
                Cookies.set(this._prefix + n, v, {
                    expires: this._expires,
                    path: this._path,
                    domain: this._domain,
                    secure: this._secure
                });
            },
            getItem: function(n) {
                return Cookies.get(this._prefix + n);
            },
            removeItem: function(n) {
                return Cookies.remove(this._prefix + n, {
                    path: this._path
                });
            },
            clear: function() {
                var cookies = Cookies.get();
                for (var key in cookies) {
                    if (cookies.hasOwnProperty(key) && key != "") {
                        if (!this._prefix && key.indexOf(cookie_local_prefix) === -1 && key.indexOf(cookie_session_prefix) === -1 || this._prefix && key.indexOf(this._prefix) === 0) {
                            Cookies.remove(key);
                        }
                    }
                }
            },
            setExpires: function(e) {
                this._expires = e;
                return this;
            },
            setPath: function(p) {
                this._path = p;
                return this;
            },
            setDomain: function(d) {
                this._domain = d;
                return this;
            },
            setSecure: function(s) {
                this._secure = s;
                return this;
            },
            setConf: function(c) {
                if (c.path) {
                    this._path = c.path;
                }
                if (c.domain) {
                    this._domain = c.domain;
                }
                if (c.secure) {
                    this._secure = c.secure;
                }
                if (c.expires) {
                    this._expires = c.expires;
                }
                return this;
            },
            setDefaultConf: function() {
                this._path = this._domain = this._expires = null;
                this._secure = false;
            }
        };
        if (!storage_available) {
            window.localCookieStorage = _extend({}, cookie_storage, {
                _prefix: cookie_local_prefix,
                _expires: 365 * 10,
                _secure: true
            });
            window.sessionCookieStorage = _extend({}, cookie_storage, {
                _prefix: cookie_session_prefix + window.name + "_",
                _secure: true
            });
        }
        window.cookieStorage = _extend({}, cookie_storage);
        apis.cookieStorage = _extend({}, storage, {
            _type: "cookieStorage",
            setExpires: function(e) {
                window.cookieStorage.setExpires(e);
                return this;
            },
            setPath: function(p) {
                window.cookieStorage.setPath(p);
                return this;
            },
            setDomain: function(d) {
                window.cookieStorage.setDomain(d);
                return this;
            },
            setSecure: function(s) {
                window.cookieStorage.setSecure(s);
                return this;
            },
            setConf: function(c) {
                window.cookieStorage.setConf(c);
                return this;
            },
            setDefaultConf: function() {
                window.cookieStorage.setDefaultConf();
                return this;
            }
        });
    }
    apis.initNamespaceStorage = function(ns) {
        return _createNamespace(ns);
    };
    if (storage_available) {
        apis.localStorage = _extend({}, storage, {
            _type: "localStorage"
        });
        apis.sessionStorage = _extend({}, storage, {
            _type: "sessionStorage"
        });
    } else {
        apis.localStorage = _extend({}, storage, {
            _type: "localCookieStorage"
        });
        apis.sessionStorage = _extend({}, storage, {
            _type: "sessionCookieStorage"
        });
    }
    apis.namespaceStorages = {};
    apis.removeAllStorages = function(reinit_ns) {
        apis.localStorage.removeAll(reinit_ns);
        apis.sessionStorage.removeAll(reinit_ns);
        if (apis.cookieStorage) {
            apis.cookieStorage.removeAll(reinit_ns);
        }
        if (!reinit_ns) {
            apis.namespaceStorages = {};
        }
    };
    apis.alwaysUseJsonInStorage = function(value) {
        storage.alwaysUseJson = value;
        apis.localStorage.alwaysUseJson = value;
        apis.sessionStorage.alwaysUseJson = value;
        if (apis.cookieStorage) {
            apis.cookieStorage.alwaysUseJson = value;
        }
    };
    return apis;
});

(function(global, factory) {
    typeof exports === "object" && typeof module !== "undefined" ? module.exports = factory() : typeof define === "function" && define.amd ? define(factory) : global.moment = factory();
})(this, function() {
    "use strict";
    var hookCallback;
    function hooks() {
        return hookCallback.apply(null, arguments);
    }
    function setHookCallback(callback) {
        hookCallback = callback;
    }
    function isArray(input) {
        return input instanceof Array || Object.prototype.toString.call(input) === "[object Array]";
    }
    function isObject(input) {
        return input != null && Object.prototype.toString.call(input) === "[object Object]";
    }
    function isObjectEmpty(obj) {
        if (Object.getOwnPropertyNames) {
            return Object.getOwnPropertyNames(obj).length === 0;
        } else {
            var k;
            for (k in obj) {
                if (obj.hasOwnProperty(k)) {
                    return false;
                }
            }
            return true;
        }
    }
    function isUndefined(input) {
        return input === void 0;
    }
    function isNumber(input) {
        return typeof input === "number" || Object.prototype.toString.call(input) === "[object Number]";
    }
    function isDate(input) {
        return input instanceof Date || Object.prototype.toString.call(input) === "[object Date]";
    }
    function map(arr, fn) {
        var res = [], i;
        for (i = 0; i < arr.length; ++i) {
            res.push(fn(arr[i], i));
        }
        return res;
    }
    function hasOwnProp(a, b) {
        return Object.prototype.hasOwnProperty.call(a, b);
    }
    function extend(a, b) {
        for (var i in b) {
            if (hasOwnProp(b, i)) {
                a[i] = b[i];
            }
        }
        if (hasOwnProp(b, "toString")) {
            a.toString = b.toString;
        }
        if (hasOwnProp(b, "valueOf")) {
            a.valueOf = b.valueOf;
        }
        return a;
    }
    function createUTC(input, format, locale, strict) {
        return createLocalOrUTC(input, format, locale, strict, true).utc();
    }
    function defaultParsingFlags() {
        return {
            empty: false,
            unusedTokens: [],
            unusedInput: [],
            overflow: -2,
            charsLeftOver: 0,
            nullInput: false,
            invalidMonth: null,
            invalidFormat: false,
            userInvalidated: false,
            iso: false,
            parsedDateParts: [],
            meridiem: null,
            rfc2822: false,
            weekdayMismatch: false
        };
    }
    function getParsingFlags(m) {
        if (m._pf == null) {
            m._pf = defaultParsingFlags();
        }
        return m._pf;
    }
    var some;
    if (Array.prototype.some) {
        some = Array.prototype.some;
    } else {
        some = function(fun) {
            var t = Object(this);
            var len = t.length >>> 0;
            for (var i = 0; i < len; i++) {
                if (i in t && fun.call(this, t[i], i, t)) {
                    return true;
                }
            }
            return false;
        };
    }
    function isValid(m) {
        if (m._isValid == null) {
            var flags = getParsingFlags(m);
            var parsedParts = some.call(flags.parsedDateParts, function(i) {
                return i != null;
            });
            var isNowValid = !isNaN(m._d.getTime()) && flags.overflow < 0 && !flags.empty && !flags.invalidMonth && !flags.invalidWeekday && !flags.weekdayMismatch && !flags.nullInput && !flags.invalidFormat && !flags.userInvalidated && (!flags.meridiem || flags.meridiem && parsedParts);
            if (m._strict) {
                isNowValid = isNowValid && flags.charsLeftOver === 0 && flags.unusedTokens.length === 0 && flags.bigHour === undefined;
            }
            if (Object.isFrozen == null || !Object.isFrozen(m)) {
                m._isValid = isNowValid;
            } else {
                return isNowValid;
            }
        }
        return m._isValid;
    }
    function createInvalid(flags) {
        var m = createUTC(NaN);
        if (flags != null) {
            extend(getParsingFlags(m), flags);
        } else {
            getParsingFlags(m).userInvalidated = true;
        }
        return m;
    }
    var momentProperties = hooks.momentProperties = [];
    function copyConfig(to, from) {
        var i, prop, val;
        if (!isUndefined(from._isAMomentObject)) {
            to._isAMomentObject = from._isAMomentObject;
        }
        if (!isUndefined(from._i)) {
            to._i = from._i;
        }
        if (!isUndefined(from._f)) {
            to._f = from._f;
        }
        if (!isUndefined(from._l)) {
            to._l = from._l;
        }
        if (!isUndefined(from._strict)) {
            to._strict = from._strict;
        }
        if (!isUndefined(from._tzm)) {
            to._tzm = from._tzm;
        }
        if (!isUndefined(from._isUTC)) {
            to._isUTC = from._isUTC;
        }
        if (!isUndefined(from._offset)) {
            to._offset = from._offset;
        }
        if (!isUndefined(from._pf)) {
            to._pf = getParsingFlags(from);
        }
        if (!isUndefined(from._locale)) {
            to._locale = from._locale;
        }
        if (momentProperties.length > 0) {
            for (i = 0; i < momentProperties.length; i++) {
                prop = momentProperties[i];
                val = from[prop];
                if (!isUndefined(val)) {
                    to[prop] = val;
                }
            }
        }
        return to;
    }
    var updateInProgress = false;
    function Moment(config) {
        copyConfig(this, config);
        this._d = new Date(config._d != null ? config._d.getTime() : NaN);
        if (!this.isValid()) {
            this._d = new Date(NaN);
        }
        if (updateInProgress === false) {
            updateInProgress = true;
            hooks.updateOffset(this);
            updateInProgress = false;
        }
    }
    function isMoment(obj) {
        return obj instanceof Moment || obj != null && obj._isAMomentObject != null;
    }
    function absFloor(number) {
        if (number < 0) {
            return Math.ceil(number) || 0;
        } else {
            return Math.floor(number);
        }
    }
    function toInt(argumentForCoercion) {
        var coercedNumber = +argumentForCoercion, value = 0;
        if (coercedNumber !== 0 && isFinite(coercedNumber)) {
            value = absFloor(coercedNumber);
        }
        return value;
    }
    function compareArrays(array1, array2, dontConvert) {
        var len = Math.min(array1.length, array2.length), lengthDiff = Math.abs(array1.length - array2.length), diffs = 0, i;
        for (i = 0; i < len; i++) {
            if (dontConvert && array1[i] !== array2[i] || !dontConvert && toInt(array1[i]) !== toInt(array2[i])) {
                diffs++;
            }
        }
        return diffs + lengthDiff;
    }
    function warn(msg) {
        if (hooks.suppressDeprecationWarnings === false && typeof console !== "undefined" && console.warn) {
            console.warn("Deprecation warning: " + msg);
        }
    }
    function deprecate(msg, fn) {
        var firstTime = true;
        return extend(function() {
            if (hooks.deprecationHandler != null) {
                hooks.deprecationHandler(null, msg);
            }
            if (firstTime) {
                var args = [];
                var arg;
                for (var i = 0; i < arguments.length; i++) {
                    arg = "";
                    if (typeof arguments[i] === "object") {
                        arg += "\n[" + i + "] ";
                        for (var key in arguments[0]) {
                            arg += key + ": " + arguments[0][key] + ", ";
                        }
                        arg = arg.slice(0, -2);
                    } else {
                        arg = arguments[i];
                    }
                    args.push(arg);
                }
                warn(msg + "\nArguments: " + Array.prototype.slice.call(args).join("") + "\n" + new Error().stack);
                firstTime = false;
            }
            return fn.apply(this, arguments);
        }, fn);
    }
    var deprecations = {};
    function deprecateSimple(name, msg) {
        if (hooks.deprecationHandler != null) {
            hooks.deprecationHandler(name, msg);
        }
        if (!deprecations[name]) {
            warn(msg);
            deprecations[name] = true;
        }
    }
    hooks.suppressDeprecationWarnings = false;
    hooks.deprecationHandler = null;
    function isFunction(input) {
        return input instanceof Function || Object.prototype.toString.call(input) === "[object Function]";
    }
    function set(config) {
        var prop, i;
        for (i in config) {
            prop = config[i];
            if (isFunction(prop)) {
                this[i] = prop;
            } else {
                this["_" + i] = prop;
            }
        }
        this._config = config;
        this._dayOfMonthOrdinalParseLenient = new RegExp((this._dayOfMonthOrdinalParse.source || this._ordinalParse.source) + "|" + /\d{1,2}/.source);
    }
    function mergeConfigs(parentConfig, childConfig) {
        var res = extend({}, parentConfig), prop;
        for (prop in childConfig) {
            if (hasOwnProp(childConfig, prop)) {
                if (isObject(parentConfig[prop]) && isObject(childConfig[prop])) {
                    res[prop] = {};
                    extend(res[prop], parentConfig[prop]);
                    extend(res[prop], childConfig[prop]);
                } else if (childConfig[prop] != null) {
                    res[prop] = childConfig[prop];
                } else {
                    delete res[prop];
                }
            }
        }
        for (prop in parentConfig) {
            if (hasOwnProp(parentConfig, prop) && !hasOwnProp(childConfig, prop) && isObject(parentConfig[prop])) {
                res[prop] = extend({}, res[prop]);
            }
        }
        return res;
    }
    function Locale(config) {
        if (config != null) {
            this.set(config);
        }
    }
    var keys;
    if (Object.keys) {
        keys = Object.keys;
    } else {
        keys = function(obj) {
            var i, res = [];
            for (i in obj) {
                if (hasOwnProp(obj, i)) {
                    res.push(i);
                }
            }
            return res;
        };
    }
    var defaultCalendar = {
        sameDay: "[Today at] LT",
        nextDay: "[Tomorrow at] LT",
        nextWeek: "dddd [at] LT",
        lastDay: "[Yesterday at] LT",
        lastWeek: "[Last] dddd [at] LT",
        sameElse: "L"
    };
    function calendar(key, mom, now) {
        var output = this._calendar[key] || this._calendar["sameElse"];
        return isFunction(output) ? output.call(mom, now) : output;
    }
    var defaultLongDateFormat = {
        LTS: "h:mm:ss A",
        LT: "h:mm A",
        L: "MM/DD/YYYY",
        LL: "MMMM D, YYYY",
        LLL: "MMMM D, YYYY h:mm A",
        LLLL: "dddd, MMMM D, YYYY h:mm A"
    };
    function longDateFormat(key) {
        var format = this._longDateFormat[key], formatUpper = this._longDateFormat[key.toUpperCase()];
        if (format || !formatUpper) {
            return format;
        }
        this._longDateFormat[key] = formatUpper.replace(/MMMM|MM|DD|dddd/g, function(val) {
            return val.slice(1);
        });
        return this._longDateFormat[key];
    }
    var defaultInvalidDate = "Invalid date";
    function invalidDate() {
        return this._invalidDate;
    }
    var defaultOrdinal = "%d";
    var defaultDayOfMonthOrdinalParse = /\d{1,2}/;
    function ordinal(number) {
        return this._ordinal.replace("%d", number);
    }
    var defaultRelativeTime = {
        future: "in %s",
        past: "%s ago",
        s: "a few seconds",
        ss: "%d seconds",
        m: "a minute",
        mm: "%d minutes",
        h: "an hour",
        hh: "%d hours",
        d: "a day",
        dd: "%d days",
        M: "a month",
        MM: "%d months",
        y: "a year",
        yy: "%d years"
    };
    function relativeTime(number, withoutSuffix, string, isFuture) {
        var output = this._relativeTime[string];
        return isFunction(output) ? output(number, withoutSuffix, string, isFuture) : output.replace(/%d/i, number);
    }
    function pastFuture(diff, output) {
        var format = this._relativeTime[diff > 0 ? "future" : "past"];
        return isFunction(format) ? format(output) : format.replace(/%s/i, output);
    }
    var aliases = {};
    function addUnitAlias(unit, shorthand) {
        var lowerCase = unit.toLowerCase();
        aliases[lowerCase] = aliases[lowerCase + "s"] = aliases[shorthand] = unit;
    }
    function normalizeUnits(units) {
        return typeof units === "string" ? aliases[units] || aliases[units.toLowerCase()] : undefined;
    }
    function normalizeObjectUnits(inputObject) {
        var normalizedInput = {}, normalizedProp, prop;
        for (prop in inputObject) {
            if (hasOwnProp(inputObject, prop)) {
                normalizedProp = normalizeUnits(prop);
                if (normalizedProp) {
                    normalizedInput[normalizedProp] = inputObject[prop];
                }
            }
        }
        return normalizedInput;
    }
    var priorities = {};
    function addUnitPriority(unit, priority) {
        priorities[unit] = priority;
    }
    function getPrioritizedUnits(unitsObj) {
        var units = [];
        for (var u in unitsObj) {
            units.push({
                unit: u,
                priority: priorities[u]
            });
        }
        units.sort(function(a, b) {
            return a.priority - b.priority;
        });
        return units;
    }
    function zeroFill(number, targetLength, forceSign) {
        var absNumber = "" + Math.abs(number), zerosToFill = targetLength - absNumber.length, sign = number >= 0;
        return (sign ? forceSign ? "+" : "" : "-") + Math.pow(10, Math.max(0, zerosToFill)).toString().substr(1) + absNumber;
    }
    var formattingTokens = /(\[[^\[]*\])|(\\)?([Hh]mm(ss)?|Mo|MM?M?M?|Do|DDDo|DD?D?D?|ddd?d?|do?|w[o|w]?|W[o|W]?|Qo?|YYYYYY|YYYYY|YYYY|YY|gg(ggg?)?|GG(GGG?)?|e|E|a|A|hh?|HH?|kk?|mm?|ss?|S{1,9}|x|X|zz?|ZZ?|.)/g;
    var localFormattingTokens = /(\[[^\[]*\])|(\\)?(LTS|LT|LL?L?L?|l{1,4})/g;
    var formatFunctions = {};
    var formatTokenFunctions = {};
    function addFormatToken(token, padded, ordinal, callback) {
        var func = callback;
        if (typeof callback === "string") {
            func = function() {
                return this[callback]();
            };
        }
        if (token) {
            formatTokenFunctions[token] = func;
        }
        if (padded) {
            formatTokenFunctions[padded[0]] = function() {
                return zeroFill(func.apply(this, arguments), padded[1], padded[2]);
            };
        }
        if (ordinal) {
            formatTokenFunctions[ordinal] = function() {
                return this.localeData().ordinal(func.apply(this, arguments), token);
            };
        }
    }
    function removeFormattingTokens(input) {
        if (input.match(/\[[\s\S]/)) {
            return input.replace(/^\[|\]$/g, "");
        }
        return input.replace(/\\/g, "");
    }
    function makeFormatFunction(format) {
        var array = format.match(formattingTokens), i, length;
        for (i = 0, length = array.length; i < length; i++) {
            if (formatTokenFunctions[array[i]]) {
                array[i] = formatTokenFunctions[array[i]];
            } else {
                array[i] = removeFormattingTokens(array[i]);
            }
        }
        return function(mom) {
            var output = "", i;
            for (i = 0; i < length; i++) {
                output += isFunction(array[i]) ? array[i].call(mom, format) : array[i];
            }
            return output;
        };
    }
    function formatMoment(m, format) {
        if (!m.isValid()) {
            return m.localeData().invalidDate();
        }
        format = expandFormat(format, m.localeData());
        formatFunctions[format] = formatFunctions[format] || makeFormatFunction(format);
        return formatFunctions[format](m);
    }
    function expandFormat(format, locale) {
        var i = 5;
        function replaceLongDateFormatTokens(input) {
            return locale.longDateFormat(input) || input;
        }
        localFormattingTokens.lastIndex = 0;
        while (i >= 0 && localFormattingTokens.test(format)) {
            format = format.replace(localFormattingTokens, replaceLongDateFormatTokens);
            localFormattingTokens.lastIndex = 0;
            i -= 1;
        }
        return format;
    }
    var match1 = /\d/;
    var match2 = /\d\d/;
    var match3 = /\d{3}/;
    var match4 = /\d{4}/;
    var match6 = /[+-]?\d{6}/;
    var match1to2 = /\d\d?/;
    var match3to4 = /\d\d\d\d?/;
    var match5to6 = /\d\d\d\d\d\d?/;
    var match1to3 = /\d{1,3}/;
    var match1to4 = /\d{1,4}/;
    var match1to6 = /[+-]?\d{1,6}/;
    var matchUnsigned = /\d+/;
    var matchSigned = /[+-]?\d+/;
    var matchOffset = /Z|[+-]\d\d:?\d\d/gi;
    var matchShortOffset = /Z|[+-]\d\d(?::?\d\d)?/gi;
    var matchTimestamp = /[+-]?\d+(\.\d{1,3})?/;
    var matchWord = /[0-9]{0,256}['a-z\u00A0-\u05FF\u0700-\uD7FF\uF900-\uFDCF\uFDF0-\uFF07\uFF10-\uFFEF]{1,256}|[\u0600-\u06FF\/]{1,256}(\s*?[\u0600-\u06FF]{1,256}){1,2}/i;
    var regexes = {};
    function addRegexToken(token, regex, strictRegex) {
        regexes[token] = isFunction(regex) ? regex : function(isStrict, localeData) {
            return isStrict && strictRegex ? strictRegex : regex;
        };
    }
    function getParseRegexForToken(token, config) {
        if (!hasOwnProp(regexes, token)) {
            return new RegExp(unescapeFormat(token));
        }
        return regexes[token](config._strict, config._locale);
    }
    function unescapeFormat(s) {
        return regexEscape(s.replace("\\", "").replace(/\\(\[)|\\(\])|\[([^\]\[]*)\]|\\(.)/g, function(matched, p1, p2, p3, p4) {
            return p1 || p2 || p3 || p4;
        }));
    }
    function regexEscape(s) {
        return s.replace(/[-\/\\^$*+?.()|[\]{}]/g, "\\$&");
    }
    var tokens = {};
    function addParseToken(token, callback) {
        var i, func = callback;
        if (typeof token === "string") {
            token = [ token ];
        }
        if (isNumber(callback)) {
            func = function(input, array) {
                array[callback] = toInt(input);
            };
        }
        for (i = 0; i < token.length; i++) {
            tokens[token[i]] = func;
        }
    }
    function addWeekParseToken(token, callback) {
        addParseToken(token, function(input, array, config, token) {
            config._w = config._w || {};
            callback(input, config._w, config, token);
        });
    }
    function addTimeToArrayFromToken(token, input, config) {
        if (input != null && hasOwnProp(tokens, token)) {
            tokens[token](input, config._a, config, token);
        }
    }
    var YEAR = 0;
    var MONTH = 1;
    var DATE = 2;
    var HOUR = 3;
    var MINUTE = 4;
    var SECOND = 5;
    var MILLISECOND = 6;
    var WEEK = 7;
    var WEEKDAY = 8;
    addFormatToken("Y", 0, 0, function() {
        var y = this.year();
        return y <= 9999 ? "" + y : "+" + y;
    });
    addFormatToken(0, [ "YY", 2 ], 0, function() {
        return this.year() % 100;
    });
    addFormatToken(0, [ "YYYY", 4 ], 0, "year");
    addFormatToken(0, [ "YYYYY", 5 ], 0, "year");
    addFormatToken(0, [ "YYYYYY", 6, true ], 0, "year");
    addUnitAlias("year", "y");
    addUnitPriority("year", 1);
    addRegexToken("Y", matchSigned);
    addRegexToken("YY", match1to2, match2);
    addRegexToken("YYYY", match1to4, match4);
    addRegexToken("YYYYY", match1to6, match6);
    addRegexToken("YYYYYY", match1to6, match6);
    addParseToken([ "YYYYY", "YYYYYY" ], YEAR);
    addParseToken("YYYY", function(input, array) {
        array[YEAR] = input.length === 2 ? hooks.parseTwoDigitYear(input) : toInt(input);
    });
    addParseToken("YY", function(input, array) {
        array[YEAR] = hooks.parseTwoDigitYear(input);
    });
    addParseToken("Y", function(input, array) {
        array[YEAR] = parseInt(input, 10);
    });
    function daysInYear(year) {
        return isLeapYear(year) ? 366 : 365;
    }
    function isLeapYear(year) {
        return year % 4 === 0 && year % 100 !== 0 || year % 400 === 0;
    }
    hooks.parseTwoDigitYear = function(input) {
        return toInt(input) + (toInt(input) > 68 ? 1900 : 2e3);
    };
    var getSetYear = makeGetSet("FullYear", true);
    function getIsLeapYear() {
        return isLeapYear(this.year());
    }
    function makeGetSet(unit, keepTime) {
        return function(value) {
            if (value != null) {
                set$1(this, unit, value);
                hooks.updateOffset(this, keepTime);
                return this;
            } else {
                return get(this, unit);
            }
        };
    }
    function get(mom, unit) {
        return mom.isValid() ? mom._d["get" + (mom._isUTC ? "UTC" : "") + unit]() : NaN;
    }
    function set$1(mom, unit, value) {
        if (mom.isValid() && !isNaN(value)) {
            if (unit === "FullYear" && isLeapYear(mom.year()) && mom.month() === 1 && mom.date() === 29) {
                mom._d["set" + (mom._isUTC ? "UTC" : "") + unit](value, mom.month(), daysInMonth(value, mom.month()));
            } else {
                mom._d["set" + (mom._isUTC ? "UTC" : "") + unit](value);
            }
        }
    }
    function stringGet(units) {
        units = normalizeUnits(units);
        if (isFunction(this[units])) {
            return this[units]();
        }
        return this;
    }
    function stringSet(units, value) {
        if (typeof units === "object") {
            units = normalizeObjectUnits(units);
            var prioritized = getPrioritizedUnits(units);
            for (var i = 0; i < prioritized.length; i++) {
                this[prioritized[i].unit](units[prioritized[i].unit]);
            }
        } else {
            units = normalizeUnits(units);
            if (isFunction(this[units])) {
                return this[units](value);
            }
        }
        return this;
    }
    function mod(n, x) {
        return (n % x + x) % x;
    }
    var indexOf;
    if (Array.prototype.indexOf) {
        indexOf = Array.prototype.indexOf;
    } else {
        indexOf = function(o) {
            var i;
            for (i = 0; i < this.length; ++i) {
                if (this[i] === o) {
                    return i;
                }
            }
            return -1;
        };
    }
    function daysInMonth(year, month) {
        if (isNaN(year) || isNaN(month)) {
            return NaN;
        }
        var modMonth = mod(month, 12);
        year += (month - modMonth) / 12;
        return modMonth === 1 ? isLeapYear(year) ? 29 : 28 : 31 - modMonth % 7 % 2;
    }
    addFormatToken("M", [ "MM", 2 ], "Mo", function() {
        return this.month() + 1;
    });
    addFormatToken("MMM", 0, 0, function(format) {
        return this.localeData().monthsShort(this, format);
    });
    addFormatToken("MMMM", 0, 0, function(format) {
        return this.localeData().months(this, format);
    });
    addUnitAlias("month", "M");
    addUnitPriority("month", 8);
    addRegexToken("M", match1to2);
    addRegexToken("MM", match1to2, match2);
    addRegexToken("MMM", function(isStrict, locale) {
        return locale.monthsShortRegex(isStrict);
    });
    addRegexToken("MMMM", function(isStrict, locale) {
        return locale.monthsRegex(isStrict);
    });
    addParseToken([ "M", "MM" ], function(input, array) {
        array[MONTH] = toInt(input) - 1;
    });
    addParseToken([ "MMM", "MMMM" ], function(input, array, config, token) {
        var month = config._locale.monthsParse(input, token, config._strict);
        if (month != null) {
            array[MONTH] = month;
        } else {
            getParsingFlags(config).invalidMonth = input;
        }
    });
    var MONTHS_IN_FORMAT = /D[oD]?(\[[^\[\]]*\]|\s)+MMMM?/;
    var defaultLocaleMonths = "January_February_March_April_May_June_July_August_September_October_November_December".split("_");
    function localeMonths(m, format) {
        if (!m) {
            return isArray(this._months) ? this._months : this._months["standalone"];
        }
        return isArray(this._months) ? this._months[m.month()] : this._months[(this._months.isFormat || MONTHS_IN_FORMAT).test(format) ? "format" : "standalone"][m.month()];
    }
    var defaultLocaleMonthsShort = "Jan_Feb_Mar_Apr_May_Jun_Jul_Aug_Sep_Oct_Nov_Dec".split("_");
    function localeMonthsShort(m, format) {
        if (!m) {
            return isArray(this._monthsShort) ? this._monthsShort : this._monthsShort["standalone"];
        }
        return isArray(this._monthsShort) ? this._monthsShort[m.month()] : this._monthsShort[MONTHS_IN_FORMAT.test(format) ? "format" : "standalone"][m.month()];
    }
    function handleStrictParse(monthName, format, strict) {
        var i, ii, mom, llc = monthName.toLocaleLowerCase();
        if (!this._monthsParse) {
            this._monthsParse = [];
            this._longMonthsParse = [];
            this._shortMonthsParse = [];
            for (i = 0; i < 12; ++i) {
                mom = createUTC([ 2e3, i ]);
                this._shortMonthsParse[i] = this.monthsShort(mom, "").toLocaleLowerCase();
                this._longMonthsParse[i] = this.months(mom, "").toLocaleLowerCase();
            }
        }
        if (strict) {
            if (format === "MMM") {
                ii = indexOf.call(this._shortMonthsParse, llc);
                return ii !== -1 ? ii : null;
            } else {
                ii = indexOf.call(this._longMonthsParse, llc);
                return ii !== -1 ? ii : null;
            }
        } else {
            if (format === "MMM") {
                ii = indexOf.call(this._shortMonthsParse, llc);
                if (ii !== -1) {
                    return ii;
                }
                ii = indexOf.call(this._longMonthsParse, llc);
                return ii !== -1 ? ii : null;
            } else {
                ii = indexOf.call(this._longMonthsParse, llc);
                if (ii !== -1) {
                    return ii;
                }
                ii = indexOf.call(this._shortMonthsParse, llc);
                return ii !== -1 ? ii : null;
            }
        }
    }
    function localeMonthsParse(monthName, format, strict) {
        var i, mom, regex;
        if (this._monthsParseExact) {
            return handleStrictParse.call(this, monthName, format, strict);
        }
        if (!this._monthsParse) {
            this._monthsParse = [];
            this._longMonthsParse = [];
            this._shortMonthsParse = [];
        }
        for (i = 0; i < 12; i++) {
            mom = createUTC([ 2e3, i ]);
            if (strict && !this._longMonthsParse[i]) {
                this._longMonthsParse[i] = new RegExp("^" + this.months(mom, "").replace(".", "") + "$", "i");
                this._shortMonthsParse[i] = new RegExp("^" + this.monthsShort(mom, "").replace(".", "") + "$", "i");
            }
            if (!strict && !this._monthsParse[i]) {
                regex = "^" + this.months(mom, "") + "|^" + this.monthsShort(mom, "");
                this._monthsParse[i] = new RegExp(regex.replace(".", ""), "i");
            }
            if (strict && format === "MMMM" && this._longMonthsParse[i].test(monthName)) {
                return i;
            } else if (strict && format === "MMM" && this._shortMonthsParse[i].test(monthName)) {
                return i;
            } else if (!strict && this._monthsParse[i].test(monthName)) {
                return i;
            }
        }
    }
    function setMonth(mom, value) {
        var dayOfMonth;
        if (!mom.isValid()) {
            return mom;
        }
        if (typeof value === "string") {
            if (/^\d+$/.test(value)) {
                value = toInt(value);
            } else {
                value = mom.localeData().monthsParse(value);
                if (!isNumber(value)) {
                    return mom;
                }
            }
        }
        dayOfMonth = Math.min(mom.date(), daysInMonth(mom.year(), value));
        mom._d["set" + (mom._isUTC ? "UTC" : "") + "Month"](value, dayOfMonth);
        return mom;
    }
    function getSetMonth(value) {
        if (value != null) {
            setMonth(this, value);
            hooks.updateOffset(this, true);
            return this;
        } else {
            return get(this, "Month");
        }
    }
    function getDaysInMonth() {
        return daysInMonth(this.year(), this.month());
    }
    var defaultMonthsShortRegex = matchWord;
    function monthsShortRegex(isStrict) {
        if (this._monthsParseExact) {
            if (!hasOwnProp(this, "_monthsRegex")) {
                computeMonthsParse.call(this);
            }
            if (isStrict) {
                return this._monthsShortStrictRegex;
            } else {
                return this._monthsShortRegex;
            }
        } else {
            if (!hasOwnProp(this, "_monthsShortRegex")) {
                this._monthsShortRegex = defaultMonthsShortRegex;
            }
            return this._monthsShortStrictRegex && isStrict ? this._monthsShortStrictRegex : this._monthsShortRegex;
        }
    }
    var defaultMonthsRegex = matchWord;
    function monthsRegex(isStrict) {
        if (this._monthsParseExact) {
            if (!hasOwnProp(this, "_monthsRegex")) {
                computeMonthsParse.call(this);
            }
            if (isStrict) {
                return this._monthsStrictRegex;
            } else {
                return this._monthsRegex;
            }
        } else {
            if (!hasOwnProp(this, "_monthsRegex")) {
                this._monthsRegex = defaultMonthsRegex;
            }
            return this._monthsStrictRegex && isStrict ? this._monthsStrictRegex : this._monthsRegex;
        }
    }
    function computeMonthsParse() {
        function cmpLenRev(a, b) {
            return b.length - a.length;
        }
        var shortPieces = [], longPieces = [], mixedPieces = [], i, mom;
        for (i = 0; i < 12; i++) {
            mom = createUTC([ 2e3, i ]);
            shortPieces.push(this.monthsShort(mom, ""));
            longPieces.push(this.months(mom, ""));
            mixedPieces.push(this.months(mom, ""));
            mixedPieces.push(this.monthsShort(mom, ""));
        }
        shortPieces.sort(cmpLenRev);
        longPieces.sort(cmpLenRev);
        mixedPieces.sort(cmpLenRev);
        for (i = 0; i < 12; i++) {
            shortPieces[i] = regexEscape(shortPieces[i]);
            longPieces[i] = regexEscape(longPieces[i]);
        }
        for (i = 0; i < 24; i++) {
            mixedPieces[i] = regexEscape(mixedPieces[i]);
        }
        this._monthsRegex = new RegExp("^(" + mixedPieces.join("|") + ")", "i");
        this._monthsShortRegex = this._monthsRegex;
        this._monthsStrictRegex = new RegExp("^(" + longPieces.join("|") + ")", "i");
        this._monthsShortStrictRegex = new RegExp("^(" + shortPieces.join("|") + ")", "i");
    }
    function createDate(y, m, d, h, M, s, ms) {
        var date;
        if (y < 100 && y >= 0) {
            date = new Date(y + 400, m, d, h, M, s, ms);
            if (isFinite(date.getFullYear())) {
                date.setFullYear(y);
            }
        } else {
            date = new Date(y, m, d, h, M, s, ms);
        }
        return date;
    }
    function createUTCDate(y) {
        var date;
        if (y < 100 && y >= 0) {
            var args = Array.prototype.slice.call(arguments);
            args[0] = y + 400;
            date = new Date(Date.UTC.apply(null, args));
            if (isFinite(date.getUTCFullYear())) {
                date.setUTCFullYear(y);
            }
        } else {
            date = new Date(Date.UTC.apply(null, arguments));
        }
        return date;
    }
    function firstWeekOffset(year, dow, doy) {
        var fwd = 7 + dow - doy, fwdlw = (7 + createUTCDate(year, 0, fwd).getUTCDay() - dow) % 7;
        return -fwdlw + fwd - 1;
    }
    function dayOfYearFromWeeks(year, week, weekday, dow, doy) {
        var localWeekday = (7 + weekday - dow) % 7, weekOffset = firstWeekOffset(year, dow, doy), dayOfYear = 1 + 7 * (week - 1) + localWeekday + weekOffset, resYear, resDayOfYear;
        if (dayOfYear <= 0) {
            resYear = year - 1;
            resDayOfYear = daysInYear(resYear) + dayOfYear;
        } else if (dayOfYear > daysInYear(year)) {
            resYear = year + 1;
            resDayOfYear = dayOfYear - daysInYear(year);
        } else {
            resYear = year;
            resDayOfYear = dayOfYear;
        }
        return {
            year: resYear,
            dayOfYear: resDayOfYear
        };
    }
    function weekOfYear(mom, dow, doy) {
        var weekOffset = firstWeekOffset(mom.year(), dow, doy), week = Math.floor((mom.dayOfYear() - weekOffset - 1) / 7) + 1, resWeek, resYear;
        if (week < 1) {
            resYear = mom.year() - 1;
            resWeek = week + weeksInYear(resYear, dow, doy);
        } else if (week > weeksInYear(mom.year(), dow, doy)) {
            resWeek = week - weeksInYear(mom.year(), dow, doy);
            resYear = mom.year() + 1;
        } else {
            resYear = mom.year();
            resWeek = week;
        }
        return {
            week: resWeek,
            year: resYear
        };
    }
    function weeksInYear(year, dow, doy) {
        var weekOffset = firstWeekOffset(year, dow, doy), weekOffsetNext = firstWeekOffset(year + 1, dow, doy);
        return (daysInYear(year) - weekOffset + weekOffsetNext) / 7;
    }
    addFormatToken("w", [ "ww", 2 ], "wo", "week");
    addFormatToken("W", [ "WW", 2 ], "Wo", "isoWeek");
    addUnitAlias("week", "w");
    addUnitAlias("isoWeek", "W");
    addUnitPriority("week", 5);
    addUnitPriority("isoWeek", 5);
    addRegexToken("w", match1to2);
    addRegexToken("ww", match1to2, match2);
    addRegexToken("W", match1to2);
    addRegexToken("WW", match1to2, match2);
    addWeekParseToken([ "w", "ww", "W", "WW" ], function(input, week, config, token) {
        week[token.substr(0, 1)] = toInt(input);
    });
    function localeWeek(mom) {
        return weekOfYear(mom, this._week.dow, this._week.doy).week;
    }
    var defaultLocaleWeek = {
        dow: 0,
        doy: 6
    };
    function localeFirstDayOfWeek() {
        return this._week.dow;
    }
    function localeFirstDayOfYear() {
        return this._week.doy;
    }
    function getSetWeek(input) {
        var week = this.localeData().week(this);
        return input == null ? week : this.add((input - week) * 7, "d");
    }
    function getSetISOWeek(input) {
        var week = weekOfYear(this, 1, 4).week;
        return input == null ? week : this.add((input - week) * 7, "d");
    }
    addFormatToken("d", 0, "do", "day");
    addFormatToken("dd", 0, 0, function(format) {
        return this.localeData().weekdaysMin(this, format);
    });
    addFormatToken("ddd", 0, 0, function(format) {
        return this.localeData().weekdaysShort(this, format);
    });
    addFormatToken("dddd", 0, 0, function(format) {
        return this.localeData().weekdays(this, format);
    });
    addFormatToken("e", 0, 0, "weekday");
    addFormatToken("E", 0, 0, "isoWeekday");
    addUnitAlias("day", "d");
    addUnitAlias("weekday", "e");
    addUnitAlias("isoWeekday", "E");
    addUnitPriority("day", 11);
    addUnitPriority("weekday", 11);
    addUnitPriority("isoWeekday", 11);
    addRegexToken("d", match1to2);
    addRegexToken("e", match1to2);
    addRegexToken("E", match1to2);
    addRegexToken("dd", function(isStrict, locale) {
        return locale.weekdaysMinRegex(isStrict);
    });
    addRegexToken("ddd", function(isStrict, locale) {
        return locale.weekdaysShortRegex(isStrict);
    });
    addRegexToken("dddd", function(isStrict, locale) {
        return locale.weekdaysRegex(isStrict);
    });
    addWeekParseToken([ "dd", "ddd", "dddd" ], function(input, week, config, token) {
        var weekday = config._locale.weekdaysParse(input, token, config._strict);
        if (weekday != null) {
            week.d = weekday;
        } else {
            getParsingFlags(config).invalidWeekday = input;
        }
    });
    addWeekParseToken([ "d", "e", "E" ], function(input, week, config, token) {
        week[token] = toInt(input);
    });
    function parseWeekday(input, locale) {
        if (typeof input !== "string") {
            return input;
        }
        if (!isNaN(input)) {
            return parseInt(input, 10);
        }
        input = locale.weekdaysParse(input);
        if (typeof input === "number") {
            return input;
        }
        return null;
    }
    function parseIsoWeekday(input, locale) {
        if (typeof input === "string") {
            return locale.weekdaysParse(input) % 7 || 7;
        }
        return isNaN(input) ? null : input;
    }
    function shiftWeekdays(ws, n) {
        return ws.slice(n, 7).concat(ws.slice(0, n));
    }
    var defaultLocaleWeekdays = "Sunday_Monday_Tuesday_Wednesday_Thursday_Friday_Saturday".split("_");
    function localeWeekdays(m, format) {
        var weekdays = isArray(this._weekdays) ? this._weekdays : this._weekdays[m && m !== true && this._weekdays.isFormat.test(format) ? "format" : "standalone"];
        return m === true ? shiftWeekdays(weekdays, this._week.dow) : m ? weekdays[m.day()] : weekdays;
    }
    var defaultLocaleWeekdaysShort = "Sun_Mon_Tue_Wed_Thu_Fri_Sat".split("_");
    function localeWeekdaysShort(m) {
        return m === true ? shiftWeekdays(this._weekdaysShort, this._week.dow) : m ? this._weekdaysShort[m.day()] : this._weekdaysShort;
    }
    var defaultLocaleWeekdaysMin = "Su_Mo_Tu_We_Th_Fr_Sa".split("_");
    function localeWeekdaysMin(m) {
        return m === true ? shiftWeekdays(this._weekdaysMin, this._week.dow) : m ? this._weekdaysMin[m.day()] : this._weekdaysMin;
    }
    function handleStrictParse$1(weekdayName, format, strict) {
        var i, ii, mom, llc = weekdayName.toLocaleLowerCase();
        if (!this._weekdaysParse) {
            this._weekdaysParse = [];
            this._shortWeekdaysParse = [];
            this._minWeekdaysParse = [];
            for (i = 0; i < 7; ++i) {
                mom = createUTC([ 2e3, 1 ]).day(i);
                this._minWeekdaysParse[i] = this.weekdaysMin(mom, "").toLocaleLowerCase();
                this._shortWeekdaysParse[i] = this.weekdaysShort(mom, "").toLocaleLowerCase();
                this._weekdaysParse[i] = this.weekdays(mom, "").toLocaleLowerCase();
            }
        }
        if (strict) {
            if (format === "dddd") {
                ii = indexOf.call(this._weekdaysParse, llc);
                return ii !== -1 ? ii : null;
            } else if (format === "ddd") {
                ii = indexOf.call(this._shortWeekdaysParse, llc);
                return ii !== -1 ? ii : null;
            } else {
                ii = indexOf.call(this._minWeekdaysParse, llc);
                return ii !== -1 ? ii : null;
            }
        } else {
            if (format === "dddd") {
                ii = indexOf.call(this._weekdaysParse, llc);
                if (ii !== -1) {
                    return ii;
                }
                ii = indexOf.call(this._shortWeekdaysParse, llc);
                if (ii !== -1) {
                    return ii;
                }
                ii = indexOf.call(this._minWeekdaysParse, llc);
                return ii !== -1 ? ii : null;
            } else if (format === "ddd") {
                ii = indexOf.call(this._shortWeekdaysParse, llc);
                if (ii !== -1) {
                    return ii;
                }
                ii = indexOf.call(this._weekdaysParse, llc);
                if (ii !== -1) {
                    return ii;
                }
                ii = indexOf.call(this._minWeekdaysParse, llc);
                return ii !== -1 ? ii : null;
            } else {
                ii = indexOf.call(this._minWeekdaysParse, llc);
                if (ii !== -1) {
                    return ii;
                }
                ii = indexOf.call(this._weekdaysParse, llc);
                if (ii !== -1) {
                    return ii;
                }
                ii = indexOf.call(this._shortWeekdaysParse, llc);
                return ii !== -1 ? ii : null;
            }
        }
    }
    function localeWeekdaysParse(weekdayName, format, strict) {
        var i, mom, regex;
        if (this._weekdaysParseExact) {
            return handleStrictParse$1.call(this, weekdayName, format, strict);
        }
        if (!this._weekdaysParse) {
            this._weekdaysParse = [];
            this._minWeekdaysParse = [];
            this._shortWeekdaysParse = [];
            this._fullWeekdaysParse = [];
        }
        for (i = 0; i < 7; i++) {
            mom = createUTC([ 2e3, 1 ]).day(i);
            if (strict && !this._fullWeekdaysParse[i]) {
                this._fullWeekdaysParse[i] = new RegExp("^" + this.weekdays(mom, "").replace(".", "\\.?") + "$", "i");
                this._shortWeekdaysParse[i] = new RegExp("^" + this.weekdaysShort(mom, "").replace(".", "\\.?") + "$", "i");
                this._minWeekdaysParse[i] = new RegExp("^" + this.weekdaysMin(mom, "").replace(".", "\\.?") + "$", "i");
            }
            if (!this._weekdaysParse[i]) {
                regex = "^" + this.weekdays(mom, "") + "|^" + this.weekdaysShort(mom, "") + "|^" + this.weekdaysMin(mom, "");
                this._weekdaysParse[i] = new RegExp(regex.replace(".", ""), "i");
            }
            if (strict && format === "dddd" && this._fullWeekdaysParse[i].test(weekdayName)) {
                return i;
            } else if (strict && format === "ddd" && this._shortWeekdaysParse[i].test(weekdayName)) {
                return i;
            } else if (strict && format === "dd" && this._minWeekdaysParse[i].test(weekdayName)) {
                return i;
            } else if (!strict && this._weekdaysParse[i].test(weekdayName)) {
                return i;
            }
        }
    }
    function getSetDayOfWeek(input) {
        if (!this.isValid()) {
            return input != null ? this : NaN;
        }
        var day = this._isUTC ? this._d.getUTCDay() : this._d.getDay();
        if (input != null) {
            input = parseWeekday(input, this.localeData());
            return this.add(input - day, "d");
        } else {
            return day;
        }
    }
    function getSetLocaleDayOfWeek(input) {
        if (!this.isValid()) {
            return input != null ? this : NaN;
        }
        var weekday = (this.day() + 7 - this.localeData()._week.dow) % 7;
        return input == null ? weekday : this.add(input - weekday, "d");
    }
    function getSetISODayOfWeek(input) {
        if (!this.isValid()) {
            return input != null ? this : NaN;
        }
        if (input != null) {
            var weekday = parseIsoWeekday(input, this.localeData());
            return this.day(this.day() % 7 ? weekday : weekday - 7);
        } else {
            return this.day() || 7;
        }
    }
    var defaultWeekdaysRegex = matchWord;
    function weekdaysRegex(isStrict) {
        if (this._weekdaysParseExact) {
            if (!hasOwnProp(this, "_weekdaysRegex")) {
                computeWeekdaysParse.call(this);
            }
            if (isStrict) {
                return this._weekdaysStrictRegex;
            } else {
                return this._weekdaysRegex;
            }
        } else {
            if (!hasOwnProp(this, "_weekdaysRegex")) {
                this._weekdaysRegex = defaultWeekdaysRegex;
            }
            return this._weekdaysStrictRegex && isStrict ? this._weekdaysStrictRegex : this._weekdaysRegex;
        }
    }
    var defaultWeekdaysShortRegex = matchWord;
    function weekdaysShortRegex(isStrict) {
        if (this._weekdaysParseExact) {
            if (!hasOwnProp(this, "_weekdaysRegex")) {
                computeWeekdaysParse.call(this);
            }
            if (isStrict) {
                return this._weekdaysShortStrictRegex;
            } else {
                return this._weekdaysShortRegex;
            }
        } else {
            if (!hasOwnProp(this, "_weekdaysShortRegex")) {
                this._weekdaysShortRegex = defaultWeekdaysShortRegex;
            }
            return this._weekdaysShortStrictRegex && isStrict ? this._weekdaysShortStrictRegex : this._weekdaysShortRegex;
        }
    }
    var defaultWeekdaysMinRegex = matchWord;
    function weekdaysMinRegex(isStrict) {
        if (this._weekdaysParseExact) {
            if (!hasOwnProp(this, "_weekdaysRegex")) {
                computeWeekdaysParse.call(this);
            }
            if (isStrict) {
                return this._weekdaysMinStrictRegex;
            } else {
                return this._weekdaysMinRegex;
            }
        } else {
            if (!hasOwnProp(this, "_weekdaysMinRegex")) {
                this._weekdaysMinRegex = defaultWeekdaysMinRegex;
            }
            return this._weekdaysMinStrictRegex && isStrict ? this._weekdaysMinStrictRegex : this._weekdaysMinRegex;
        }
    }
    function computeWeekdaysParse() {
        function cmpLenRev(a, b) {
            return b.length - a.length;
        }
        var minPieces = [], shortPieces = [], longPieces = [], mixedPieces = [], i, mom, minp, shortp, longp;
        for (i = 0; i < 7; i++) {
            mom = createUTC([ 2e3, 1 ]).day(i);
            minp = this.weekdaysMin(mom, "");
            shortp = this.weekdaysShort(mom, "");
            longp = this.weekdays(mom, "");
            minPieces.push(minp);
            shortPieces.push(shortp);
            longPieces.push(longp);
            mixedPieces.push(minp);
            mixedPieces.push(shortp);
            mixedPieces.push(longp);
        }
        minPieces.sort(cmpLenRev);
        shortPieces.sort(cmpLenRev);
        longPieces.sort(cmpLenRev);
        mixedPieces.sort(cmpLenRev);
        for (i = 0; i < 7; i++) {
            shortPieces[i] = regexEscape(shortPieces[i]);
            longPieces[i] = regexEscape(longPieces[i]);
            mixedPieces[i] = regexEscape(mixedPieces[i]);
        }
        this._weekdaysRegex = new RegExp("^(" + mixedPieces.join("|") + ")", "i");
        this._weekdaysShortRegex = this._weekdaysRegex;
        this._weekdaysMinRegex = this._weekdaysRegex;
        this._weekdaysStrictRegex = new RegExp("^(" + longPieces.join("|") + ")", "i");
        this._weekdaysShortStrictRegex = new RegExp("^(" + shortPieces.join("|") + ")", "i");
        this._weekdaysMinStrictRegex = new RegExp("^(" + minPieces.join("|") + ")", "i");
    }
    function hFormat() {
        return this.hours() % 12 || 12;
    }
    function kFormat() {
        return this.hours() || 24;
    }
    addFormatToken("H", [ "HH", 2 ], 0, "hour");
    addFormatToken("h", [ "hh", 2 ], 0, hFormat);
    addFormatToken("k", [ "kk", 2 ], 0, kFormat);
    addFormatToken("hmm", 0, 0, function() {
        return "" + hFormat.apply(this) + zeroFill(this.minutes(), 2);
    });
    addFormatToken("hmmss", 0, 0, function() {
        return "" + hFormat.apply(this) + zeroFill(this.minutes(), 2) + zeroFill(this.seconds(), 2);
    });
    addFormatToken("Hmm", 0, 0, function() {
        return "" + this.hours() + zeroFill(this.minutes(), 2);
    });
    addFormatToken("Hmmss", 0, 0, function() {
        return "" + this.hours() + zeroFill(this.minutes(), 2) + zeroFill(this.seconds(), 2);
    });
    function meridiem(token, lowercase) {
        addFormatToken(token, 0, 0, function() {
            return this.localeData().meridiem(this.hours(), this.minutes(), lowercase);
        });
    }
    meridiem("a", true);
    meridiem("A", false);
    addUnitAlias("hour", "h");
    addUnitPriority("hour", 13);
    function matchMeridiem(isStrict, locale) {
        return locale._meridiemParse;
    }
    addRegexToken("a", matchMeridiem);
    addRegexToken("A", matchMeridiem);
    addRegexToken("H", match1to2);
    addRegexToken("h", match1to2);
    addRegexToken("k", match1to2);
    addRegexToken("HH", match1to2, match2);
    addRegexToken("hh", match1to2, match2);
    addRegexToken("kk", match1to2, match2);
    addRegexToken("hmm", match3to4);
    addRegexToken("hmmss", match5to6);
    addRegexToken("Hmm", match3to4);
    addRegexToken("Hmmss", match5to6);
    addParseToken([ "H", "HH" ], HOUR);
    addParseToken([ "k", "kk" ], function(input, array, config) {
        var kInput = toInt(input);
        array[HOUR] = kInput === 24 ? 0 : kInput;
    });
    addParseToken([ "a", "A" ], function(input, array, config) {
        config._isPm = config._locale.isPM(input);
        config._meridiem = input;
    });
    addParseToken([ "h", "hh" ], function(input, array, config) {
        array[HOUR] = toInt(input);
        getParsingFlags(config).bigHour = true;
    });
    addParseToken("hmm", function(input, array, config) {
        var pos = input.length - 2;
        array[HOUR] = toInt(input.substr(0, pos));
        array[MINUTE] = toInt(input.substr(pos));
        getParsingFlags(config).bigHour = true;
    });
    addParseToken("hmmss", function(input, array, config) {
        var pos1 = input.length - 4;
        var pos2 = input.length - 2;
        array[HOUR] = toInt(input.substr(0, pos1));
        array[MINUTE] = toInt(input.substr(pos1, 2));
        array[SECOND] = toInt(input.substr(pos2));
        getParsingFlags(config).bigHour = true;
    });
    addParseToken("Hmm", function(input, array, config) {
        var pos = input.length - 2;
        array[HOUR] = toInt(input.substr(0, pos));
        array[MINUTE] = toInt(input.substr(pos));
    });
    addParseToken("Hmmss", function(input, array, config) {
        var pos1 = input.length - 4;
        var pos2 = input.length - 2;
        array[HOUR] = toInt(input.substr(0, pos1));
        array[MINUTE] = toInt(input.substr(pos1, 2));
        array[SECOND] = toInt(input.substr(pos2));
    });
    function localeIsPM(input) {
        return (input + "").toLowerCase().charAt(0) === "p";
    }
    var defaultLocaleMeridiemParse = /[ap]\.?m?\.?/i;
    function localeMeridiem(hours, minutes, isLower) {
        if (hours > 11) {
            return isLower ? "pm" : "PM";
        } else {
            return isLower ? "am" : "AM";
        }
    }
    var getSetHour = makeGetSet("Hours", true);
    var baseConfig = {
        calendar: defaultCalendar,
        longDateFormat: defaultLongDateFormat,
        invalidDate: defaultInvalidDate,
        ordinal: defaultOrdinal,
        dayOfMonthOrdinalParse: defaultDayOfMonthOrdinalParse,
        relativeTime: defaultRelativeTime,
        months: defaultLocaleMonths,
        monthsShort: defaultLocaleMonthsShort,
        week: defaultLocaleWeek,
        weekdays: defaultLocaleWeekdays,
        weekdaysMin: defaultLocaleWeekdaysMin,
        weekdaysShort: defaultLocaleWeekdaysShort,
        meridiemParse: defaultLocaleMeridiemParse
    };
    var locales = {};
    var localeFamilies = {};
    var globalLocale;
    function normalizeLocale(key) {
        return key ? key.toLowerCase().replace("_", "-") : key;
    }
    function chooseLocale(names) {
        var i = 0, j, next, locale, split;
        while (i < names.length) {
            split = normalizeLocale(names[i]).split("-");
            j = split.length;
            next = normalizeLocale(names[i + 1]);
            next = next ? next.split("-") : null;
            while (j > 0) {
                locale = loadLocale(split.slice(0, j).join("-"));
                if (locale) {
                    return locale;
                }
                if (next && next.length >= j && compareArrays(split, next, true) >= j - 1) {
                    break;
                }
                j--;
            }
            i++;
        }
        return globalLocale;
    }
    function loadLocale(name) {
        var oldLocale = null;
        if (!locales[name] && typeof module !== "undefined" && module && module.exports) {
            try {
                oldLocale = globalLocale._abbr;
                var aliasedRequire = require;
                aliasedRequire("./locale/" + name);
                getSetGlobalLocale(oldLocale);
            } catch (e) {}
        }
        return locales[name];
    }
    function getSetGlobalLocale(key, values) {
        var data;
        if (key) {
            if (isUndefined(values)) {
                data = getLocale(key);
            } else {
                data = defineLocale(key, values);
            }
            if (data) {
                globalLocale = data;
            } else {
                if (typeof console !== "undefined" && console.warn) {
                    console.warn("Locale " + key + " not found. Did you forget to load it?");
                }
            }
        }
        return globalLocale._abbr;
    }
    function defineLocale(name, config) {
        if (config !== null) {
            var locale, parentConfig = baseConfig;
            config.abbr = name;
            if (locales[name] != null) {
                deprecateSimple("defineLocaleOverride", "use moment.updateLocale(localeName, config) to change " + "an existing locale. moment.defineLocale(localeName, " + "config) should only be used for creating a new locale " + "See http://momentjs.com/guides/#/warnings/define-locale/ for more info.");
                parentConfig = locales[name]._config;
            } else if (config.parentLocale != null) {
                if (locales[config.parentLocale] != null) {
                    parentConfig = locales[config.parentLocale]._config;
                } else {
                    locale = loadLocale(config.parentLocale);
                    if (locale != null) {
                        parentConfig = locale._config;
                    } else {
                        if (!localeFamilies[config.parentLocale]) {
                            localeFamilies[config.parentLocale] = [];
                        }
                        localeFamilies[config.parentLocale].push({
                            name: name,
                            config: config
                        });
                        return null;
                    }
                }
            }
            locales[name] = new Locale(mergeConfigs(parentConfig, config));
            if (localeFamilies[name]) {
                localeFamilies[name].forEach(function(x) {
                    defineLocale(x.name, x.config);
                });
            }
            getSetGlobalLocale(name);
            return locales[name];
        } else {
            delete locales[name];
            return null;
        }
    }
    function updateLocale(name, config) {
        if (config != null) {
            var locale, tmpLocale, parentConfig = baseConfig;
            tmpLocale = loadLocale(name);
            if (tmpLocale != null) {
                parentConfig = tmpLocale._config;
            }
            config = mergeConfigs(parentConfig, config);
            locale = new Locale(config);
            locale.parentLocale = locales[name];
            locales[name] = locale;
            getSetGlobalLocale(name);
        } else {
            if (locales[name] != null) {
                if (locales[name].parentLocale != null) {
                    locales[name] = locales[name].parentLocale;
                } else if (locales[name] != null) {
                    delete locales[name];
                }
            }
        }
        return locales[name];
    }
    function getLocale(key) {
        var locale;
        if (key && key._locale && key._locale._abbr) {
            key = key._locale._abbr;
        }
        if (!key) {
            return globalLocale;
        }
        if (!isArray(key)) {
            locale = loadLocale(key);
            if (locale) {
                return locale;
            }
            key = [ key ];
        }
        return chooseLocale(key);
    }
    function listLocales() {
        return keys(locales);
    }
    function checkOverflow(m) {
        var overflow;
        var a = m._a;
        if (a && getParsingFlags(m).overflow === -2) {
            overflow = a[MONTH] < 0 || a[MONTH] > 11 ? MONTH : a[DATE] < 1 || a[DATE] > daysInMonth(a[YEAR], a[MONTH]) ? DATE : a[HOUR] < 0 || a[HOUR] > 24 || a[HOUR] === 24 && (a[MINUTE] !== 0 || a[SECOND] !== 0 || a[MILLISECOND] !== 0) ? HOUR : a[MINUTE] < 0 || a[MINUTE] > 59 ? MINUTE : a[SECOND] < 0 || a[SECOND] > 59 ? SECOND : a[MILLISECOND] < 0 || a[MILLISECOND] > 999 ? MILLISECOND : -1;
            if (getParsingFlags(m)._overflowDayOfYear && (overflow < YEAR || overflow > DATE)) {
                overflow = DATE;
            }
            if (getParsingFlags(m)._overflowWeeks && overflow === -1) {
                overflow = WEEK;
            }
            if (getParsingFlags(m)._overflowWeekday && overflow === -1) {
                overflow = WEEKDAY;
            }
            getParsingFlags(m).overflow = overflow;
        }
        return m;
    }
    function defaults(a, b, c) {
        if (a != null) {
            return a;
        }
        if (b != null) {
            return b;
        }
        return c;
    }
    function currentDateArray(config) {
        var nowValue = new Date(hooks.now());
        if (config._useUTC) {
            return [ nowValue.getUTCFullYear(), nowValue.getUTCMonth(), nowValue.getUTCDate() ];
        }
        return [ nowValue.getFullYear(), nowValue.getMonth(), nowValue.getDate() ];
    }
    function configFromArray(config) {
        var i, date, input = [], currentDate, expectedWeekday, yearToUse;
        if (config._d) {
            return;
        }
        currentDate = currentDateArray(config);
        if (config._w && config._a[DATE] == null && config._a[MONTH] == null) {
            dayOfYearFromWeekInfo(config);
        }
        if (config._dayOfYear != null) {
            yearToUse = defaults(config._a[YEAR], currentDate[YEAR]);
            if (config._dayOfYear > daysInYear(yearToUse) || config._dayOfYear === 0) {
                getParsingFlags(config)._overflowDayOfYear = true;
            }
            date = createUTCDate(yearToUse, 0, config._dayOfYear);
            config._a[MONTH] = date.getUTCMonth();
            config._a[DATE] = date.getUTCDate();
        }
        for (i = 0; i < 3 && config._a[i] == null; ++i) {
            config._a[i] = input[i] = currentDate[i];
        }
        for (;i < 7; i++) {
            config._a[i] = input[i] = config._a[i] == null ? i === 2 ? 1 : 0 : config._a[i];
        }
        if (config._a[HOUR] === 24 && config._a[MINUTE] === 0 && config._a[SECOND] === 0 && config._a[MILLISECOND] === 0) {
            config._nextDay = true;
            config._a[HOUR] = 0;
        }
        config._d = (config._useUTC ? createUTCDate : createDate).apply(null, input);
        expectedWeekday = config._useUTC ? config._d.getUTCDay() : config._d.getDay();
        if (config._tzm != null) {
            config._d.setUTCMinutes(config._d.getUTCMinutes() - config._tzm);
        }
        if (config._nextDay) {
            config._a[HOUR] = 24;
        }
        if (config._w && typeof config._w.d !== "undefined" && config._w.d !== expectedWeekday) {
            getParsingFlags(config).weekdayMismatch = true;
        }
    }
    function dayOfYearFromWeekInfo(config) {
        var w, weekYear, week, weekday, dow, doy, temp, weekdayOverflow;
        w = config._w;
        if (w.GG != null || w.W != null || w.E != null) {
            dow = 1;
            doy = 4;
            weekYear = defaults(w.GG, config._a[YEAR], weekOfYear(createLocal(), 1, 4).year);
            week = defaults(w.W, 1);
            weekday = defaults(w.E, 1);
            if (weekday < 1 || weekday > 7) {
                weekdayOverflow = true;
            }
        } else {
            dow = config._locale._week.dow;
            doy = config._locale._week.doy;
            var curWeek = weekOfYear(createLocal(), dow, doy);
            weekYear = defaults(w.gg, config._a[YEAR], curWeek.year);
            week = defaults(w.w, curWeek.week);
            if (w.d != null) {
                weekday = w.d;
                if (weekday < 0 || weekday > 6) {
                    weekdayOverflow = true;
                }
            } else if (w.e != null) {
                weekday = w.e + dow;
                if (w.e < 0 || w.e > 6) {
                    weekdayOverflow = true;
                }
            } else {
                weekday = dow;
            }
        }
        if (week < 1 || week > weeksInYear(weekYear, dow, doy)) {
            getParsingFlags(config)._overflowWeeks = true;
        } else if (weekdayOverflow != null) {
            getParsingFlags(config)._overflowWeekday = true;
        } else {
            temp = dayOfYearFromWeeks(weekYear, week, weekday, dow, doy);
            config._a[YEAR] = temp.year;
            config._dayOfYear = temp.dayOfYear;
        }
    }
    var extendedIsoRegex = /^\s*((?:[+-]\d{6}|\d{4})-(?:\d\d-\d\d|W\d\d-\d|W\d\d|\d\d\d|\d\d))(?:(T| )(\d\d(?::\d\d(?::\d\d(?:[.,]\d+)?)?)?)([\+\-]\d\d(?::?\d\d)?|\s*Z)?)?$/;
    var basicIsoRegex = /^\s*((?:[+-]\d{6}|\d{4})(?:\d\d\d\d|W\d\d\d|W\d\d|\d\d\d|\d\d))(?:(T| )(\d\d(?:\d\d(?:\d\d(?:[.,]\d+)?)?)?)([\+\-]\d\d(?::?\d\d)?|\s*Z)?)?$/;
    var tzRegex = /Z|[+-]\d\d(?::?\d\d)?/;
    var isoDates = [ [ "YYYYYY-MM-DD", /[+-]\d{6}-\d\d-\d\d/ ], [ "YYYY-MM-DD", /\d{4}-\d\d-\d\d/ ], [ "GGGG-[W]WW-E", /\d{4}-W\d\d-\d/ ], [ "GGGG-[W]WW", /\d{4}-W\d\d/, false ], [ "YYYY-DDD", /\d{4}-\d{3}/ ], [ "YYYY-MM", /\d{4}-\d\d/, false ], [ "YYYYYYMMDD", /[+-]\d{10}/ ], [ "YYYYMMDD", /\d{8}/ ], [ "GGGG[W]WWE", /\d{4}W\d{3}/ ], [ "GGGG[W]WW", /\d{4}W\d{2}/, false ], [ "YYYYDDD", /\d{7}/ ] ];
    var isoTimes = [ [ "HH:mm:ss.SSSS", /\d\d:\d\d:\d\d\.\d+/ ], [ "HH:mm:ss,SSSS", /\d\d:\d\d:\d\d,\d+/ ], [ "HH:mm:ss", /\d\d:\d\d:\d\d/ ], [ "HH:mm", /\d\d:\d\d/ ], [ "HHmmss.SSSS", /\d\d\d\d\d\d\.\d+/ ], [ "HHmmss,SSSS", /\d\d\d\d\d\d,\d+/ ], [ "HHmmss", /\d\d\d\d\d\d/ ], [ "HHmm", /\d\d\d\d/ ], [ "HH", /\d\d/ ] ];
    var aspNetJsonRegex = /^\/?Date\((\-?\d+)/i;
    function configFromISO(config) {
        var i, l, string = config._i, match = extendedIsoRegex.exec(string) || basicIsoRegex.exec(string), allowTime, dateFormat, timeFormat, tzFormat;
        if (match) {
            getParsingFlags(config).iso = true;
            for (i = 0, l = isoDates.length; i < l; i++) {
                if (isoDates[i][1].exec(match[1])) {
                    dateFormat = isoDates[i][0];
                    allowTime = isoDates[i][2] !== false;
                    break;
                }
            }
            if (dateFormat == null) {
                config._isValid = false;
                return;
            }
            if (match[3]) {
                for (i = 0, l = isoTimes.length; i < l; i++) {
                    if (isoTimes[i][1].exec(match[3])) {
                        timeFormat = (match[2] || " ") + isoTimes[i][0];
                        break;
                    }
                }
                if (timeFormat == null) {
                    config._isValid = false;
                    return;
                }
            }
            if (!allowTime && timeFormat != null) {
                config._isValid = false;
                return;
            }
            if (match[4]) {
                if (tzRegex.exec(match[4])) {
                    tzFormat = "Z";
                } else {
                    config._isValid = false;
                    return;
                }
            }
            config._f = dateFormat + (timeFormat || "") + (tzFormat || "");
            configFromStringAndFormat(config);
        } else {
            config._isValid = false;
        }
    }
    var rfc2822 = /^(?:(Mon|Tue|Wed|Thu|Fri|Sat|Sun),?\s)?(\d{1,2})\s(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s(\d{2,4})\s(\d\d):(\d\d)(?::(\d\d))?\s(?:(UT|GMT|[ECMP][SD]T)|([Zz])|([+-]\d{4}))$/;
    function extractFromRFC2822Strings(yearStr, monthStr, dayStr, hourStr, minuteStr, secondStr) {
        var result = [ untruncateYear(yearStr), defaultLocaleMonthsShort.indexOf(monthStr), parseInt(dayStr, 10), parseInt(hourStr, 10), parseInt(minuteStr, 10) ];
        if (secondStr) {
            result.push(parseInt(secondStr, 10));
        }
        return result;
    }
    function untruncateYear(yearStr) {
        var year = parseInt(yearStr, 10);
        if (year <= 49) {
            return 2e3 + year;
        } else if (year <= 999) {
            return 1900 + year;
        }
        return year;
    }
    function preprocessRFC2822(s) {
        return s.replace(/\([^)]*\)|[\n\t]/g, " ").replace(/(\s\s+)/g, " ").replace(/^\s\s*/, "").replace(/\s\s*$/, "");
    }
    function checkWeekday(weekdayStr, parsedInput, config) {
        if (weekdayStr) {
            var weekdayProvided = defaultLocaleWeekdaysShort.indexOf(weekdayStr), weekdayActual = new Date(parsedInput[0], parsedInput[1], parsedInput[2]).getDay();
            if (weekdayProvided !== weekdayActual) {
                getParsingFlags(config).weekdayMismatch = true;
                config._isValid = false;
                return false;
            }
        }
        return true;
    }
    var obsOffsets = {
        UT: 0,
        GMT: 0,
        EDT: -4 * 60,
        EST: -5 * 60,
        CDT: -5 * 60,
        CST: -6 * 60,
        MDT: -6 * 60,
        MST: -7 * 60,
        PDT: -7 * 60,
        PST: -8 * 60
    };
    function calculateOffset(obsOffset, militaryOffset, numOffset) {
        if (obsOffset) {
            return obsOffsets[obsOffset];
        } else if (militaryOffset) {
            return 0;
        } else {
            var hm = parseInt(numOffset, 10);
            var m = hm % 100, h = (hm - m) / 100;
            return h * 60 + m;
        }
    }
    function configFromRFC2822(config) {
        var match = rfc2822.exec(preprocessRFC2822(config._i));
        if (match) {
            var parsedArray = extractFromRFC2822Strings(match[4], match[3], match[2], match[5], match[6], match[7]);
            if (!checkWeekday(match[1], parsedArray, config)) {
                return;
            }
            config._a = parsedArray;
            config._tzm = calculateOffset(match[8], match[9], match[10]);
            config._d = createUTCDate.apply(null, config._a);
            config._d.setUTCMinutes(config._d.getUTCMinutes() - config._tzm);
            getParsingFlags(config).rfc2822 = true;
        } else {
            config._isValid = false;
        }
    }
    function configFromString(config) {
        var matched = aspNetJsonRegex.exec(config._i);
        if (matched !== null) {
            config._d = new Date(+matched[1]);
            return;
        }
        configFromISO(config);
        if (config._isValid === false) {
            delete config._isValid;
        } else {
            return;
        }
        configFromRFC2822(config);
        if (config._isValid === false) {
            delete config._isValid;
        } else {
            return;
        }
        hooks.createFromInputFallback(config);
    }
    hooks.createFromInputFallback = deprecate("value provided is not in a recognized RFC2822 or ISO format. moment construction falls back to js Date(), " + "which is not reliable across all browsers and versions. Non RFC2822/ISO date formats are " + "discouraged and will be removed in an upcoming major release. Please refer to " + "http://momentjs.com/guides/#/warnings/js-date/ for more info.", function(config) {
        config._d = new Date(config._i + (config._useUTC ? " UTC" : ""));
    });
    hooks.ISO_8601 = function() {};
    hooks.RFC_2822 = function() {};
    function configFromStringAndFormat(config) {
        if (config._f === hooks.ISO_8601) {
            configFromISO(config);
            return;
        }
        if (config._f === hooks.RFC_2822) {
            configFromRFC2822(config);
            return;
        }
        config._a = [];
        getParsingFlags(config).empty = true;
        var string = "" + config._i, i, parsedInput, tokens, token, skipped, stringLength = string.length, totalParsedInputLength = 0;
        tokens = expandFormat(config._f, config._locale).match(formattingTokens) || [];
        for (i = 0; i < tokens.length; i++) {
            token = tokens[i];
            parsedInput = (string.match(getParseRegexForToken(token, config)) || [])[0];
            if (parsedInput) {
                skipped = string.substr(0, string.indexOf(parsedInput));
                if (skipped.length > 0) {
                    getParsingFlags(config).unusedInput.push(skipped);
                }
                string = string.slice(string.indexOf(parsedInput) + parsedInput.length);
                totalParsedInputLength += parsedInput.length;
            }
            if (formatTokenFunctions[token]) {
                if (parsedInput) {
                    getParsingFlags(config).empty = false;
                } else {
                    getParsingFlags(config).unusedTokens.push(token);
                }
                addTimeToArrayFromToken(token, parsedInput, config);
            } else if (config._strict && !parsedInput) {
                getParsingFlags(config).unusedTokens.push(token);
            }
        }
        getParsingFlags(config).charsLeftOver = stringLength - totalParsedInputLength;
        if (string.length > 0) {
            getParsingFlags(config).unusedInput.push(string);
        }
        if (config._a[HOUR] <= 12 && getParsingFlags(config).bigHour === true && config._a[HOUR] > 0) {
            getParsingFlags(config).bigHour = undefined;
        }
        getParsingFlags(config).parsedDateParts = config._a.slice(0);
        getParsingFlags(config).meridiem = config._meridiem;
        config._a[HOUR] = meridiemFixWrap(config._locale, config._a[HOUR], config._meridiem);
        configFromArray(config);
        checkOverflow(config);
    }
    function meridiemFixWrap(locale, hour, meridiem) {
        var isPm;
        if (meridiem == null) {
            return hour;
        }
        if (locale.meridiemHour != null) {
            return locale.meridiemHour(hour, meridiem);
        } else if (locale.isPM != null) {
            isPm = locale.isPM(meridiem);
            if (isPm && hour < 12) {
                hour += 12;
            }
            if (!isPm && hour === 12) {
                hour = 0;
            }
            return hour;
        } else {
            return hour;
        }
    }
    function configFromStringAndArray(config) {
        var tempConfig, bestMoment, scoreToBeat, i, currentScore;
        if (config._f.length === 0) {
            getParsingFlags(config).invalidFormat = true;
            config._d = new Date(NaN);
            return;
        }
        for (i = 0; i < config._f.length; i++) {
            currentScore = 0;
            tempConfig = copyConfig({}, config);
            if (config._useUTC != null) {
                tempConfig._useUTC = config._useUTC;
            }
            tempConfig._f = config._f[i];
            configFromStringAndFormat(tempConfig);
            if (!isValid(tempConfig)) {
                continue;
            }
            currentScore += getParsingFlags(tempConfig).charsLeftOver;
            currentScore += getParsingFlags(tempConfig).unusedTokens.length * 10;
            getParsingFlags(tempConfig).score = currentScore;
            if (scoreToBeat == null || currentScore < scoreToBeat) {
                scoreToBeat = currentScore;
                bestMoment = tempConfig;
            }
        }
        extend(config, bestMoment || tempConfig);
    }
    function configFromObject(config) {
        if (config._d) {
            return;
        }
        var i = normalizeObjectUnits(config._i);
        config._a = map([ i.year, i.month, i.day || i.date, i.hour, i.minute, i.second, i.millisecond ], function(obj) {
            return obj && parseInt(obj, 10);
        });
        configFromArray(config);
    }
    function createFromConfig(config) {
        var res = new Moment(checkOverflow(prepareConfig(config)));
        if (res._nextDay) {
            res.add(1, "d");
            res._nextDay = undefined;
        }
        return res;
    }
    function prepareConfig(config) {
        var input = config._i, format = config._f;
        config._locale = config._locale || getLocale(config._l);
        if (input === null || format === undefined && input === "") {
            return createInvalid({
                nullInput: true
            });
        }
        if (typeof input === "string") {
            config._i = input = config._locale.preparse(input);
        }
        if (isMoment(input)) {
            return new Moment(checkOverflow(input));
        } else if (isDate(input)) {
            config._d = input;
        } else if (isArray(format)) {
            configFromStringAndArray(config);
        } else if (format) {
            configFromStringAndFormat(config);
        } else {
            configFromInput(config);
        }
        if (!isValid(config)) {
            config._d = null;
        }
        return config;
    }
    function configFromInput(config) {
        var input = config._i;
        if (isUndefined(input)) {
            config._d = new Date(hooks.now());
        } else if (isDate(input)) {
            config._d = new Date(input.valueOf());
        } else if (typeof input === "string") {
            configFromString(config);
        } else if (isArray(input)) {
            config._a = map(input.slice(0), function(obj) {
                return parseInt(obj, 10);
            });
            configFromArray(config);
        } else if (isObject(input)) {
            configFromObject(config);
        } else if (isNumber(input)) {
            config._d = new Date(input);
        } else {
            hooks.createFromInputFallback(config);
        }
    }
    function createLocalOrUTC(input, format, locale, strict, isUTC) {
        var c = {};
        if (locale === true || locale === false) {
            strict = locale;
            locale = undefined;
        }
        if (isObject(input) && isObjectEmpty(input) || isArray(input) && input.length === 0) {
            input = undefined;
        }
        c._isAMomentObject = true;
        c._useUTC = c._isUTC = isUTC;
        c._l = locale;
        c._i = input;
        c._f = format;
        c._strict = strict;
        return createFromConfig(c);
    }
    function createLocal(input, format, locale, strict) {
        return createLocalOrUTC(input, format, locale, strict, false);
    }
    var prototypeMin = deprecate("moment().min is deprecated, use moment.max instead. http://momentjs.com/guides/#/warnings/min-max/", function() {
        var other = createLocal.apply(null, arguments);
        if (this.isValid() && other.isValid()) {
            return other < this ? this : other;
        } else {
            return createInvalid();
        }
    });
    var prototypeMax = deprecate("moment().max is deprecated, use moment.min instead. http://momentjs.com/guides/#/warnings/min-max/", function() {
        var other = createLocal.apply(null, arguments);
        if (this.isValid() && other.isValid()) {
            return other > this ? this : other;
        } else {
            return createInvalid();
        }
    });
    function pickBy(fn, moments) {
        var res, i;
        if (moments.length === 1 && isArray(moments[0])) {
            moments = moments[0];
        }
        if (!moments.length) {
            return createLocal();
        }
        res = moments[0];
        for (i = 1; i < moments.length; ++i) {
            if (!moments[i].isValid() || moments[i][fn](res)) {
                res = moments[i];
            }
        }
        return res;
    }
    function min() {
        var args = [].slice.call(arguments, 0);
        return pickBy("isBefore", args);
    }
    function max() {
        var args = [].slice.call(arguments, 0);
        return pickBy("isAfter", args);
    }
    var now = function() {
        return Date.now ? Date.now() : +new Date();
    };
    var ordering = [ "year", "quarter", "month", "week", "day", "hour", "minute", "second", "millisecond" ];
    function isDurationValid(m) {
        for (var key in m) {
            if (!(indexOf.call(ordering, key) !== -1 && (m[key] == null || !isNaN(m[key])))) {
                return false;
            }
        }
        var unitHasDecimal = false;
        for (var i = 0; i < ordering.length; ++i) {
            if (m[ordering[i]]) {
                if (unitHasDecimal) {
                    return false;
                }
                if (parseFloat(m[ordering[i]]) !== toInt(m[ordering[i]])) {
                    unitHasDecimal = true;
                }
            }
        }
        return true;
    }
    function isValid$1() {
        return this._isValid;
    }
    function createInvalid$1() {
        return createDuration(NaN);
    }
    function Duration(duration) {
        var normalizedInput = normalizeObjectUnits(duration), years = normalizedInput.year || 0, quarters = normalizedInput.quarter || 0, months = normalizedInput.month || 0, weeks = normalizedInput.week || normalizedInput.isoWeek || 0, days = normalizedInput.day || 0, hours = normalizedInput.hour || 0, minutes = normalizedInput.minute || 0, seconds = normalizedInput.second || 0, milliseconds = normalizedInput.millisecond || 0;
        this._isValid = isDurationValid(normalizedInput);
        this._milliseconds = +milliseconds + seconds * 1e3 + minutes * 6e4 + hours * 1e3 * 60 * 60;
        this._days = +days + weeks * 7;
        this._months = +months + quarters * 3 + years * 12;
        this._data = {};
        this._locale = getLocale();
        this._bubble();
    }
    function isDuration(obj) {
        return obj instanceof Duration;
    }
    function absRound(number) {
        if (number < 0) {
            return Math.round(-1 * number) * -1;
        } else {
            return Math.round(number);
        }
    }
    function offset(token, separator) {
        addFormatToken(token, 0, 0, function() {
            var offset = this.utcOffset();
            var sign = "+";
            if (offset < 0) {
                offset = -offset;
                sign = "-";
            }
            return sign + zeroFill(~~(offset / 60), 2) + separator + zeroFill(~~offset % 60, 2);
        });
    }
    offset("Z", ":");
    offset("ZZ", "");
    addRegexToken("Z", matchShortOffset);
    addRegexToken("ZZ", matchShortOffset);
    addParseToken([ "Z", "ZZ" ], function(input, array, config) {
        config._useUTC = true;
        config._tzm = offsetFromString(matchShortOffset, input);
    });
    var chunkOffset = /([\+\-]|\d\d)/gi;
    function offsetFromString(matcher, string) {
        var matches = (string || "").match(matcher);
        if (matches === null) {
            return null;
        }
        var chunk = matches[matches.length - 1] || [];
        var parts = (chunk + "").match(chunkOffset) || [ "-", 0, 0 ];
        var minutes = +(parts[1] * 60) + toInt(parts[2]);
        return minutes === 0 ? 0 : parts[0] === "+" ? minutes : -minutes;
    }
    function cloneWithOffset(input, model) {
        var res, diff;
        if (model._isUTC) {
            res = model.clone();
            diff = (isMoment(input) || isDate(input) ? input.valueOf() : createLocal(input).valueOf()) - res.valueOf();
            res._d.setTime(res._d.valueOf() + diff);
            hooks.updateOffset(res, false);
            return res;
        } else {
            return createLocal(input).local();
        }
    }
    function getDateOffset(m) {
        return -Math.round(m._d.getTimezoneOffset() / 15) * 15;
    }
    hooks.updateOffset = function() {};
    function getSetOffset(input, keepLocalTime, keepMinutes) {
        var offset = this._offset || 0, localAdjust;
        if (!this.isValid()) {
            return input != null ? this : NaN;
        }
        if (input != null) {
            if (typeof input === "string") {
                input = offsetFromString(matchShortOffset, input);
                if (input === null) {
                    return this;
                }
            } else if (Math.abs(input) < 16 && !keepMinutes) {
                input = input * 60;
            }
            if (!this._isUTC && keepLocalTime) {
                localAdjust = getDateOffset(this);
            }
            this._offset = input;
            this._isUTC = true;
            if (localAdjust != null) {
                this.add(localAdjust, "m");
            }
            if (offset !== input) {
                if (!keepLocalTime || this._changeInProgress) {
                    addSubtract(this, createDuration(input - offset, "m"), 1, false);
                } else if (!this._changeInProgress) {
                    this._changeInProgress = true;
                    hooks.updateOffset(this, true);
                    this._changeInProgress = null;
                }
            }
            return this;
        } else {
            return this._isUTC ? offset : getDateOffset(this);
        }
    }
    function getSetZone(input, keepLocalTime) {
        if (input != null) {
            if (typeof input !== "string") {
                input = -input;
            }
            this.utcOffset(input, keepLocalTime);
            return this;
        } else {
            return -this.utcOffset();
        }
    }
    function setOffsetToUTC(keepLocalTime) {
        return this.utcOffset(0, keepLocalTime);
    }
    function setOffsetToLocal(keepLocalTime) {
        if (this._isUTC) {
            this.utcOffset(0, keepLocalTime);
            this._isUTC = false;
            if (keepLocalTime) {
                this.subtract(getDateOffset(this), "m");
            }
        }
        return this;
    }
    function setOffsetToParsedOffset() {
        if (this._tzm != null) {
            this.utcOffset(this._tzm, false, true);
        } else if (typeof this._i === "string") {
            var tZone = offsetFromString(matchOffset, this._i);
            if (tZone != null) {
                this.utcOffset(tZone);
            } else {
                this.utcOffset(0, true);
            }
        }
        return this;
    }
    function hasAlignedHourOffset(input) {
        if (!this.isValid()) {
            return false;
        }
        input = input ? createLocal(input).utcOffset() : 0;
        return (this.utcOffset() - input) % 60 === 0;
    }
    function isDaylightSavingTime() {
        return this.utcOffset() > this.clone().month(0).utcOffset() || this.utcOffset() > this.clone().month(5).utcOffset();
    }
    function isDaylightSavingTimeShifted() {
        if (!isUndefined(this._isDSTShifted)) {
            return this._isDSTShifted;
        }
        var c = {};
        copyConfig(c, this);
        c = prepareConfig(c);
        if (c._a) {
            var other = c._isUTC ? createUTC(c._a) : createLocal(c._a);
            this._isDSTShifted = this.isValid() && compareArrays(c._a, other.toArray()) > 0;
        } else {
            this._isDSTShifted = false;
        }
        return this._isDSTShifted;
    }
    function isLocal() {
        return this.isValid() ? !this._isUTC : false;
    }
    function isUtcOffset() {
        return this.isValid() ? this._isUTC : false;
    }
    function isUtc() {
        return this.isValid() ? this._isUTC && this._offset === 0 : false;
    }
    var aspNetRegex = /^(\-|\+)?(?:(\d*)[. ])?(\d+)\:(\d+)(?:\:(\d+)(\.\d*)?)?$/;
    var isoRegex = /^(-|\+)?P(?:([-+]?[0-9,.]*)Y)?(?:([-+]?[0-9,.]*)M)?(?:([-+]?[0-9,.]*)W)?(?:([-+]?[0-9,.]*)D)?(?:T(?:([-+]?[0-9,.]*)H)?(?:([-+]?[0-9,.]*)M)?(?:([-+]?[0-9,.]*)S)?)?$/;
    function createDuration(input, key) {
        var duration = input, match = null, sign, ret, diffRes;
        if (isDuration(input)) {
            duration = {
                ms: input._milliseconds,
                d: input._days,
                M: input._months
            };
        } else if (isNumber(input)) {
            duration = {};
            if (key) {
                duration[key] = input;
            } else {
                duration.milliseconds = input;
            }
        } else if (!!(match = aspNetRegex.exec(input))) {
            sign = match[1] === "-" ? -1 : 1;
            duration = {
                y: 0,
                d: toInt(match[DATE]) * sign,
                h: toInt(match[HOUR]) * sign,
                m: toInt(match[MINUTE]) * sign,
                s: toInt(match[SECOND]) * sign,
                ms: toInt(absRound(match[MILLISECOND] * 1e3)) * sign
            };
        } else if (!!(match = isoRegex.exec(input))) {
            sign = match[1] === "-" ? -1 : 1;
            duration = {
                y: parseIso(match[2], sign),
                M: parseIso(match[3], sign),
                w: parseIso(match[4], sign),
                d: parseIso(match[5], sign),
                h: parseIso(match[6], sign),
                m: parseIso(match[7], sign),
                s: parseIso(match[8], sign)
            };
        } else if (duration == null) {
            duration = {};
        } else if (typeof duration === "object" && ("from" in duration || "to" in duration)) {
            diffRes = momentsDifference(createLocal(duration.from), createLocal(duration.to));
            duration = {};
            duration.ms = diffRes.milliseconds;
            duration.M = diffRes.months;
        }
        ret = new Duration(duration);
        if (isDuration(input) && hasOwnProp(input, "_locale")) {
            ret._locale = input._locale;
        }
        return ret;
    }
    createDuration.fn = Duration.prototype;
    createDuration.invalid = createInvalid$1;
    function parseIso(inp, sign) {
        var res = inp && parseFloat(inp.replace(",", "."));
        return (isNaN(res) ? 0 : res) * sign;
    }
    function positiveMomentsDifference(base, other) {
        var res = {};
        res.months = other.month() - base.month() + (other.year() - base.year()) * 12;
        if (base.clone().add(res.months, "M").isAfter(other)) {
            --res.months;
        }
        res.milliseconds = +other - +base.clone().add(res.months, "M");
        return res;
    }
    function momentsDifference(base, other) {
        var res;
        if (!(base.isValid() && other.isValid())) {
            return {
                milliseconds: 0,
                months: 0
            };
        }
        other = cloneWithOffset(other, base);
        if (base.isBefore(other)) {
            res = positiveMomentsDifference(base, other);
        } else {
            res = positiveMomentsDifference(other, base);
            res.milliseconds = -res.milliseconds;
            res.months = -res.months;
        }
        return res;
    }
    function createAdder(direction, name) {
        return function(val, period) {
            var dur, tmp;
            if (period !== null && !isNaN(+period)) {
                deprecateSimple(name, "moment()." + name + "(period, number) is deprecated. Please use moment()." + name + "(number, period). " + "See http://momentjs.com/guides/#/warnings/add-inverted-param/ for more info.");
                tmp = val;
                val = period;
                period = tmp;
            }
            val = typeof val === "string" ? +val : val;
            dur = createDuration(val, period);
            addSubtract(this, dur, direction);
            return this;
        };
    }
    function addSubtract(mom, duration, isAdding, updateOffset) {
        var milliseconds = duration._milliseconds, days = absRound(duration._days), months = absRound(duration._months);
        if (!mom.isValid()) {
            return;
        }
        updateOffset = updateOffset == null ? true : updateOffset;
        if (months) {
            setMonth(mom, get(mom, "Month") + months * isAdding);
        }
        if (days) {
            set$1(mom, "Date", get(mom, "Date") + days * isAdding);
        }
        if (milliseconds) {
            mom._d.setTime(mom._d.valueOf() + milliseconds * isAdding);
        }
        if (updateOffset) {
            hooks.updateOffset(mom, days || months);
        }
    }
    var add = createAdder(1, "add");
    var subtract = createAdder(-1, "subtract");
    function getCalendarFormat(myMoment, now) {
        var diff = myMoment.diff(now, "days", true);
        return diff < -6 ? "sameElse" : diff < -1 ? "lastWeek" : diff < 0 ? "lastDay" : diff < 1 ? "sameDay" : diff < 2 ? "nextDay" : diff < 7 ? "nextWeek" : "sameElse";
    }
    function calendar$1(time, formats) {
        var now = time || createLocal(), sod = cloneWithOffset(now, this).startOf("day"), format = hooks.calendarFormat(this, sod) || "sameElse";
        var output = formats && (isFunction(formats[format]) ? formats[format].call(this, now) : formats[format]);
        return this.format(output || this.localeData().calendar(format, this, createLocal(now)));
    }
    function clone() {
        return new Moment(this);
    }
    function isAfter(input, units) {
        var localInput = isMoment(input) ? input : createLocal(input);
        if (!(this.isValid() && localInput.isValid())) {
            return false;
        }
        units = normalizeUnits(units) || "millisecond";
        if (units === "millisecond") {
            return this.valueOf() > localInput.valueOf();
        } else {
            return localInput.valueOf() < this.clone().startOf(units).valueOf();
        }
    }
    function isBefore(input, units) {
        var localInput = isMoment(input) ? input : createLocal(input);
        if (!(this.isValid() && localInput.isValid())) {
            return false;
        }
        units = normalizeUnits(units) || "millisecond";
        if (units === "millisecond") {
            return this.valueOf() < localInput.valueOf();
        } else {
            return this.clone().endOf(units).valueOf() < localInput.valueOf();
        }
    }
    function isBetween(from, to, units, inclusivity) {
        var localFrom = isMoment(from) ? from : createLocal(from), localTo = isMoment(to) ? to : createLocal(to);
        if (!(this.isValid() && localFrom.isValid() && localTo.isValid())) {
            return false;
        }
        inclusivity = inclusivity || "()";
        return (inclusivity[0] === "(" ? this.isAfter(localFrom, units) : !this.isBefore(localFrom, units)) && (inclusivity[1] === ")" ? this.isBefore(localTo, units) : !this.isAfter(localTo, units));
    }
    function isSame(input, units) {
        var localInput = isMoment(input) ? input : createLocal(input), inputMs;
        if (!(this.isValid() && localInput.isValid())) {
            return false;
        }
        units = normalizeUnits(units) || "millisecond";
        if (units === "millisecond") {
            return this.valueOf() === localInput.valueOf();
        } else {
            inputMs = localInput.valueOf();
            return this.clone().startOf(units).valueOf() <= inputMs && inputMs <= this.clone().endOf(units).valueOf();
        }
    }
    function isSameOrAfter(input, units) {
        return this.isSame(input, units) || this.isAfter(input, units);
    }
    function isSameOrBefore(input, units) {
        return this.isSame(input, units) || this.isBefore(input, units);
    }
    function diff(input, units, asFloat) {
        var that, zoneDelta, output;
        if (!this.isValid()) {
            return NaN;
        }
        that = cloneWithOffset(input, this);
        if (!that.isValid()) {
            return NaN;
        }
        zoneDelta = (that.utcOffset() - this.utcOffset()) * 6e4;
        units = normalizeUnits(units);
        switch (units) {
          case "year":
            output = monthDiff(this, that) / 12;
            break;

          case "month":
            output = monthDiff(this, that);
            break;

          case "quarter":
            output = monthDiff(this, that) / 3;
            break;

          case "second":
            output = (this - that) / 1e3;
            break;

          case "minute":
            output = (this - that) / 6e4;
            break;

          case "hour":
            output = (this - that) / 36e5;
            break;

          case "day":
            output = (this - that - zoneDelta) / 864e5;
            break;

          case "week":
            output = (this - that - zoneDelta) / 6048e5;
            break;

          default:
            output = this - that;
        }
        return asFloat ? output : absFloor(output);
    }
    function monthDiff(a, b) {
        var wholeMonthDiff = (b.year() - a.year()) * 12 + (b.month() - a.month()), anchor = a.clone().add(wholeMonthDiff, "months"), anchor2, adjust;
        if (b - anchor < 0) {
            anchor2 = a.clone().add(wholeMonthDiff - 1, "months");
            adjust = (b - anchor) / (anchor - anchor2);
        } else {
            anchor2 = a.clone().add(wholeMonthDiff + 1, "months");
            adjust = (b - anchor) / (anchor2 - anchor);
        }
        return -(wholeMonthDiff + adjust) || 0;
    }
    hooks.defaultFormat = "YYYY-MM-DDTHH:mm:ssZ";
    hooks.defaultFormatUtc = "YYYY-MM-DDTHH:mm:ss[Z]";
    function toString() {
        return this.clone().locale("en").format("ddd MMM DD YYYY HH:mm:ss [GMT]ZZ");
    }
    function toISOString(keepOffset) {
        if (!this.isValid()) {
            return null;
        }
        var utc = keepOffset !== true;
        var m = utc ? this.clone().utc() : this;
        if (m.year() < 0 || m.year() > 9999) {
            return formatMoment(m, utc ? "YYYYYY-MM-DD[T]HH:mm:ss.SSS[Z]" : "YYYYYY-MM-DD[T]HH:mm:ss.SSSZ");
        }
        if (isFunction(Date.prototype.toISOString)) {
            if (utc) {
                return this.toDate().toISOString();
            } else {
                return new Date(this.valueOf() + this.utcOffset() * 60 * 1e3).toISOString().replace("Z", formatMoment(m, "Z"));
            }
        }
        return formatMoment(m, utc ? "YYYY-MM-DD[T]HH:mm:ss.SSS[Z]" : "YYYY-MM-DD[T]HH:mm:ss.SSSZ");
    }
    function inspect() {
        if (!this.isValid()) {
            return "moment.invalid(/* " + this._i + " */)";
        }
        var func = "moment";
        var zone = "";
        if (!this.isLocal()) {
            func = this.utcOffset() === 0 ? "moment.utc" : "moment.parseZone";
            zone = "Z";
        }
        var prefix = "[" + func + '("]';
        var year = 0 <= this.year() && this.year() <= 9999 ? "YYYY" : "YYYYYY";
        var datetime = "-MM-DD[T]HH:mm:ss.SSS";
        var suffix = zone + '[")]';
        return this.format(prefix + year + datetime + suffix);
    }
    function format(inputString) {
        if (!inputString) {
            inputString = this.isUtc() ? hooks.defaultFormatUtc : hooks.defaultFormat;
        }
        var output = formatMoment(this, inputString);
        return this.localeData().postformat(output);
    }
    function from(time, withoutSuffix) {
        if (this.isValid() && (isMoment(time) && time.isValid() || createLocal(time).isValid())) {
            return createDuration({
                to: this,
                from: time
            }).locale(this.locale()).humanize(!withoutSuffix);
        } else {
            return this.localeData().invalidDate();
        }
    }
    function fromNow(withoutSuffix) {
        return this.from(createLocal(), withoutSuffix);
    }
    function to(time, withoutSuffix) {
        if (this.isValid() && (isMoment(time) && time.isValid() || createLocal(time).isValid())) {
            return createDuration({
                from: this,
                to: time
            }).locale(this.locale()).humanize(!withoutSuffix);
        } else {
            return this.localeData().invalidDate();
        }
    }
    function toNow(withoutSuffix) {
        return this.to(createLocal(), withoutSuffix);
    }
    function locale(key) {
        var newLocaleData;
        if (key === undefined) {
            return this._locale._abbr;
        } else {
            newLocaleData = getLocale(key);
            if (newLocaleData != null) {
                this._locale = newLocaleData;
            }
            return this;
        }
    }
    var lang = deprecate("moment().lang() is deprecated. Instead, use moment().localeData() to get the language configuration. Use moment().locale() to change languages.", function(key) {
        if (key === undefined) {
            return this.localeData();
        } else {
            return this.locale(key);
        }
    });
    function localeData() {
        return this._locale;
    }
    var MS_PER_SECOND = 1e3;
    var MS_PER_MINUTE = 60 * MS_PER_SECOND;
    var MS_PER_HOUR = 60 * MS_PER_MINUTE;
    var MS_PER_400_YEARS = (365 * 400 + 97) * 24 * MS_PER_HOUR;
    function mod$1(dividend, divisor) {
        return (dividend % divisor + divisor) % divisor;
    }
    function localStartOfDate(y, m, d) {
        if (y < 100 && y >= 0) {
            return new Date(y + 400, m, d) - MS_PER_400_YEARS;
        } else {
            return new Date(y, m, d).valueOf();
        }
    }
    function utcStartOfDate(y, m, d) {
        if (y < 100 && y >= 0) {
            return Date.UTC(y + 400, m, d) - MS_PER_400_YEARS;
        } else {
            return Date.UTC(y, m, d);
        }
    }
    function startOf(units) {
        var time;
        units = normalizeUnits(units);
        if (units === undefined || units === "millisecond" || !this.isValid()) {
            return this;
        }
        var startOfDate = this._isUTC ? utcStartOfDate : localStartOfDate;
        switch (units) {
          case "year":
            time = startOfDate(this.year(), 0, 1);
            break;

          case "quarter":
            time = startOfDate(this.year(), this.month() - this.month() % 3, 1);
            break;

          case "month":
            time = startOfDate(this.year(), this.month(), 1);
            break;

          case "week":
            time = startOfDate(this.year(), this.month(), this.date() - this.weekday());
            break;

          case "isoWeek":
            time = startOfDate(this.year(), this.month(), this.date() - (this.isoWeekday() - 1));
            break;

          case "day":
          case "date":
            time = startOfDate(this.year(), this.month(), this.date());
            break;

          case "hour":
            time = this._d.valueOf();
            time -= mod$1(time + (this._isUTC ? 0 : this.utcOffset() * MS_PER_MINUTE), MS_PER_HOUR);
            break;

          case "minute":
            time = this._d.valueOf();
            time -= mod$1(time, MS_PER_MINUTE);
            break;

          case "second":
            time = this._d.valueOf();
            time -= mod$1(time, MS_PER_SECOND);
            break;
        }
        this._d.setTime(time);
        hooks.updateOffset(this, true);
        return this;
    }
    function endOf(units) {
        var time;
        units = normalizeUnits(units);
        if (units === undefined || units === "millisecond" || !this.isValid()) {
            return this;
        }
        var startOfDate = this._isUTC ? utcStartOfDate : localStartOfDate;
        switch (units) {
          case "year":
            time = startOfDate(this.year() + 1, 0, 1) - 1;
            break;

          case "quarter":
            time = startOfDate(this.year(), this.month() - this.month() % 3 + 3, 1) - 1;
            break;

          case "month":
            time = startOfDate(this.year(), this.month() + 1, 1) - 1;
            break;

          case "week":
            time = startOfDate(this.year(), this.month(), this.date() - this.weekday() + 7) - 1;
            break;

          case "isoWeek":
            time = startOfDate(this.year(), this.month(), this.date() - (this.isoWeekday() - 1) + 7) - 1;
            break;

          case "day":
          case "date":
            time = startOfDate(this.year(), this.month(), this.date() + 1) - 1;
            break;

          case "hour":
            time = this._d.valueOf();
            time += MS_PER_HOUR - mod$1(time + (this._isUTC ? 0 : this.utcOffset() * MS_PER_MINUTE), MS_PER_HOUR) - 1;
            break;

          case "minute":
            time = this._d.valueOf();
            time += MS_PER_MINUTE - mod$1(time, MS_PER_MINUTE) - 1;
            break;

          case "second":
            time = this._d.valueOf();
            time += MS_PER_SECOND - mod$1(time, MS_PER_SECOND) - 1;
            break;
        }
        this._d.setTime(time);
        hooks.updateOffset(this, true);
        return this;
    }
    function valueOf() {
        return this._d.valueOf() - (this._offset || 0) * 6e4;
    }
    function unix() {
        return Math.floor(this.valueOf() / 1e3);
    }
    function toDate() {
        return new Date(this.valueOf());
    }
    function toArray() {
        var m = this;
        return [ m.year(), m.month(), m.date(), m.hour(), m.minute(), m.second(), m.millisecond() ];
    }
    function toObject() {
        var m = this;
        return {
            years: m.year(),
            months: m.month(),
            date: m.date(),
            hours: m.hours(),
            minutes: m.minutes(),
            seconds: m.seconds(),
            milliseconds: m.milliseconds()
        };
    }
    function toJSON() {
        return this.isValid() ? this.toISOString() : null;
    }
    function isValid$2() {
        return isValid(this);
    }
    function parsingFlags() {
        return extend({}, getParsingFlags(this));
    }
    function invalidAt() {
        return getParsingFlags(this).overflow;
    }
    function creationData() {
        return {
            input: this._i,
            format: this._f,
            locale: this._locale,
            isUTC: this._isUTC,
            strict: this._strict
        };
    }
    addFormatToken(0, [ "gg", 2 ], 0, function() {
        return this.weekYear() % 100;
    });
    addFormatToken(0, [ "GG", 2 ], 0, function() {
        return this.isoWeekYear() % 100;
    });
    function addWeekYearFormatToken(token, getter) {
        addFormatToken(0, [ token, token.length ], 0, getter);
    }
    addWeekYearFormatToken("gggg", "weekYear");
    addWeekYearFormatToken("ggggg", "weekYear");
    addWeekYearFormatToken("GGGG", "isoWeekYear");
    addWeekYearFormatToken("GGGGG", "isoWeekYear");
    addUnitAlias("weekYear", "gg");
    addUnitAlias("isoWeekYear", "GG");
    addUnitPriority("weekYear", 1);
    addUnitPriority("isoWeekYear", 1);
    addRegexToken("G", matchSigned);
    addRegexToken("g", matchSigned);
    addRegexToken("GG", match1to2, match2);
    addRegexToken("gg", match1to2, match2);
    addRegexToken("GGGG", match1to4, match4);
    addRegexToken("gggg", match1to4, match4);
    addRegexToken("GGGGG", match1to6, match6);
    addRegexToken("ggggg", match1to6, match6);
    addWeekParseToken([ "gggg", "ggggg", "GGGG", "GGGGG" ], function(input, week, config, token) {
        week[token.substr(0, 2)] = toInt(input);
    });
    addWeekParseToken([ "gg", "GG" ], function(input, week, config, token) {
        week[token] = hooks.parseTwoDigitYear(input);
    });
    function getSetWeekYear(input) {
        return getSetWeekYearHelper.call(this, input, this.week(), this.weekday(), this.localeData()._week.dow, this.localeData()._week.doy);
    }
    function getSetISOWeekYear(input) {
        return getSetWeekYearHelper.call(this, input, this.isoWeek(), this.isoWeekday(), 1, 4);
    }
    function getISOWeeksInYear() {
        return weeksInYear(this.year(), 1, 4);
    }
    function getWeeksInYear() {
        var weekInfo = this.localeData()._week;
        return weeksInYear(this.year(), weekInfo.dow, weekInfo.doy);
    }
    function getSetWeekYearHelper(input, week, weekday, dow, doy) {
        var weeksTarget;
        if (input == null) {
            return weekOfYear(this, dow, doy).year;
        } else {
            weeksTarget = weeksInYear(input, dow, doy);
            if (week > weeksTarget) {
                week = weeksTarget;
            }
            return setWeekAll.call(this, input, week, weekday, dow, doy);
        }
    }
    function setWeekAll(weekYear, week, weekday, dow, doy) {
        var dayOfYearData = dayOfYearFromWeeks(weekYear, week, weekday, dow, doy), date = createUTCDate(dayOfYearData.year, 0, dayOfYearData.dayOfYear);
        this.year(date.getUTCFullYear());
        this.month(date.getUTCMonth());
        this.date(date.getUTCDate());
        return this;
    }
    addFormatToken("Q", 0, "Qo", "quarter");
    addUnitAlias("quarter", "Q");
    addUnitPriority("quarter", 7);
    addRegexToken("Q", match1);
    addParseToken("Q", function(input, array) {
        array[MONTH] = (toInt(input) - 1) * 3;
    });
    function getSetQuarter(input) {
        return input == null ? Math.ceil((this.month() + 1) / 3) : this.month((input - 1) * 3 + this.month() % 3);
    }
    addFormatToken("D", [ "DD", 2 ], "Do", "date");
    addUnitAlias("date", "D");
    addUnitPriority("date", 9);
    addRegexToken("D", match1to2);
    addRegexToken("DD", match1to2, match2);
    addRegexToken("Do", function(isStrict, locale) {
        return isStrict ? locale._dayOfMonthOrdinalParse || locale._ordinalParse : locale._dayOfMonthOrdinalParseLenient;
    });
    addParseToken([ "D", "DD" ], DATE);
    addParseToken("Do", function(input, array) {
        array[DATE] = toInt(input.match(match1to2)[0]);
    });
    var getSetDayOfMonth = makeGetSet("Date", true);
    addFormatToken("DDD", [ "DDDD", 3 ], "DDDo", "dayOfYear");
    addUnitAlias("dayOfYear", "DDD");
    addUnitPriority("dayOfYear", 4);
    addRegexToken("DDD", match1to3);
    addRegexToken("DDDD", match3);
    addParseToken([ "DDD", "DDDD" ], function(input, array, config) {
        config._dayOfYear = toInt(input);
    });
    function getSetDayOfYear(input) {
        var dayOfYear = Math.round((this.clone().startOf("day") - this.clone().startOf("year")) / 864e5) + 1;
        return input == null ? dayOfYear : this.add(input - dayOfYear, "d");
    }
    addFormatToken("m", [ "mm", 2 ], 0, "minute");
    addUnitAlias("minute", "m");
    addUnitPriority("minute", 14);
    addRegexToken("m", match1to2);
    addRegexToken("mm", match1to2, match2);
    addParseToken([ "m", "mm" ], MINUTE);
    var getSetMinute = makeGetSet("Minutes", false);
    addFormatToken("s", [ "ss", 2 ], 0, "second");
    addUnitAlias("second", "s");
    addUnitPriority("second", 15);
    addRegexToken("s", match1to2);
    addRegexToken("ss", match1to2, match2);
    addParseToken([ "s", "ss" ], SECOND);
    var getSetSecond = makeGetSet("Seconds", false);
    addFormatToken("S", 0, 0, function() {
        return ~~(this.millisecond() / 100);
    });
    addFormatToken(0, [ "SS", 2 ], 0, function() {
        return ~~(this.millisecond() / 10);
    });
    addFormatToken(0, [ "SSS", 3 ], 0, "millisecond");
    addFormatToken(0, [ "SSSS", 4 ], 0, function() {
        return this.millisecond() * 10;
    });
    addFormatToken(0, [ "SSSSS", 5 ], 0, function() {
        return this.millisecond() * 100;
    });
    addFormatToken(0, [ "SSSSSS", 6 ], 0, function() {
        return this.millisecond() * 1e3;
    });
    addFormatToken(0, [ "SSSSSSS", 7 ], 0, function() {
        return this.millisecond() * 1e4;
    });
    addFormatToken(0, [ "SSSSSSSS", 8 ], 0, function() {
        return this.millisecond() * 1e5;
    });
    addFormatToken(0, [ "SSSSSSSSS", 9 ], 0, function() {
        return this.millisecond() * 1e6;
    });
    addUnitAlias("millisecond", "ms");
    addUnitPriority("millisecond", 16);
    addRegexToken("S", match1to3, match1);
    addRegexToken("SS", match1to3, match2);
    addRegexToken("SSS", match1to3, match3);
    var token;
    for (token = "SSSS"; token.length <= 9; token += "S") {
        addRegexToken(token, matchUnsigned);
    }
    function parseMs(input, array) {
        array[MILLISECOND] = toInt(("0." + input) * 1e3);
    }
    for (token = "S"; token.length <= 9; token += "S") {
        addParseToken(token, parseMs);
    }
    var getSetMillisecond = makeGetSet("Milliseconds", false);
    addFormatToken("z", 0, 0, "zoneAbbr");
    addFormatToken("zz", 0, 0, "zoneName");
    function getZoneAbbr() {
        return this._isUTC ? "UTC" : "";
    }
    function getZoneName() {
        return this._isUTC ? "Coordinated Universal Time" : "";
    }
    var proto = Moment.prototype;
    proto.add = add;
    proto.calendar = calendar$1;
    proto.clone = clone;
    proto.diff = diff;
    proto.endOf = endOf;
    proto.format = format;
    proto.from = from;
    proto.fromNow = fromNow;
    proto.to = to;
    proto.toNow = toNow;
    proto.get = stringGet;
    proto.invalidAt = invalidAt;
    proto.isAfter = isAfter;
    proto.isBefore = isBefore;
    proto.isBetween = isBetween;
    proto.isSame = isSame;
    proto.isSameOrAfter = isSameOrAfter;
    proto.isSameOrBefore = isSameOrBefore;
    proto.isValid = isValid$2;
    proto.lang = lang;
    proto.locale = locale;
    proto.localeData = localeData;
    proto.max = prototypeMax;
    proto.min = prototypeMin;
    proto.parsingFlags = parsingFlags;
    proto.set = stringSet;
    proto.startOf = startOf;
    proto.subtract = subtract;
    proto.toArray = toArray;
    proto.toObject = toObject;
    proto.toDate = toDate;
    proto.toISOString = toISOString;
    proto.inspect = inspect;
    proto.toJSON = toJSON;
    proto.toString = toString;
    proto.unix = unix;
    proto.valueOf = valueOf;
    proto.creationData = creationData;
    proto.year = getSetYear;
    proto.isLeapYear = getIsLeapYear;
    proto.weekYear = getSetWeekYear;
    proto.isoWeekYear = getSetISOWeekYear;
    proto.quarter = proto.quarters = getSetQuarter;
    proto.month = getSetMonth;
    proto.daysInMonth = getDaysInMonth;
    proto.week = proto.weeks = getSetWeek;
    proto.isoWeek = proto.isoWeeks = getSetISOWeek;
    proto.weeksInYear = getWeeksInYear;
    proto.isoWeeksInYear = getISOWeeksInYear;
    proto.date = getSetDayOfMonth;
    proto.day = proto.days = getSetDayOfWeek;
    proto.weekday = getSetLocaleDayOfWeek;
    proto.isoWeekday = getSetISODayOfWeek;
    proto.dayOfYear = getSetDayOfYear;
    proto.hour = proto.hours = getSetHour;
    proto.minute = proto.minutes = getSetMinute;
    proto.second = proto.seconds = getSetSecond;
    proto.millisecond = proto.milliseconds = getSetMillisecond;
    proto.utcOffset = getSetOffset;
    proto.utc = setOffsetToUTC;
    proto.local = setOffsetToLocal;
    proto.parseZone = setOffsetToParsedOffset;
    proto.hasAlignedHourOffset = hasAlignedHourOffset;
    proto.isDST = isDaylightSavingTime;
    proto.isLocal = isLocal;
    proto.isUtcOffset = isUtcOffset;
    proto.isUtc = isUtc;
    proto.isUTC = isUtc;
    proto.zoneAbbr = getZoneAbbr;
    proto.zoneName = getZoneName;
    proto.dates = deprecate("dates accessor is deprecated. Use date instead.", getSetDayOfMonth);
    proto.months = deprecate("months accessor is deprecated. Use month instead", getSetMonth);
    proto.years = deprecate("years accessor is deprecated. Use year instead", getSetYear);
    proto.zone = deprecate("moment().zone is deprecated, use moment().utcOffset instead. http://momentjs.com/guides/#/warnings/zone/", getSetZone);
    proto.isDSTShifted = deprecate("isDSTShifted is deprecated. See http://momentjs.com/guides/#/warnings/dst-shifted/ for more information", isDaylightSavingTimeShifted);
    function createUnix(input) {
        return createLocal(input * 1e3);
    }
    function createInZone() {
        return createLocal.apply(null, arguments).parseZone();
    }
    function preParsePostFormat(string) {
        return string;
    }
    var proto$1 = Locale.prototype;
    proto$1.calendar = calendar;
    proto$1.longDateFormat = longDateFormat;
    proto$1.invalidDate = invalidDate;
    proto$1.ordinal = ordinal;
    proto$1.preparse = preParsePostFormat;
    proto$1.postformat = preParsePostFormat;
    proto$1.relativeTime = relativeTime;
    proto$1.pastFuture = pastFuture;
    proto$1.set = set;
    proto$1.months = localeMonths;
    proto$1.monthsShort = localeMonthsShort;
    proto$1.monthsParse = localeMonthsParse;
    proto$1.monthsRegex = monthsRegex;
    proto$1.monthsShortRegex = monthsShortRegex;
    proto$1.week = localeWeek;
    proto$1.firstDayOfYear = localeFirstDayOfYear;
    proto$1.firstDayOfWeek = localeFirstDayOfWeek;
    proto$1.weekdays = localeWeekdays;
    proto$1.weekdaysMin = localeWeekdaysMin;
    proto$1.weekdaysShort = localeWeekdaysShort;
    proto$1.weekdaysParse = localeWeekdaysParse;
    proto$1.weekdaysRegex = weekdaysRegex;
    proto$1.weekdaysShortRegex = weekdaysShortRegex;
    proto$1.weekdaysMinRegex = weekdaysMinRegex;
    proto$1.isPM = localeIsPM;
    proto$1.meridiem = localeMeridiem;
    function get$1(format, index, field, setter) {
        var locale = getLocale();
        var utc = createUTC().set(setter, index);
        return locale[field](utc, format);
    }
    function listMonthsImpl(format, index, field) {
        if (isNumber(format)) {
            index = format;
            format = undefined;
        }
        format = format || "";
        if (index != null) {
            return get$1(format, index, field, "month");
        }
        var i;
        var out = [];
        for (i = 0; i < 12; i++) {
            out[i] = get$1(format, i, field, "month");
        }
        return out;
    }
    function listWeekdaysImpl(localeSorted, format, index, field) {
        if (typeof localeSorted === "boolean") {
            if (isNumber(format)) {
                index = format;
                format = undefined;
            }
            format = format || "";
        } else {
            format = localeSorted;
            index = format;
            localeSorted = false;
            if (isNumber(format)) {
                index = format;
                format = undefined;
            }
            format = format || "";
        }
        var locale = getLocale(), shift = localeSorted ? locale._week.dow : 0;
        if (index != null) {
            return get$1(format, (index + shift) % 7, field, "day");
        }
        var i;
        var out = [];
        for (i = 0; i < 7; i++) {
            out[i] = get$1(format, (i + shift) % 7, field, "day");
        }
        return out;
    }
    function listMonths(format, index) {
        return listMonthsImpl(format, index, "months");
    }
    function listMonthsShort(format, index) {
        return listMonthsImpl(format, index, "monthsShort");
    }
    function listWeekdays(localeSorted, format, index) {
        return listWeekdaysImpl(localeSorted, format, index, "weekdays");
    }
    function listWeekdaysShort(localeSorted, format, index) {
        return listWeekdaysImpl(localeSorted, format, index, "weekdaysShort");
    }
    function listWeekdaysMin(localeSorted, format, index) {
        return listWeekdaysImpl(localeSorted, format, index, "weekdaysMin");
    }
    getSetGlobalLocale("en", {
        dayOfMonthOrdinalParse: /\d{1,2}(th|st|nd|rd)/,
        ordinal: function(number) {
            var b = number % 10, output = toInt(number % 100 / 10) === 1 ? "th" : b === 1 ? "st" : b === 2 ? "nd" : b === 3 ? "rd" : "th";
            return number + output;
        }
    });
    hooks.lang = deprecate("moment.lang is deprecated. Use moment.locale instead.", getSetGlobalLocale);
    hooks.langData = deprecate("moment.langData is deprecated. Use moment.localeData instead.", getLocale);
    var mathAbs = Math.abs;
    function abs() {
        var data = this._data;
        this._milliseconds = mathAbs(this._milliseconds);
        this._days = mathAbs(this._days);
        this._months = mathAbs(this._months);
        data.milliseconds = mathAbs(data.milliseconds);
        data.seconds = mathAbs(data.seconds);
        data.minutes = mathAbs(data.minutes);
        data.hours = mathAbs(data.hours);
        data.months = mathAbs(data.months);
        data.years = mathAbs(data.years);
        return this;
    }
    function addSubtract$1(duration, input, value, direction) {
        var other = createDuration(input, value);
        duration._milliseconds += direction * other._milliseconds;
        duration._days += direction * other._days;
        duration._months += direction * other._months;
        return duration._bubble();
    }
    function add$1(input, value) {
        return addSubtract$1(this, input, value, 1);
    }
    function subtract$1(input, value) {
        return addSubtract$1(this, input, value, -1);
    }
    function absCeil(number) {
        if (number < 0) {
            return Math.floor(number);
        } else {
            return Math.ceil(number);
        }
    }
    function bubble() {
        var milliseconds = this._milliseconds;
        var days = this._days;
        var months = this._months;
        var data = this._data;
        var seconds, minutes, hours, years, monthsFromDays;
        if (!(milliseconds >= 0 && days >= 0 && months >= 0 || milliseconds <= 0 && days <= 0 && months <= 0)) {
            milliseconds += absCeil(monthsToDays(months) + days) * 864e5;
            days = 0;
            months = 0;
        }
        data.milliseconds = milliseconds % 1e3;
        seconds = absFloor(milliseconds / 1e3);
        data.seconds = seconds % 60;
        minutes = absFloor(seconds / 60);
        data.minutes = minutes % 60;
        hours = absFloor(minutes / 60);
        data.hours = hours % 24;
        days += absFloor(hours / 24);
        monthsFromDays = absFloor(daysToMonths(days));
        months += monthsFromDays;
        days -= absCeil(monthsToDays(monthsFromDays));
        years = absFloor(months / 12);
        months %= 12;
        data.days = days;
        data.months = months;
        data.years = years;
        return this;
    }
    function daysToMonths(days) {
        return days * 4800 / 146097;
    }
    function monthsToDays(months) {
        return months * 146097 / 4800;
    }
    function as(units) {
        if (!this.isValid()) {
            return NaN;
        }
        var days;
        var months;
        var milliseconds = this._milliseconds;
        units = normalizeUnits(units);
        if (units === "month" || units === "quarter" || units === "year") {
            days = this._days + milliseconds / 864e5;
            months = this._months + daysToMonths(days);
            switch (units) {
              case "month":
                return months;

              case "quarter":
                return months / 3;

              case "year":
                return months / 12;
            }
        } else {
            days = this._days + Math.round(monthsToDays(this._months));
            switch (units) {
              case "week":
                return days / 7 + milliseconds / 6048e5;

              case "day":
                return days + milliseconds / 864e5;

              case "hour":
                return days * 24 + milliseconds / 36e5;

              case "minute":
                return days * 1440 + milliseconds / 6e4;

              case "second":
                return days * 86400 + milliseconds / 1e3;

              case "millisecond":
                return Math.floor(days * 864e5) + milliseconds;

              default:
                throw new Error("Unknown unit " + units);
            }
        }
    }
    function valueOf$1() {
        if (!this.isValid()) {
            return NaN;
        }
        return this._milliseconds + this._days * 864e5 + this._months % 12 * 2592e6 + toInt(this._months / 12) * 31536e6;
    }
    function makeAs(alias) {
        return function() {
            return this.as(alias);
        };
    }
    var asMilliseconds = makeAs("ms");
    var asSeconds = makeAs("s");
    var asMinutes = makeAs("m");
    var asHours = makeAs("h");
    var asDays = makeAs("d");
    var asWeeks = makeAs("w");
    var asMonths = makeAs("M");
    var asQuarters = makeAs("Q");
    var asYears = makeAs("y");
    function clone$1() {
        return createDuration(this);
    }
    function get$2(units) {
        units = normalizeUnits(units);
        return this.isValid() ? this[units + "s"]() : NaN;
    }
    function makeGetter(name) {
        return function() {
            return this.isValid() ? this._data[name] : NaN;
        };
    }
    var milliseconds = makeGetter("milliseconds");
    var seconds = makeGetter("seconds");
    var minutes = makeGetter("minutes");
    var hours = makeGetter("hours");
    var days = makeGetter("days");
    var months = makeGetter("months");
    var years = makeGetter("years");
    function weeks() {
        return absFloor(this.days() / 7);
    }
    var round = Math.round;
    var thresholds = {
        ss: 44,
        s: 45,
        m: 45,
        h: 22,
        d: 26,
        M: 11
    };
    function substituteTimeAgo(string, number, withoutSuffix, isFuture, locale) {
        return locale.relativeTime(number || 1, !!withoutSuffix, string, isFuture);
    }
    function relativeTime$1(posNegDuration, withoutSuffix, locale) {
        var duration = createDuration(posNegDuration).abs();
        var seconds = round(duration.as("s"));
        var minutes = round(duration.as("m"));
        var hours = round(duration.as("h"));
        var days = round(duration.as("d"));
        var months = round(duration.as("M"));
        var years = round(duration.as("y"));
        var a = seconds <= thresholds.ss && [ "s", seconds ] || seconds < thresholds.s && [ "ss", seconds ] || minutes <= 1 && [ "m" ] || minutes < thresholds.m && [ "mm", minutes ] || hours <= 1 && [ "h" ] || hours < thresholds.h && [ "hh", hours ] || days <= 1 && [ "d" ] || days < thresholds.d && [ "dd", days ] || months <= 1 && [ "M" ] || months < thresholds.M && [ "MM", months ] || years <= 1 && [ "y" ] || [ "yy", years ];
        a[2] = withoutSuffix;
        a[3] = +posNegDuration > 0;
        a[4] = locale;
        return substituteTimeAgo.apply(null, a);
    }
    function getSetRelativeTimeRounding(roundingFunction) {
        if (roundingFunction === undefined) {
            return round;
        }
        if (typeof roundingFunction === "function") {
            round = roundingFunction;
            return true;
        }
        return false;
    }
    function getSetRelativeTimeThreshold(threshold, limit) {
        if (thresholds[threshold] === undefined) {
            return false;
        }
        if (limit === undefined) {
            return thresholds[threshold];
        }
        thresholds[threshold] = limit;
        if (threshold === "s") {
            thresholds.ss = limit - 1;
        }
        return true;
    }
    function humanize(withSuffix) {
        if (!this.isValid()) {
            return this.localeData().invalidDate();
        }
        var locale = this.localeData();
        var output = relativeTime$1(this, !withSuffix, locale);
        if (withSuffix) {
            output = locale.pastFuture(+this, output);
        }
        return locale.postformat(output);
    }
    var abs$1 = Math.abs;
    function sign(x) {
        return (x > 0) - (x < 0) || +x;
    }
    function toISOString$1() {
        if (!this.isValid()) {
            return this.localeData().invalidDate();
        }
        var seconds = abs$1(this._milliseconds) / 1e3;
        var days = abs$1(this._days);
        var months = abs$1(this._months);
        var minutes, hours, years;
        minutes = absFloor(seconds / 60);
        hours = absFloor(minutes / 60);
        seconds %= 60;
        minutes %= 60;
        years = absFloor(months / 12);
        months %= 12;
        var Y = years;
        var M = months;
        var D = days;
        var h = hours;
        var m = minutes;
        var s = seconds ? seconds.toFixed(3).replace(/\.?0+$/, "") : "";
        var total = this.asSeconds();
        if (!total) {
            return "P0D";
        }
        var totalSign = total < 0 ? "-" : "";
        var ymSign = sign(this._months) !== sign(total) ? "-" : "";
        var daysSign = sign(this._days) !== sign(total) ? "-" : "";
        var hmsSign = sign(this._milliseconds) !== sign(total) ? "-" : "";
        return totalSign + "P" + (Y ? ymSign + Y + "Y" : "") + (M ? ymSign + M + "M" : "") + (D ? daysSign + D + "D" : "") + (h || m || s ? "T" : "") + (h ? hmsSign + h + "H" : "") + (m ? hmsSign + m + "M" : "") + (s ? hmsSign + s + "S" : "");
    }
    var proto$2 = Duration.prototype;
    proto$2.isValid = isValid$1;
    proto$2.abs = abs;
    proto$2.add = add$1;
    proto$2.subtract = subtract$1;
    proto$2.as = as;
    proto$2.asMilliseconds = asMilliseconds;
    proto$2.asSeconds = asSeconds;
    proto$2.asMinutes = asMinutes;
    proto$2.asHours = asHours;
    proto$2.asDays = asDays;
    proto$2.asWeeks = asWeeks;
    proto$2.asMonths = asMonths;
    proto$2.asQuarters = asQuarters;
    proto$2.asYears = asYears;
    proto$2.valueOf = valueOf$1;
    proto$2._bubble = bubble;
    proto$2.clone = clone$1;
    proto$2.get = get$2;
    proto$2.milliseconds = milliseconds;
    proto$2.seconds = seconds;
    proto$2.minutes = minutes;
    proto$2.hours = hours;
    proto$2.days = days;
    proto$2.weeks = weeks;
    proto$2.months = months;
    proto$2.years = years;
    proto$2.humanize = humanize;
    proto$2.toISOString = toISOString$1;
    proto$2.toString = toISOString$1;
    proto$2.toJSON = toISOString$1;
    proto$2.locale = locale;
    proto$2.localeData = localeData;
    proto$2.toIsoString = deprecate("toIsoString() is deprecated. Please use toISOString() instead (notice the capitals)", toISOString$1);
    proto$2.lang = lang;
    addFormatToken("X", 0, 0, "unix");
    addFormatToken("x", 0, 0, "valueOf");
    addRegexToken("x", matchSigned);
    addRegexToken("X", matchTimestamp);
    addParseToken("X", function(input, array, config) {
        config._d = new Date(parseFloat(input, 10) * 1e3);
    });
    addParseToken("x", function(input, array, config) {
        config._d = new Date(toInt(input));
    });
    hooks.version = "2.24.0";
    setHookCallback(createLocal);
    hooks.fn = proto;
    hooks.min = min;
    hooks.max = max;
    hooks.now = now;
    hooks.utc = createUTC;
    hooks.unix = createUnix;
    hooks.months = listMonths;
    hooks.isDate = isDate;
    hooks.locale = getSetGlobalLocale;
    hooks.invalid = createInvalid;
    hooks.duration = createDuration;
    hooks.isMoment = isMoment;
    hooks.weekdays = listWeekdays;
    hooks.parseZone = createInZone;
    hooks.localeData = getLocale;
    hooks.isDuration = isDuration;
    hooks.monthsShort = listMonthsShort;
    hooks.weekdaysMin = listWeekdaysMin;
    hooks.defineLocale = defineLocale;
    hooks.updateLocale = updateLocale;
    hooks.locales = listLocales;
    hooks.weekdaysShort = listWeekdaysShort;
    hooks.normalizeUnits = normalizeUnits;
    hooks.relativeTimeRounding = getSetRelativeTimeRounding;
    hooks.relativeTimeThreshold = getSetRelativeTimeThreshold;
    hooks.calendarFormat = getCalendarFormat;
    hooks.prototype = proto;
    hooks.HTML5_FMT = {
        DATETIME_LOCAL: "YYYY-MM-DDTHH:mm",
        DATETIME_LOCAL_SECONDS: "YYYY-MM-DDTHH:mm:ss",
        DATETIME_LOCAL_MS: "YYYY-MM-DDTHH:mm:ss.SSS",
        DATE: "YYYY-MM-DD",
        TIME: "HH:mm",
        TIME_SECONDS: "HH:mm:ss",
        TIME_MS: "HH:mm:ss.SSS",
        WEEK: "GGGG-[W]WW",
        MONTH: "YYYY-MM"
    };
    return hooks;
});

(function(global, factory) {
    typeof exports === "object" && typeof module !== "undefined" && typeof require === "function" ? factory(require("../moment")) : typeof define === "function" && define.amd ? define([ "../moment" ], factory) : factory(global.moment);
})(this, function(moment) {
    "use strict";
    var da = moment.defineLocale("da", {
        months: "januar_februar_marts_april_maj_juni_juli_august_september_oktober_november_december".split("_"),
        monthsShort: "jan_feb_mar_apr_maj_jun_jul_aug_sep_okt_nov_dec".split("_"),
        weekdays: "søndag_mandag_tirsdag_onsdag_torsdag_fredag_lørdag".split("_"),
        weekdaysShort: "søn_man_tir_ons_tor_fre_lør".split("_"),
        weekdaysMin: "sø_ma_ti_on_to_fr_lø".split("_"),
        longDateFormat: {
            LT: "HH:mm",
            LTS: "HH:mm:ss",
            L: "DD.MM.YYYY",
            LL: "D. MMMM YYYY",
            LLL: "D. MMMM YYYY HH:mm",
            LLLL: "dddd [d.] D. MMMM YYYY [kl.] HH:mm"
        },
        calendar: {
            sameDay: "[i dag kl.] LT",
            nextDay: "[i morgen kl.] LT",
            nextWeek: "på dddd [kl.] LT",
            lastDay: "[i går kl.] LT",
            lastWeek: "[i] dddd[s kl.] LT",
            sameElse: "L"
        },
        relativeTime: {
            future: "om %s",
            past: "%s siden",
            s: "få sekunder",
            ss: "%d sekunder",
            m: "et minut",
            mm: "%d minutter",
            h: "en time",
            hh: "%d timer",
            d: "en dag",
            dd: "%d dage",
            M: "en måned",
            MM: "%d måneder",
            y: "et år",
            yy: "%d år"
        },
        dayOfMonthOrdinalParse: /\d{1,2}\./,
        ordinal: "%d.",
        week: {
            dow: 1,
            doy: 4
        }
    });
    return da;
});

(function(global, factory) {
    typeof exports === "object" && typeof module !== "undefined" && typeof require === "function" ? factory(require("../moment")) : typeof define === "function" && define.amd ? define([ "../moment" ], factory) : factory(global.moment);
})(this, function(moment) {
    "use strict";
    function processRelativeTime(number, withoutSuffix, key, isFuture) {
        var format = {
            m: [ "eine Minute", "einer Minute" ],
            h: [ "eine Stunde", "einer Stunde" ],
            d: [ "ein Tag", "einem Tag" ],
            dd: [ number + " Tage", number + " Tagen" ],
            M: [ "ein Monat", "einem Monat" ],
            MM: [ number + " Monate", number + " Monaten" ],
            y: [ "ein Jahr", "einem Jahr" ],
            yy: [ number + " Jahre", number + " Jahren" ]
        };
        return withoutSuffix ? format[key][0] : format[key][1];
    }
    var de = moment.defineLocale("de", {
        months: "Januar_Februar_März_April_Mai_Juni_Juli_August_September_Oktober_November_Dezember".split("_"),
        monthsShort: "Jan._Feb._März_Apr._Mai_Juni_Juli_Aug._Sep._Okt._Nov._Dez.".split("_"),
        monthsParseExact: true,
        weekdays: "Sonntag_Montag_Dienstag_Mittwoch_Donnerstag_Freitag_Samstag".split("_"),
        weekdaysShort: "So._Mo._Di._Mi._Do._Fr._Sa.".split("_"),
        weekdaysMin: "So_Mo_Di_Mi_Do_Fr_Sa".split("_"),
        weekdaysParseExact: true,
        longDateFormat: {
            LT: "HH:mm",
            LTS: "HH:mm:ss",
            L: "DD.MM.YYYY",
            LL: "D. MMMM YYYY",
            LLL: "D. MMMM YYYY HH:mm",
            LLLL: "dddd, D. MMMM YYYY HH:mm"
        },
        calendar: {
            sameDay: "[heute um] LT [Uhr]",
            sameElse: "L",
            nextDay: "[morgen um] LT [Uhr]",
            nextWeek: "dddd [um] LT [Uhr]",
            lastDay: "[gestern um] LT [Uhr]",
            lastWeek: "[letzten] dddd [um] LT [Uhr]"
        },
        relativeTime: {
            future: "in %s",
            past: "vor %s",
            s: "ein paar Sekunden",
            ss: "%d Sekunden",
            m: processRelativeTime,
            mm: "%d Minuten",
            h: processRelativeTime,
            hh: "%d Stunden",
            d: processRelativeTime,
            dd: processRelativeTime,
            M: processRelativeTime,
            MM: processRelativeTime,
            y: processRelativeTime,
            yy: processRelativeTime
        },
        dayOfMonthOrdinalParse: /\d{1,2}\./,
        ordinal: "%d.",
        week: {
            dow: 1,
            doy: 4
        }
    });
    return de;
});

(function(global, factory) {
    typeof exports === "object" && typeof module !== "undefined" && typeof require === "function" ? factory(require("../moment")) : typeof define === "function" && define.amd ? define([ "../moment" ], factory) : factory(global.moment);
})(this, function(moment) {
    "use strict";
    var fr = moment.defineLocale("fr", {
        months: "janvier_février_mars_avril_mai_juin_juillet_août_septembre_octobre_novembre_décembre".split("_"),
        monthsShort: "janv._févr._mars_avr._mai_juin_juil._août_sept._oct._nov._déc.".split("_"),
        monthsParseExact: true,
        weekdays: "dimanche_lundi_mardi_mercredi_jeudi_vendredi_samedi".split("_"),
        weekdaysShort: "dim._lun._mar._mer._jeu._ven._sam.".split("_"),
        weekdaysMin: "di_lu_ma_me_je_ve_sa".split("_"),
        weekdaysParseExact: true,
        longDateFormat: {
            LT: "HH:mm",
            LTS: "HH:mm:ss",
            L: "DD/MM/YYYY",
            LL: "D MMMM YYYY",
            LLL: "D MMMM YYYY HH:mm",
            LLLL: "dddd D MMMM YYYY HH:mm"
        },
        calendar: {
            sameDay: "[Aujourd’hui à] LT",
            nextDay: "[Demain à] LT",
            nextWeek: "dddd [à] LT",
            lastDay: "[Hier à] LT",
            lastWeek: "dddd [dernier à] LT",
            sameElse: "L"
        },
        relativeTime: {
            future: "dans %s",
            past: "il y a %s",
            s: "quelques secondes",
            ss: "%d secondes",
            m: "une minute",
            mm: "%d minutes",
            h: "une heure",
            hh: "%d heures",
            d: "un jour",
            dd: "%d jours",
            M: "un mois",
            MM: "%d mois",
            y: "un an",
            yy: "%d ans"
        },
        dayOfMonthOrdinalParse: /\d{1,2}(er|)/,
        ordinal: function(number, period) {
            switch (period) {
              case "D":
                return number + (number === 1 ? "er" : "");

              default:
              case "M":
              case "Q":
              case "DDD":
              case "d":
                return number + (number === 1 ? "er" : "e");

              case "w":
              case "W":
                return number + (number === 1 ? "re" : "e");
            }
        },
        week: {
            dow: 1,
            doy: 4
        }
    });
    return fr;
});

(function(global, factory) {
    typeof exports === "object" && typeof module !== "undefined" && typeof require === "function" ? factory(require("../moment")) : typeof define === "function" && define.amd ? define([ "../moment" ], factory) : factory(global.moment);
})(this, function(moment) {
    "use strict";
    var it = moment.defineLocale("it", {
        months: "gennaio_febbraio_marzo_aprile_maggio_giugno_luglio_agosto_settembre_ottobre_novembre_dicembre".split("_"),
        monthsShort: "gen_feb_mar_apr_mag_giu_lug_ago_set_ott_nov_dic".split("_"),
        weekdays: "domenica_lunedì_martedì_mercoledì_giovedì_venerdì_sabato".split("_"),
        weekdaysShort: "dom_lun_mar_mer_gio_ven_sab".split("_"),
        weekdaysMin: "do_lu_ma_me_gi_ve_sa".split("_"),
        longDateFormat: {
            LT: "HH:mm",
            LTS: "HH:mm:ss",
            L: "DD/MM/YYYY",
            LL: "D MMMM YYYY",
            LLL: "D MMMM YYYY HH:mm",
            LLLL: "dddd D MMMM YYYY HH:mm"
        },
        calendar: {
            sameDay: "[Oggi alle] LT",
            nextDay: "[Domani alle] LT",
            nextWeek: "dddd [alle] LT",
            lastDay: "[Ieri alle] LT",
            lastWeek: function() {
                switch (this.day()) {
                  case 0:
                    return "[la scorsa] dddd [alle] LT";

                  default:
                    return "[lo scorso] dddd [alle] LT";
                }
            },
            sameElse: "L"
        },
        relativeTime: {
            future: function(s) {
                return (/^[0-9].+$/.test(s) ? "tra" : "in") + " " + s;
            },
            past: "%s fa",
            s: "alcuni secondi",
            ss: "%d secondi",
            m: "un minuto",
            mm: "%d minuti",
            h: "un'ora",
            hh: "%d ore",
            d: "un giorno",
            dd: "%d giorni",
            M: "un mese",
            MM: "%d mesi",
            y: "un anno",
            yy: "%d anni"
        },
        dayOfMonthOrdinalParse: /\d{1,2}º/,
        ordinal: "%dº",
        week: {
            dow: 1,
            doy: 4
        }
    });
    return it;
});

(function(global, factory) {
    typeof exports === "object" && typeof module !== "undefined" && typeof require === "function" ? factory(require("../moment")) : typeof define === "function" && define.amd ? define([ "../moment" ], factory) : factory(global.moment);
})(this, function(moment) {
    "use strict";
    var monthsShortWithDots = "jan._feb._mrt._apr._mei_jun._jul._aug._sep._okt._nov._dec.".split("_"), monthsShortWithoutDots = "jan_feb_mrt_apr_mei_jun_jul_aug_sep_okt_nov_dec".split("_");
    var monthsParse = [ /^jan/i, /^feb/i, /^maart|mrt.?$/i, /^apr/i, /^mei$/i, /^jun[i.]?$/i, /^jul[i.]?$/i, /^aug/i, /^sep/i, /^okt/i, /^nov/i, /^dec/i ];
    var monthsRegex = /^(januari|februari|maart|april|mei|ju[nl]i|augustus|september|oktober|november|december|jan\.?|feb\.?|mrt\.?|apr\.?|ju[nl]\.?|aug\.?|sep\.?|okt\.?|nov\.?|dec\.?)/i;
    var nl = moment.defineLocale("nl", {
        months: "januari_februari_maart_april_mei_juni_juli_augustus_september_oktober_november_december".split("_"),
        monthsShort: function(m, format) {
            if (!m) {
                return monthsShortWithDots;
            } else if (/-MMM-/.test(format)) {
                return monthsShortWithoutDots[m.month()];
            } else {
                return monthsShortWithDots[m.month()];
            }
        },
        monthsRegex: monthsRegex,
        monthsShortRegex: monthsRegex,
        monthsStrictRegex: /^(januari|februari|maart|april|mei|ju[nl]i|augustus|september|oktober|november|december)/i,
        monthsShortStrictRegex: /^(jan\.?|feb\.?|mrt\.?|apr\.?|mei|ju[nl]\.?|aug\.?|sep\.?|okt\.?|nov\.?|dec\.?)/i,
        monthsParse: monthsParse,
        longMonthsParse: monthsParse,
        shortMonthsParse: monthsParse,
        weekdays: "zondag_maandag_dinsdag_woensdag_donderdag_vrijdag_zaterdag".split("_"),
        weekdaysShort: "zo._ma._di._wo._do._vr._za.".split("_"),
        weekdaysMin: "zo_ma_di_wo_do_vr_za".split("_"),
        weekdaysParseExact: true,
        longDateFormat: {
            LT: "HH:mm",
            LTS: "HH:mm:ss",
            L: "DD-MM-YYYY",
            LL: "D MMMM YYYY",
            LLL: "D MMMM YYYY HH:mm",
            LLLL: "dddd D MMMM YYYY HH:mm"
        },
        calendar: {
            sameDay: "[vandaag om] LT",
            nextDay: "[morgen om] LT",
            nextWeek: "dddd [om] LT",
            lastDay: "[gisteren om] LT",
            lastWeek: "[afgelopen] dddd [om] LT",
            sameElse: "L"
        },
        relativeTime: {
            future: "over %s",
            past: "%s geleden",
            s: "een paar seconden",
            ss: "%d seconden",
            m: "één minuut",
            mm: "%d minuten",
            h: "één uur",
            hh: "%d uur",
            d: "één dag",
            dd: "%d dagen",
            M: "één maand",
            MM: "%d maanden",
            y: "één jaar",
            yy: "%d jaar"
        },
        dayOfMonthOrdinalParse: /\d{1,2}(ste|de)/,
        ordinal: function(number) {
            return number + (number === 1 || number === 8 || number >= 20 ? "ste" : "de");
        },
        week: {
            dow: 1,
            doy: 4
        }
    });
    return nl;
});

(function(global, factory) {
    typeof exports === "object" && typeof module !== "undefined" && typeof require === "function" ? factory(require("../moment")) : typeof define === "function" && define.amd ? define([ "../moment" ], factory) : factory(global.moment);
})(this, function(moment) {
    "use strict";
    var monthsShortDot = "ene._feb._mar._abr._may._jun._jul._ago._sep._oct._nov._dic.".split("_"), monthsShort = "ene_feb_mar_abr_may_jun_jul_ago_sep_oct_nov_dic".split("_");
    var monthsParse = [ /^ene/i, /^feb/i, /^mar/i, /^abr/i, /^may/i, /^jun/i, /^jul/i, /^ago/i, /^sep/i, /^oct/i, /^nov/i, /^dic/i ];
    var monthsRegex = /^(enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre|ene\.?|feb\.?|mar\.?|abr\.?|may\.?|jun\.?|jul\.?|ago\.?|sep\.?|oct\.?|nov\.?|dic\.?)/i;
    var es = moment.defineLocale("es", {
        months: "enero_febrero_marzo_abril_mayo_junio_julio_agosto_septiembre_octubre_noviembre_diciembre".split("_"),
        monthsShort: function(m, format) {
            if (!m) {
                return monthsShortDot;
            } else if (/-MMM-/.test(format)) {
                return monthsShort[m.month()];
            } else {
                return monthsShortDot[m.month()];
            }
        },
        monthsRegex: monthsRegex,
        monthsShortRegex: monthsRegex,
        monthsStrictRegex: /^(enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre)/i,
        monthsShortStrictRegex: /^(ene\.?|feb\.?|mar\.?|abr\.?|may\.?|jun\.?|jul\.?|ago\.?|sep\.?|oct\.?|nov\.?|dic\.?)/i,
        monthsParse: monthsParse,
        longMonthsParse: monthsParse,
        shortMonthsParse: monthsParse,
        weekdays: "domingo_lunes_martes_miércoles_jueves_viernes_sábado".split("_"),
        weekdaysShort: "dom._lun._mar._mié._jue._vie._sáb.".split("_"),
        weekdaysMin: "do_lu_ma_mi_ju_vi_sá".split("_"),
        weekdaysParseExact: true,
        longDateFormat: {
            LT: "H:mm",
            LTS: "H:mm:ss",
            L: "DD/MM/YYYY",
            LL: "D [de] MMMM [de] YYYY",
            LLL: "D [de] MMMM [de] YYYY H:mm",
            LLLL: "dddd, D [de] MMMM [de] YYYY H:mm"
        },
        calendar: {
            sameDay: function() {
                return "[hoy a la" + (this.hours() !== 1 ? "s" : "") + "] LT";
            },
            nextDay: function() {
                return "[mañana a la" + (this.hours() !== 1 ? "s" : "") + "] LT";
            },
            nextWeek: function() {
                return "dddd [a la" + (this.hours() !== 1 ? "s" : "") + "] LT";
            },
            lastDay: function() {
                return "[ayer a la" + (this.hours() !== 1 ? "s" : "") + "] LT";
            },
            lastWeek: function() {
                return "[el] dddd [pasado a la" + (this.hours() !== 1 ? "s" : "") + "] LT";
            },
            sameElse: "L"
        },
        relativeTime: {
            future: "en %s",
            past: "hace %s",
            s: "unos segundos",
            ss: "%d segundos",
            m: "un minuto",
            mm: "%d minutos",
            h: "una hora",
            hh: "%d horas",
            d: "un día",
            dd: "%d días",
            M: "un mes",
            MM: "%d meses",
            y: "un año",
            yy: "%d años"
        },
        dayOfMonthOrdinalParse: /\d{1,2}º/,
        ordinal: "%dº",
        week: {
            dow: 1,
            doy: 4
        }
    });
    return es;
});

(function(global, factory) {
    typeof exports === "object" && typeof module !== "undefined" ? factory(exports) : typeof define === "function" && define.amd ? define([ "exports" ], factory) : factory(global.SEARCHJS = {});
})(this, function(exports) {
    "use strict";
    function _typeof(obj) {
        if (typeof Symbol === "function" && typeof Symbol.iterator === "symbol") {
            _typeof = function(obj) {
                return typeof obj;
            };
        } else {
            _typeof = function(obj) {
                return obj && typeof Symbol === "function" && obj.constructor === Symbol && obj !== Symbol.prototype ? "symbol" : typeof obj;
            };
        }
        return _typeof(obj);
    }
    function toType(obj) {
        return {}.toString.call(obj).match(/\s([a-zA-Z]+)/)[1].toLowerCase();
    }
    function deepField(data, propertyPath, propertySearch, propertySearchDepth) {
        var ret = null, i, copyPropertyPath, itemValue, parameter, newPropertySearchDepth = -1;
        if (propertySearch) {
            if (propertySearchDepth === 0) {
                return null;
            } else if (propertySearchDepth !== -1) {
                newPropertySearchDepth = propertySearchDepth - 1;
            }
        }
        if (data === null || data === undefined || propertyPath === null || propertyPath === undefined || !Array.isArray(propertyPath) || propertyPath.length < 1) {
            ret = null;
        } else if (Array.isArray(data)) {
            ret = [];
            for (i = 0; i < data.length; i++) {
                copyPropertyPath = propertyPath.slice(0);
                itemValue = deepField(data[i], copyPropertyPath, propertySearch, newPropertySearchDepth - 1);
                if (itemValue !== null) {
                    ret.push(itemValue);
                }
            }
            if (ret.length === 0) {
                ret = null;
            }
        } else if (_typeof(data) === "object") {
            parameter = propertyPath[0];
            if (!data.hasOwnProperty(parameter) && propertySearch) {
                var propertyNames = Object.keys(data);
                ret = [];
                for (i = 0; i < propertyNames.length; i++) {
                    var propertyData = data[propertyNames[i]];
                    if (propertyData === null || propertyData === undefined) {
                        continue;
                    }
                    if (Array.isArray(propertyData)) {
                        propertyData.forEach(function(propertyDataItem) {
                            var foundValue = deepField(propertyDataItem, propertyPath, propertySearch, newPropertySearchDepth);
                            if (foundValue !== null) {
                                ret.push(foundValue);
                            }
                        });
                    } else if (propertyData.constructor.name === "Object") {
                        var foundValue = deepField(propertyData, propertyPath, propertySearch, newPropertySearchDepth);
                        if (foundValue !== null) {
                            ret.push(foundValue);
                        }
                    }
                }
                if (ret.length === 0) {
                    ret = null;
                } else if (ret.length === 1) {
                    ret = ret[0];
                }
            } else if (propertyPath.length < 2) {
                ret = data[parameter];
            } else {
                ret = deepField(data[parameter], propertyPath.slice(1), propertySearch, newPropertySearchDepth);
            }
        }
        return ret;
    }
    function _getSingleOpt(first, override, fallback) {
        var ret;
        if (first !== undefined) {
            ret = first;
        } else if (override !== undefined) {
            ret = override;
        } else {
            ret = fallback;
        }
        return ret;
    }
    function _getOptions(search, _defaults) {
        var options = {};
        search = search || {};
        options.negator = _getSingleOpt(search._not, _defaults.negator, false);
        options.joinAnd = _getSingleOpt(search._join, _defaults.join, "AND") !== "OR";
        options.text = _getSingleOpt(search._text, _defaults.text, false);
        options.word = _getSingleOpt(search._word, _defaults.word, false);
        options.start = _getSingleOpt(search._start, _defaults.start, false);
        options.end = _getSingleOpt(search._end, _defaults.end, false);
        options.separator = search._separator || _defaults.separator || ".";
        options.propertySearch = _getSingleOpt(search._propertySearch, _defaults.propertySearch, false);
        options.propertySearchDepth = _getSingleOpt(search._propertySearchDepth, _defaults.propertySearchDepth, -1);
        return options;
    }
    var _defaults$1 = {};
    function setDefaults(options) {
        for (var key in options) {
            _defaults$1[key] = options[key];
        }
    }
    function resetDefaults() {
        _defaults$1 = {};
    }
    function singleMatch(field, s, text, word, start, end) {
        var oneMatch = false, t, re, j, from, to;
        t = _typeof(field);
        if (field === null) {
            oneMatch = s === null;
        } else if (field === undefined) {
            oneMatch = s === undefined;
        } else if (t === "boolean") {
            oneMatch = s === field;
        } else if (t === "number" || field instanceof Date) {
            if (s !== null && s !== undefined && toType(s) === "object") {
                if (s.from !== undefined || s.to !== undefined || s.gte !== undefined || s.lte !== undefined) {
                    from = s.from || s.gte;
                    to = s.to || s.lte;
                    oneMatch = (s.from !== undefined || s.gte !== undefined ? field >= from : true) && (s.to !== undefined || s.lte !== undefined ? field <= to : true);
                } else if (s.gt !== undefined || s.lt !== undefined) {
                    oneMatch = (s.gt !== undefined ? field > s.gt : true) && (s.lt !== undefined ? field < s.lt : true);
                }
            } else {
                if (field instanceof Date && s instanceof Date) {
                    oneMatch = field.getTime() === s.getTime();
                } else {
                    oneMatch = field === s;
                }
            }
        } else if (t === "string") {
            if (typeof s === "string") {
                s = s.toLowerCase();
            }
            field = field.toLowerCase();
            if (text) {
                oneMatch = field.indexOf(s) !== -1;
            } else if (word) {
                re = new RegExp("(\\s|^)" + s + "(?=\\s|$)", "i");
                oneMatch = field && field.match(re) !== null;
            } else if (start) {
                re = new RegExp("^" + s, "i");
                oneMatch = field && field.match(re) !== null;
            } else if (end) {
                re = new RegExp(s + "$", "i");
                oneMatch = field && field.match(re) !== null;
            } else if (s !== null && s !== undefined && toType(s) === "object") {
                if (s.from !== undefined || s.to !== undefined || s.gte !== undefined || s.lte !== undefined) {
                    from = s.from || s.gte;
                    to = s.to || s.lte;
                    oneMatch = (s.from !== undefined || s.gte !== undefined ? field >= from : true) && (s.to !== undefined || s.lte !== undefined ? field <= to : true);
                } else if (s.gt !== undefined || s.lt !== undefined) {
                    oneMatch = (s.gt !== undefined ? field > s.gt : true) && (s.lt !== undefined ? field < s.lt : true);
                }
            } else {
                oneMatch = s === field;
            }
        } else if (field.length !== undefined) {
            for (j = 0; j < field.length; j++) {
                oneMatch = singleMatch(field[j], s, text, word, start, end);
                if (oneMatch) {
                    break;
                }
            }
        } else if (t === "object") {
            oneMatch = field[s] !== undefined;
        }
        return oneMatch;
    }
    function matchArray(ary, search) {
        var matched = false, i, ret = [], options = _getOptions(search, _defaults$1);
        if (ary && ary.length > 0) {
            for (i = 0; i < ary.length; i++) {
                matched = _matchObj(ary[i], search, options);
                if (matched) {
                    ret.push(ary[i]);
                }
            }
        }
        return ret;
    }
    function matchObject(obj, search) {
        var options = _getOptions(search, _defaults$1);
        return _matchObj(obj, search, options);
    }
    function _matchObj(obj, search, options) {
        var i, j, matched, oneMatch, ary, searchTermParts;
        search = search || {};
        matched = !!options.joinAnd;
        if (search.terms) {
            for (j = 0; j < search.terms.length; j++) {
                oneMatch = matchObject(obj, search.terms[j]);
                if (options.negator) {
                    oneMatch = !oneMatch;
                }
                if (options.joinAnd && !oneMatch) {
                    matched = false;
                    break;
                } else if (!options.joinAnd && oneMatch) {
                    matched = true;
                    break;
                }
            }
        } else {
            for (i in search) {
                if (search.hasOwnProperty(i) && i.indexOf("_") !== 0) {
                    searchTermParts = i.split(options.separator);
                    ary = [].concat(search[i]);
                    for (j = 0; j < ary.length; j++) {
                        oneMatch = singleMatch(deepField(obj, searchTermParts, options.propertySearch, options.propertySearchDepth), ary[j], options.text, options.word, options.start, options.end);
                        if (oneMatch) {
                            break;
                        }
                    }
                    if (options.negator) {
                        oneMatch = !oneMatch;
                    }
                    if (options.joinAnd && !oneMatch) {
                        matched = false;
                        break;
                    } else if (!options.joinAnd && oneMatch) {
                        matched = true;
                        break;
                    }
                }
            }
        }
        return matched;
    }
    exports.setDefaults = setDefaults;
    exports.resetDefaults = resetDefaults;
    exports.singleMatch = singleMatch;
    exports.matchArray = matchArray;
    exports.matchObject = matchObject;
    Object.defineProperty(exports, "__esModule", {
        value: true
    });
});

(function(window, factory) {
    var lazySizes = factory(window, window.document, Date);
    window.lazySizes = lazySizes;
    if (typeof module == "object" && module.exports) {
        module.exports = lazySizes;
    }
})(typeof window != "undefined" ? window : {}, function l(window, document, Date) {
    "use strict";
    var lazysizes, lazySizesCfg;
    (function() {
        var prop;
        var lazySizesDefaults = {
            lazyClass: "lazyload",
            loadedClass: "lazyloaded",
            loadingClass: "lazyloading",
            preloadClass: "lazypreload",
            errorClass: "lazyerror",
            autosizesClass: "lazyautosizes",
            srcAttr: "data-src",
            srcsetAttr: "data-srcset",
            sizesAttr: "data-sizes",
            minSize: 40,
            customMedia: {},
            init: true,
            expFactor: 1.5,
            hFac: .8,
            loadMode: 2,
            loadHidden: true,
            ricTimeout: 0,
            throttleDelay: 125
        };
        lazySizesCfg = window.lazySizesConfig || window.lazysizesConfig || {};
        for (prop in lazySizesDefaults) {
            if (!(prop in lazySizesCfg)) {
                lazySizesCfg[prop] = lazySizesDefaults[prop];
            }
        }
    })();
    if (!document || !document.getElementsByClassName) {
        return {
            init: function() {},
            cfg: lazySizesCfg,
            noSupport: true
        };
    }
    var docElem = document.documentElement;
    var supportPicture = window.HTMLPictureElement;
    var _addEventListener = "addEventListener";
    var _getAttribute = "getAttribute";
    var addEventListener = window[_addEventListener].bind(window);
    var setTimeout = window.setTimeout;
    var requestAnimationFrame = window.requestAnimationFrame || setTimeout;
    var requestIdleCallback = window.requestIdleCallback;
    var regPicture = /^picture$/i;
    var loadEvents = [ "load", "error", "lazyincluded", "_lazyloaded" ];
    var regClassCache = {};
    var forEach = Array.prototype.forEach;
    var hasClass = function(ele, cls) {
        if (!regClassCache[cls]) {
            regClassCache[cls] = new RegExp("(\\s|^)" + cls + "(\\s|$)");
        }
        return regClassCache[cls].test(ele[_getAttribute]("class") || "") && regClassCache[cls];
    };
    var addClass = function(ele, cls) {
        if (!hasClass(ele, cls)) {
            ele.setAttribute("class", (ele[_getAttribute]("class") || "").trim() + " " + cls);
        }
    };
    var removeClass = function(ele, cls) {
        var reg;
        if (reg = hasClass(ele, cls)) {
            ele.setAttribute("class", (ele[_getAttribute]("class") || "").replace(reg, " "));
        }
    };
    var addRemoveLoadEvents = function(dom, fn, add) {
        var action = add ? _addEventListener : "removeEventListener";
        if (add) {
            addRemoveLoadEvents(dom, fn);
        }
        loadEvents.forEach(function(evt) {
            dom[action](evt, fn);
        });
    };
    var triggerEvent = function(elem, name, detail, noBubbles, noCancelable) {
        var event = document.createEvent("Event");
        if (!detail) {
            detail = {};
        }
        detail.instance = lazysizes;
        event.initEvent(name, !noBubbles, !noCancelable);
        event.detail = detail;
        elem.dispatchEvent(event);
        return event;
    };
    var updatePolyfill = function(el, full) {
        var polyfill;
        if (!supportPicture && (polyfill = window.picturefill || lazySizesCfg.pf)) {
            if (full && full.src && !el[_getAttribute]("srcset")) {
                el.setAttribute("srcset", full.src);
            }
            polyfill({
                reevaluate: true,
                elements: [ el ]
            });
        } else if (full && full.src) {
            el.src = full.src;
        }
    };
    var getCSS = function(elem, style) {
        return (getComputedStyle(elem, null) || {})[style];
    };
    var getWidth = function(elem, parent, width) {
        width = width || elem.offsetWidth;
        while (width < lazySizesCfg.minSize && parent && !elem._lazysizesWidth) {
            width = parent.offsetWidth;
            parent = parent.parentNode;
        }
        return width;
    };
    var rAF = function() {
        var running, waiting;
        var firstFns = [];
        var secondFns = [];
        var fns = firstFns;
        var run = function() {
            var runFns = fns;
            fns = firstFns.length ? secondFns : firstFns;
            running = true;
            waiting = false;
            while (runFns.length) {
                runFns.shift()();
            }
            running = false;
        };
        var rafBatch = function(fn, queue) {
            if (running && !queue) {
                fn.apply(this, arguments);
            } else {
                fns.push(fn);
                if (!waiting) {
                    waiting = true;
                    (document.hidden ? setTimeout : requestAnimationFrame)(run);
                }
            }
        };
        rafBatch._lsFlush = run;
        return rafBatch;
    }();
    var rAFIt = function(fn, simple) {
        return simple ? function() {
            rAF(fn);
        } : function() {
            var that = this;
            var args = arguments;
            rAF(function() {
                fn.apply(that, args);
            });
        };
    };
    var throttle = function(fn) {
        var running;
        var lastTime = 0;
        var gDelay = lazySizesCfg.throttleDelay;
        var rICTimeout = lazySizesCfg.ricTimeout;
        var run = function() {
            running = false;
            lastTime = Date.now();
            fn();
        };
        var idleCallback = requestIdleCallback && rICTimeout > 49 ? function() {
            requestIdleCallback(run, {
                timeout: rICTimeout
            });
            if (rICTimeout !== lazySizesCfg.ricTimeout) {
                rICTimeout = lazySizesCfg.ricTimeout;
            }
        } : rAFIt(function() {
            setTimeout(run);
        }, true);
        return function(isPriority) {
            var delay;
            if (isPriority = isPriority === true) {
                rICTimeout = 33;
            }
            if (running) {
                return;
            }
            running = true;
            delay = gDelay - (Date.now() - lastTime);
            if (delay < 0) {
                delay = 0;
            }
            if (isPriority || delay < 9) {
                idleCallback();
            } else {
                setTimeout(idleCallback, delay);
            }
        };
    };
    var debounce = function(func) {
        var timeout, timestamp;
        var wait = 99;
        var run = function() {
            timeout = null;
            func();
        };
        var later = function() {
            var last = Date.now() - timestamp;
            if (last < wait) {
                setTimeout(later, wait - last);
            } else {
                (requestIdleCallback || run)(run);
            }
        };
        return function() {
            timestamp = Date.now();
            if (!timeout) {
                timeout = setTimeout(later, wait);
            }
        };
    };
    var loader = function() {
        var preloadElems, isCompleted, resetPreloadingTimer, loadMode, started;
        var eLvW, elvH, eLtop, eLleft, eLright, eLbottom, isBodyHidden;
        var regImg = /^img$/i;
        var regIframe = /^iframe$/i;
        var supportScroll = "onscroll" in window && !/(gle|ing)bot/.test(navigator.userAgent);
        var shrinkExpand = 0;
        var currentExpand = 0;
        var isLoading = 0;
        var lowRuns = -1;
        var resetPreloading = function(e) {
            isLoading--;
            if (!e || isLoading < 0 || !e.target) {
                isLoading = 0;
            }
        };
        var isVisible = function(elem) {
            if (isBodyHidden == null) {
                isBodyHidden = getCSS(document.body, "visibility") == "hidden";
            }
            return isBodyHidden || !(getCSS(elem.parentNode, "visibility") == "hidden" && getCSS(elem, "visibility") == "hidden");
        };
        var isNestedVisible = function(elem, elemExpand) {
            var outerRect;
            var parent = elem;
            var visible = isVisible(elem);
            eLtop -= elemExpand;
            eLbottom += elemExpand;
            eLleft -= elemExpand;
            eLright += elemExpand;
            while (visible && (parent = parent.offsetParent) && parent != document.body && parent != docElem) {
                visible = (getCSS(parent, "opacity") || 1) > 0;
                if (visible && getCSS(parent, "overflow") != "visible") {
                    outerRect = parent.getBoundingClientRect();
                    visible = eLright > outerRect.left && eLleft < outerRect.right && eLbottom > outerRect.top - 1 && eLtop < outerRect.bottom + 1;
                }
            }
            return visible;
        };
        var checkElements = function() {
            var eLlen, i, rect, autoLoadElem, loadedSomething, elemExpand, elemNegativeExpand, elemExpandVal, beforeExpandVal, defaultExpand, preloadExpand, hFac;
            var lazyloadElems = lazysizes.elements;
            if ((loadMode = lazySizesCfg.loadMode) && isLoading < 8 && (eLlen = lazyloadElems.length)) {
                i = 0;
                lowRuns++;
                for (;i < eLlen; i++) {
                    if (!lazyloadElems[i] || lazyloadElems[i]._lazyRace) {
                        continue;
                    }
                    if (!supportScroll || lazysizes.prematureUnveil && lazysizes.prematureUnveil(lazyloadElems[i])) {
                        unveilElement(lazyloadElems[i]);
                        continue;
                    }
                    if (!(elemExpandVal = lazyloadElems[i][_getAttribute]("data-expand")) || !(elemExpand = elemExpandVal * 1)) {
                        elemExpand = currentExpand;
                    }
                    if (!defaultExpand) {
                        defaultExpand = !lazySizesCfg.expand || lazySizesCfg.expand < 1 ? docElem.clientHeight > 500 && docElem.clientWidth > 500 ? 500 : 370 : lazySizesCfg.expand;
                        lazysizes._defEx = defaultExpand;
                        preloadExpand = defaultExpand * lazySizesCfg.expFactor;
                        hFac = lazySizesCfg.hFac;
                        isBodyHidden = null;
                        if (currentExpand < preloadExpand && isLoading < 1 && lowRuns > 2 && loadMode > 2 && !document.hidden) {
                            currentExpand = preloadExpand;
                            lowRuns = 0;
                        } else if (loadMode > 1 && lowRuns > 1 && isLoading < 6) {
                            currentExpand = defaultExpand;
                        } else {
                            currentExpand = shrinkExpand;
                        }
                    }
                    if (beforeExpandVal !== elemExpand) {
                        eLvW = innerWidth + elemExpand * hFac;
                        elvH = innerHeight + elemExpand;
                        elemNegativeExpand = elemExpand * -1;
                        beforeExpandVal = elemExpand;
                    }
                    rect = lazyloadElems[i].getBoundingClientRect();
                    if ((eLbottom = rect.bottom) >= elemNegativeExpand && (eLtop = rect.top) <= elvH && (eLright = rect.right) >= elemNegativeExpand * hFac && (eLleft = rect.left) <= eLvW && (eLbottom || eLright || eLleft || eLtop) && (lazySizesCfg.loadHidden || isVisible(lazyloadElems[i])) && (isCompleted && isLoading < 3 && !elemExpandVal && (loadMode < 3 || lowRuns < 4) || isNestedVisible(lazyloadElems[i], elemExpand))) {
                        unveilElement(lazyloadElems[i]);
                        loadedSomething = true;
                        if (isLoading > 9) {
                            break;
                        }
                    } else if (!loadedSomething && isCompleted && !autoLoadElem && isLoading < 4 && lowRuns < 4 && loadMode > 2 && (preloadElems[0] || lazySizesCfg.preloadAfterLoad) && (preloadElems[0] || !elemExpandVal && (eLbottom || eLright || eLleft || eLtop || lazyloadElems[i][_getAttribute](lazySizesCfg.sizesAttr) != "auto"))) {
                        autoLoadElem = preloadElems[0] || lazyloadElems[i];
                    }
                }
                if (autoLoadElem && !loadedSomething) {
                    unveilElement(autoLoadElem);
                }
            }
        };
        var throttledCheckElements = throttle(checkElements);
        var switchLoadingClass = function(e) {
            var elem = e.target;
            if (elem._lazyCache) {
                delete elem._lazyCache;
                return;
            }
            resetPreloading(e);
            addClass(elem, lazySizesCfg.loadedClass);
            removeClass(elem, lazySizesCfg.loadingClass);
            addRemoveLoadEvents(elem, rafSwitchLoadingClass);
            triggerEvent(elem, "lazyloaded");
        };
        var rafedSwitchLoadingClass = rAFIt(switchLoadingClass);
        var rafSwitchLoadingClass = function(e) {
            rafedSwitchLoadingClass({
                target: e.target
            });
        };
        var changeIframeSrc = function(elem, src) {
            try {
                elem.contentWindow.location.replace(src);
            } catch (e) {
                elem.src = src;
            }
        };
        var handleSources = function(source) {
            var customMedia;
            var sourceSrcset = source[_getAttribute](lazySizesCfg.srcsetAttr);
            if (customMedia = lazySizesCfg.customMedia[source[_getAttribute]("data-media") || source[_getAttribute]("media")]) {
                source.setAttribute("media", customMedia);
            }
            if (sourceSrcset) {
                source.setAttribute("srcset", sourceSrcset);
            }
        };
        var lazyUnveil = rAFIt(function(elem, detail, isAuto, sizes, isImg) {
            var src, srcset, parent, isPicture, event, firesLoad;
            if (!(event = triggerEvent(elem, "lazybeforeunveil", detail)).defaultPrevented) {
                if (sizes) {
                    if (isAuto) {
                        addClass(elem, lazySizesCfg.autosizesClass);
                    } else {
                        elem.setAttribute("sizes", sizes);
                    }
                }
                srcset = elem[_getAttribute](lazySizesCfg.srcsetAttr);
                src = elem[_getAttribute](lazySizesCfg.srcAttr);
                if (isImg) {
                    parent = elem.parentNode;
                    isPicture = parent && regPicture.test(parent.nodeName || "");
                }
                firesLoad = detail.firesLoad || "src" in elem && (srcset || src || isPicture);
                event = {
                    target: elem
                };
                addClass(elem, lazySizesCfg.loadingClass);
                if (firesLoad) {
                    clearTimeout(resetPreloadingTimer);
                    resetPreloadingTimer = setTimeout(resetPreloading, 2500);
                    addRemoveLoadEvents(elem, rafSwitchLoadingClass, true);
                }
                if (isPicture) {
                    forEach.call(parent.getElementsByTagName("source"), handleSources);
                }
                if (srcset) {
                    elem.setAttribute("srcset", srcset);
                } else if (src && !isPicture) {
                    if (regIframe.test(elem.nodeName)) {
                        changeIframeSrc(elem, src);
                    } else {
                        elem.src = src;
                    }
                }
                if (isImg && (srcset || isPicture)) {
                    updatePolyfill(elem, {
                        src: src
                    });
                }
            }
            if (elem._lazyRace) {
                delete elem._lazyRace;
            }
            removeClass(elem, lazySizesCfg.lazyClass);
            rAF(function() {
                var isLoaded = elem.complete && elem.naturalWidth > 1;
                if (!firesLoad || isLoaded) {
                    if (isLoaded) {
                        addClass(elem, "ls-is-cached");
                    }
                    switchLoadingClass(event);
                    elem._lazyCache = true;
                    setTimeout(function() {
                        if ("_lazyCache" in elem) {
                            delete elem._lazyCache;
                        }
                    }, 9);
                }
                if (elem.loading == "lazy") {
                    isLoading--;
                }
            }, true);
        });
        var unveilElement = function(elem) {
            if (elem._lazyRace) {
                return;
            }
            var detail;
            var isImg = regImg.test(elem.nodeName);
            var sizes = isImg && (elem[_getAttribute](lazySizesCfg.sizesAttr) || elem[_getAttribute]("sizes"));
            var isAuto = sizes == "auto";
            if ((isAuto || !isCompleted) && isImg && (elem[_getAttribute]("src") || elem.srcset) && !elem.complete && !hasClass(elem, lazySizesCfg.errorClass) && hasClass(elem, lazySizesCfg.lazyClass)) {
                return;
            }
            detail = triggerEvent(elem, "lazyunveilread").detail;
            if (isAuto) {
                autoSizer.updateElem(elem, true, elem.offsetWidth);
            }
            elem._lazyRace = true;
            isLoading++;
            lazyUnveil(elem, detail, isAuto, sizes, isImg);
        };
        var afterScroll = debounce(function() {
            lazySizesCfg.loadMode = 3;
            throttledCheckElements();
        });
        var altLoadmodeScrollListner = function() {
            if (lazySizesCfg.loadMode == 3) {
                lazySizesCfg.loadMode = 2;
            }
            afterScroll();
        };
        var onload = function() {
            if (isCompleted) {
                return;
            }
            if (Date.now() - started < 999) {
                setTimeout(onload, 999);
                return;
            }
            isCompleted = true;
            lazySizesCfg.loadMode = 3;
            throttledCheckElements();
            addEventListener("scroll", altLoadmodeScrollListner, true);
        };
        return {
            _: function() {
                started = Date.now();
                lazysizes.elements = document.getElementsByClassName(lazySizesCfg.lazyClass);
                preloadElems = document.getElementsByClassName(lazySizesCfg.lazyClass + " " + lazySizesCfg.preloadClass);
                addEventListener("scroll", throttledCheckElements, true);
                addEventListener("resize", throttledCheckElements, true);
                addEventListener("pageshow", function(e) {
                    if (e.persisted) {
                        var loadingElements = document.querySelectorAll("." + lazySizesCfg.loadingClass);
                        if (loadingElements.length && loadingElements.forEach) {
                            requestAnimationFrame(function() {
                                loadingElements.forEach(function(img) {
                                    if (img.complete) {
                                        unveilElement(img);
                                    }
                                });
                            });
                        }
                    }
                });
                if (window.MutationObserver) {
                    new MutationObserver(throttledCheckElements).observe(docElem, {
                        childList: true,
                        subtree: true,
                        attributes: true
                    });
                } else {
                    docElem[_addEventListener]("DOMNodeInserted", throttledCheckElements, true);
                    docElem[_addEventListener]("DOMAttrModified", throttledCheckElements, true);
                    setInterval(throttledCheckElements, 999);
                }
                addEventListener("hashchange", throttledCheckElements, true);
                [ "focus", "mouseover", "click", "load", "transitionend", "animationend" ].forEach(function(name) {
                    document[_addEventListener](name, throttledCheckElements, true);
                });
                if (/d$|^c/.test(document.readyState)) {
                    onload();
                } else {
                    addEventListener("load", onload);
                    document[_addEventListener]("DOMContentLoaded", throttledCheckElements);
                    setTimeout(onload, 2e4);
                }
                if (lazysizes.elements.length) {
                    checkElements();
                    rAF._lsFlush();
                } else {
                    throttledCheckElements();
                }
            },
            checkElems: throttledCheckElements,
            unveil: unveilElement,
            _aLSL: altLoadmodeScrollListner
        };
    }();
    var autoSizer = function() {
        var autosizesElems;
        var sizeElement = rAFIt(function(elem, parent, event, width) {
            var sources, i, len;
            elem._lazysizesWidth = width;
            width += "px";
            elem.setAttribute("sizes", width);
            if (regPicture.test(parent.nodeName || "")) {
                sources = parent.getElementsByTagName("source");
                for (i = 0, len = sources.length; i < len; i++) {
                    sources[i].setAttribute("sizes", width);
                }
            }
            if (!event.detail.dataAttr) {
                updatePolyfill(elem, event.detail);
            }
        });
        var getSizeElement = function(elem, dataAttr, width) {
            var event;
            var parent = elem.parentNode;
            if (parent) {
                width = getWidth(elem, parent, width);
                event = triggerEvent(elem, "lazybeforesizes", {
                    width: width,
                    dataAttr: !!dataAttr
                });
                if (!event.defaultPrevented) {
                    width = event.detail.width;
                    if (width && width !== elem._lazysizesWidth) {
                        sizeElement(elem, parent, event, width);
                    }
                }
            }
        };
        var updateElementsSizes = function() {
            var i;
            var len = autosizesElems.length;
            if (len) {
                i = 0;
                for (;i < len; i++) {
                    getSizeElement(autosizesElems[i]);
                }
            }
        };
        var debouncedUpdateElementsSizes = debounce(updateElementsSizes);
        return {
            _: function() {
                autosizesElems = document.getElementsByClassName(lazySizesCfg.autosizesClass);
                addEventListener("resize", debouncedUpdateElementsSizes);
            },
            checkElems: debouncedUpdateElementsSizes,
            updateElem: getSizeElement
        };
    }();
    var init = function() {
        if (!init.i && document.getElementsByClassName) {
            init.i = true;
            autoSizer._();
            loader._();
        }
    };
    setTimeout(function() {
        if (lazySizesCfg.init) {
            init();
        }
    });
    lazysizes = {
        cfg: lazySizesCfg,
        autoSizer: autoSizer,
        loader: loader,
        init: init,
        uP: updatePolyfill,
        aC: addClass,
        rC: removeClass,
        hC: hasClass,
        fire: triggerEvent,
        gW: getWidth,
        rAF: rAF
    };
    return lazysizes;
});

(function(global, factory) {
    if (typeof define == "function" && define.amd) {
        define("ev-emitter/ev-emitter", factory);
    } else if (typeof module == "object" && module.exports) {
        module.exports = factory();
    } else {
        global.EvEmitter = factory();
    }
})(typeof window != "undefined" ? window : this, function() {
    function EvEmitter() {}
    var proto = EvEmitter.prototype;
    proto.on = function(eventName, listener) {
        if (!eventName || !listener) {
            return;
        }
        var events = this._events = this._events || {};
        var listeners = events[eventName] = events[eventName] || [];
        if (listeners.indexOf(listener) == -1) {
            listeners.push(listener);
        }
        return this;
    };
    proto.once = function(eventName, listener) {
        if (!eventName || !listener) {
            return;
        }
        this.on(eventName, listener);
        var onceEvents = this._onceEvents = this._onceEvents || {};
        var onceListeners = onceEvents[eventName] = onceEvents[eventName] || {};
        onceListeners[listener] = true;
        return this;
    };
    proto.off = function(eventName, listener) {
        var listeners = this._events && this._events[eventName];
        if (!listeners || !listeners.length) {
            return;
        }
        var index = listeners.indexOf(listener);
        if (index != -1) {
            listeners.splice(index, 1);
        }
        return this;
    };
    proto.emitEvent = function(eventName, args) {
        var listeners = this._events && this._events[eventName];
        if (!listeners || !listeners.length) {
            return;
        }
        listeners = listeners.slice(0);
        args = args || [];
        var onceListeners = this._onceEvents && this._onceEvents[eventName];
        for (var i = 0; i < listeners.length; i++) {
            var listener = listeners[i];
            var isOnce = onceListeners && onceListeners[listener];
            if (isOnce) {
                this.off(eventName, listener);
                delete onceListeners[listener];
            }
            listener.apply(this, args);
        }
        return this;
    };
    proto.allOff = function() {
        delete this._events;
        delete this._onceEvents;
    };
    return EvEmitter;
});

(function(window, factory) {
    "use strict";
    if (typeof define == "function" && define.amd) {
        define([ "ev-emitter/ev-emitter" ], function(EvEmitter) {
            return factory(window, EvEmitter);
        });
    } else if (typeof module == "object" && module.exports) {
        module.exports = factory(window, require("ev-emitter"));
    } else {
        window.imagesLoaded = factory(window, window.EvEmitter);
    }
})(typeof window !== "undefined" ? window : this, function factory(window, EvEmitter) {
    var $ = window.jQuery;
    var console = window.console;
    function extend(a, b) {
        for (var prop in b) {
            a[prop] = b[prop];
        }
        return a;
    }
    var arraySlice = Array.prototype.slice;
    function makeArray(obj) {
        if (Array.isArray(obj)) {
            return obj;
        }
        var isArrayLike = typeof obj == "object" && typeof obj.length == "number";
        if (isArrayLike) {
            return arraySlice.call(obj);
        }
        return [ obj ];
    }
    function ImagesLoaded(elem, options, onAlways) {
        if (!(this instanceof ImagesLoaded)) {
            return new ImagesLoaded(elem, options, onAlways);
        }
        var queryElem = elem;
        if (typeof elem == "string") {
            queryElem = document.querySelectorAll(elem);
        }
        if (!queryElem) {
            console.error("Bad element for imagesLoaded " + (queryElem || elem));
            return;
        }
        this.elements = makeArray(queryElem);
        this.options = extend({}, this.options);
        if (typeof options == "function") {
            onAlways = options;
        } else {
            extend(this.options, options);
        }
        if (onAlways) {
            this.on("always", onAlways);
        }
        this.getImages();
        if ($) {
            this.jqDeferred = new $.Deferred();
        }
        setTimeout(this.check.bind(this));
    }
    ImagesLoaded.prototype = Object.create(EvEmitter.prototype);
    ImagesLoaded.prototype.options = {};
    ImagesLoaded.prototype.getImages = function() {
        this.images = [];
        this.elements.forEach(this.addElementImages, this);
    };
    ImagesLoaded.prototype.addElementImages = function(elem) {
        if (elem.nodeName == "IMG") {
            this.addImage(elem);
        }
        if (this.options.background === true) {
            this.addElementBackgroundImages(elem);
        }
        var nodeType = elem.nodeType;
        if (!nodeType || !elementNodeTypes[nodeType]) {
            return;
        }
        var childImgs = elem.querySelectorAll("img");
        for (var i = 0; i < childImgs.length; i++) {
            var img = childImgs[i];
            this.addImage(img);
        }
        if (typeof this.options.background == "string") {
            var children = elem.querySelectorAll(this.options.background);
            for (i = 0; i < children.length; i++) {
                var child = children[i];
                this.addElementBackgroundImages(child);
            }
        }
    };
    var elementNodeTypes = {
        1: true,
        9: true,
        11: true
    };
    ImagesLoaded.prototype.addElementBackgroundImages = function(elem) {
        var style = getComputedStyle(elem);
        if (!style) {
            return;
        }
        var reURL = /url\((['"])?(.*?)\1\)/gi;
        var matches = reURL.exec(style.backgroundImage);
        while (matches !== null) {
            var url = matches && matches[2];
            if (url) {
                this.addBackground(url, elem);
            }
            matches = reURL.exec(style.backgroundImage);
        }
    };
    ImagesLoaded.prototype.addImage = function(img) {
        var loadingImage = new LoadingImage(img);
        this.images.push(loadingImage);
    };
    ImagesLoaded.prototype.addBackground = function(url, elem) {
        var background = new Background(url, elem);
        this.images.push(background);
    };
    ImagesLoaded.prototype.check = function() {
        var _this = this;
        this.progressedCount = 0;
        this.hasAnyBroken = false;
        if (!this.images.length) {
            this.complete();
            return;
        }
        function onProgress(image, elem, message) {
            setTimeout(function() {
                _this.progress(image, elem, message);
            });
        }
        this.images.forEach(function(loadingImage) {
            loadingImage.once("progress", onProgress);
            loadingImage.check();
        });
    };
    ImagesLoaded.prototype.progress = function(image, elem, message) {
        this.progressedCount++;
        this.hasAnyBroken = this.hasAnyBroken || !image.isLoaded;
        this.emitEvent("progress", [ this, image, elem ]);
        if (this.jqDeferred && this.jqDeferred.notify) {
            this.jqDeferred.notify(this, image);
        }
        if (this.progressedCount == this.images.length) {
            this.complete();
        }
        if (this.options.debug && console) {
            console.log("progress: " + message, image, elem);
        }
    };
    ImagesLoaded.prototype.complete = function() {
        var eventName = this.hasAnyBroken ? "fail" : "done";
        this.isComplete = true;
        this.emitEvent(eventName, [ this ]);
        this.emitEvent("always", [ this ]);
        if (this.jqDeferred) {
            var jqMethod = this.hasAnyBroken ? "reject" : "resolve";
            this.jqDeferred[jqMethod](this);
        }
    };
    function LoadingImage(img) {
        this.img = img;
    }
    LoadingImage.prototype = Object.create(EvEmitter.prototype);
    LoadingImage.prototype.check = function() {
        var isComplete = this.getIsImageComplete();
        if (isComplete) {
            this.confirm(this.img.naturalWidth !== 0, "naturalWidth");
            return;
        }
        this.proxyImage = new Image();
        this.proxyImage.addEventListener("load", this);
        this.proxyImage.addEventListener("error", this);
        this.img.addEventListener("load", this);
        this.img.addEventListener("error", this);
        this.proxyImage.src = this.img.src;
    };
    LoadingImage.prototype.getIsImageComplete = function() {
        return this.img.complete && this.img.naturalWidth;
    };
    LoadingImage.prototype.confirm = function(isLoaded, message) {
        this.isLoaded = isLoaded;
        this.emitEvent("progress", [ this, this.img, message ]);
    };
    LoadingImage.prototype.handleEvent = function(event) {
        var method = "on" + event.type;
        if (this[method]) {
            this[method](event);
        }
    };
    LoadingImage.prototype.onload = function() {
        this.confirm(true, "onload");
        this.unbindEvents();
    };
    LoadingImage.prototype.onerror = function() {
        this.confirm(false, "onerror");
        this.unbindEvents();
    };
    LoadingImage.prototype.unbindEvents = function() {
        this.proxyImage.removeEventListener("load", this);
        this.proxyImage.removeEventListener("error", this);
        this.img.removeEventListener("load", this);
        this.img.removeEventListener("error", this);
    };
    function Background(url, element) {
        this.url = url;
        this.element = element;
        this.img = new Image();
    }
    Background.prototype = Object.create(LoadingImage.prototype);
    Background.prototype.check = function() {
        this.img.addEventListener("load", this);
        this.img.addEventListener("error", this);
        this.img.src = this.url;
        var isComplete = this.getIsImageComplete();
        if (isComplete) {
            this.confirm(this.img.naturalWidth !== 0, "naturalWidth");
            this.unbindEvents();
        }
    };
    Background.prototype.unbindEvents = function() {
        this.img.removeEventListener("load", this);
        this.img.removeEventListener("error", this);
    };
    Background.prototype.confirm = function(isLoaded, message) {
        this.isLoaded = isLoaded;
        this.emitEvent("progress", [ this, this.element, message ]);
    };
    ImagesLoaded.makeJQueryPlugin = function(jQuery) {
        jQuery = jQuery || window.jQuery;
        if (!jQuery) {
            return;
        }
        $ = jQuery;
        $.fn.imagesLoaded = function(options, callback) {
            var instance = new ImagesLoaded(this, options, callback);
            return instance.jqDeferred.promise($(this));
        };
    };
    ImagesLoaded.makeJQueryPlugin();
    return ImagesLoaded;
});

(function() {
    var _ = function(input, o) {
        var me = this;
        _.count = (_.count || 0) + 1;
        this.count = _.count;
        this.isOpened = false;
        this.input = $(input);
        this.input.setAttribute("autocomplete", "off");
        this.input.setAttribute("aria-expanded", "false");
        this.input.setAttribute("aria-owns", "awesomplete_list_" + this.count);
        this.input.setAttribute("role", "combobox");
        this.options = o = o || {};
        configure(this, {
            minChars: 2,
            maxItems: 10,
            autoFirst: false,
            data: _.DATA,
            filter: _.FILTER_CONTAINS,
            sort: o.sort === false ? false : _.SORT_BYLENGTH,
            container: _.CONTAINER,
            item: _.ITEM,
            replace: _.REPLACE,
            tabSelect: false
        }, o);
        this.index = -1;
        this.container = this.container(input);
        this.ul = $.create("ul", {
            hidden: "hidden",
            role: "listbox",
            id: "awesomplete_list_" + this.count,
            inside: this.container
        });
        this.status = $.create("span", {
            className: "visually-hidden",
            role: "status",
            "aria-live": "assertive",
            "aria-atomic": true,
            inside: this.container,
            textContent: this.minChars != 0 ? "Type " + this.minChars + " or more characters for results." : "Begin typing for results."
        });
        this._events = {
            input: {
                input: this.evaluate.bind(this),
                blur: this.close.bind(this, {
                    reason: "blur"
                }),
                keydown: function(evt) {
                    var c = evt.keyCode;
                    if (me.opened) {
                        if (c === 13 && me.selected) {
                            evt.preventDefault();
                            me.select(undefined, undefined, evt);
                        } else if (c === 9 && me.selected && me.tabSelect) {
                            me.select(undefined, undefined, evt);
                        } else if (c === 27) {
                            me.close({
                                reason: "esc"
                            });
                        } else if (c === 38 || c === 40) {
                            evt.preventDefault();
                            me[c === 38 ? "previous" : "next"]();
                        }
                    }
                }
            },
            form: {
                submit: this.close.bind(this, {
                    reason: "submit"
                })
            },
            ul: {
                mousedown: function(evt) {
                    evt.preventDefault();
                },
                click: function(evt) {
                    var li = evt.target;
                    if (li !== this) {
                        while (li && !/li/i.test(li.nodeName)) {
                            li = li.parentNode;
                        }
                        if (li && evt.button === 0) {
                            evt.preventDefault();
                            me.select(li, evt.target, evt);
                        }
                    }
                }
            }
        };
        $.bind(this.input, this._events.input);
        $.bind(this.input.form, this._events.form);
        $.bind(this.ul, this._events.ul);
        if (this.input.hasAttribute("list")) {
            this.list = "#" + this.input.getAttribute("list");
            this.input.removeAttribute("list");
        } else {
            this.list = this.input.getAttribute("data-list") || o.list || [];
        }
        _.all.push(this);
    };
    _.prototype = {
        set list(list) {
            if (Array.isArray(list)) {
                this._list = list;
            } else if (typeof list === "string" && list.indexOf(",") > -1) {
                this._list = list.split(/\s*,\s*/);
            } else {
                list = $(list);
                if (list && list.children) {
                    var items = [];
                    slice.apply(list.children).forEach(function(el) {
                        if (!el.disabled) {
                            var text = el.textContent.trim();
                            var value = el.value || text;
                            var label = el.label || text;
                            if (value !== "") {
                                items.push({
                                    label: label,
                                    value: value
                                });
                            }
                        }
                    });
                    this._list = items;
                }
            }
            if (document.activeElement === this.input) {
                this.evaluate();
            }
        },
        get selected() {
            return this.index > -1;
        },
        get opened() {
            return this.isOpened;
        },
        close: function(o) {
            if (!this.opened) {
                return;
            }
            this.input.setAttribute("aria-expanded", "false");
            this.ul.setAttribute("hidden", "");
            this.isOpened = false;
            this.index = -1;
            this.status.setAttribute("hidden", "");
            $.fire(this.input, "awesomplete-close", o || {});
        },
        open: function() {
            this.input.setAttribute("aria-expanded", "true");
            this.ul.removeAttribute("hidden");
            this.isOpened = true;
            this.status.removeAttribute("hidden");
            if (this.autoFirst && this.index === -1) {
                this.goto(0);
            }
            $.fire(this.input, "awesomplete-open");
        },
        destroy: function() {
            $.unbind(this.input, this._events.input);
            $.unbind(this.input.form, this._events.form);
            if (!this.options.container) {
                var parentNode = this.container.parentNode;
                parentNode.insertBefore(this.input, this.container);
                parentNode.removeChild(this.container);
            }
            this.input.removeAttribute("autocomplete");
            this.input.removeAttribute("aria-autocomplete");
            var indexOfAwesomplete = _.all.indexOf(this);
            if (indexOfAwesomplete !== -1) {
                _.all.splice(indexOfAwesomplete, 1);
            }
        },
        next: function() {
            var count = this.ul.children.length;
            this.goto(this.index < count - 1 ? this.index + 1 : count ? 0 : -1);
        },
        previous: function() {
            var count = this.ul.children.length;
            var pos = this.index - 1;
            this.goto(this.selected && pos !== -1 ? pos : count - 1);
        },
        goto: function(i) {
            var lis = this.ul.children;
            if (this.selected) {
                lis[this.index].setAttribute("aria-selected", "false");
            }
            this.index = i;
            if (i > -1 && lis.length > 0) {
                lis[i].setAttribute("aria-selected", "true");
                this.status.textContent = lis[i].textContent + ", list item " + (i + 1) + " of " + lis.length;
                this.input.setAttribute("aria-activedescendant", this.ul.id + "_item_" + this.index);
                this.ul.scrollTop = lis[i].offsetTop - this.ul.clientHeight + lis[i].clientHeight;
                $.fire(this.input, "awesomplete-highlight", {
                    text: this.suggestions[this.index]
                });
            }
        },
        select: function(selected, origin, originalEvent) {
            if (selected) {
                this.index = $.siblingIndex(selected);
            } else {
                selected = this.ul.children[this.index];
            }
            if (selected) {
                var suggestion = this.suggestions[this.index];
                var allowed = $.fire(this.input, "awesomplete-select", {
                    text: suggestion,
                    origin: origin || selected,
                    originalEvent: originalEvent
                });
                if (allowed) {
                    this.replace(suggestion);
                    this.close({
                        reason: "select"
                    });
                    $.fire(this.input, "awesomplete-selectcomplete", {
                        text: suggestion,
                        originalEvent: originalEvent
                    });
                }
            }
        },
        evaluate: function() {
            var me = this;
            var value = this.input.value;
            if (value.length >= this.minChars && this._list && this._list.length > 0) {
                this.index = -1;
                this.ul.innerHTML = "";
                this.suggestions = this._list.map(function(item) {
                    return new Suggestion(me.data(item, value));
                }).filter(function(item) {
                    return me.filter(item, value);
                });
                if (this.sort !== false) {
                    this.suggestions = this.suggestions.sort(this.sort);
                }
                this.suggestions = this.suggestions.slice(0, this.maxItems);
                this.suggestions.forEach(function(text, index) {
                    me.ul.appendChild(me.item(text, value, index));
                });
                if (this.ul.children.length === 0) {
                    this.status.textContent = "No results found";
                    this.close({
                        reason: "nomatches"
                    });
                } else {
                    this.open();
                    this.status.textContent = this.ul.children.length + " results found";
                }
            } else {
                this.close({
                    reason: "nomatches"
                });
                this.status.textContent = "No results found";
            }
        }
    };
    _.all = [];
    _.FILTER_CONTAINS = function(text, input) {
        return RegExp($.regExpEscape(input.trim()), "i").test(text);
    };
    _.FILTER_STARTSWITH = function(text, input) {
        return RegExp("^" + $.regExpEscape(input.trim()), "i").test(text);
    };
    _.SORT_BYLENGTH = function(a, b) {
        if (a.length !== b.length) {
            return a.length - b.length;
        }
        return a < b ? -1 : 1;
    };
    _.CONTAINER = function(input) {
        return $.create("div", {
            className: "awesomplete",
            around: input
        });
    };
    _.ITEM = function(text, input, item_id) {
        var html = input.trim() === "" ? text : text.replace(RegExp($.regExpEscape(input.trim()), "gi"), "<mark>$&</mark>");
        return $.create("li", {
            innerHTML: html,
            role: "option",
            "aria-selected": "false",
            id: "awesomplete_list_" + this.count + "_item_" + item_id
        });
    };
    _.REPLACE = function(text) {
        this.input.value = text.value;
    };
    _.DATA = function(item) {
        return item;
    };
    function Suggestion(data) {
        var o = Array.isArray(data) ? {
            label: data[0],
            value: data[1]
        } : typeof data === "object" && "label" in data && "value" in data ? data : {
            label: data,
            value: data
        };
        this.label = o.label || o.value;
        this.value = o.value;
    }
    Object.defineProperty(Suggestion.prototype = Object.create(String.prototype), "length", {
        get: function() {
            return this.label.length;
        }
    });
    Suggestion.prototype.toString = Suggestion.prototype.valueOf = function() {
        return "" + this.label;
    };
    function configure(instance, properties, o) {
        for (var i in properties) {
            var initial = properties[i], attrValue = instance.input.getAttribute("data-" + i.toLowerCase());
            if (typeof initial === "number") {
                instance[i] = parseInt(attrValue);
            } else if (initial === false) {
                instance[i] = attrValue !== null;
            } else if (initial instanceof Function) {
                instance[i] = null;
            } else {
                instance[i] = attrValue;
            }
            if (!instance[i] && instance[i] !== 0) {
                instance[i] = i in o ? o[i] : initial;
            }
        }
    }
    var slice = Array.prototype.slice;
    function $(expr, con) {
        return typeof expr === "string" ? (con || document).querySelector(expr) : expr || null;
    }
    function $$(expr, con) {
        return slice.call((con || document).querySelectorAll(expr));
    }
    $.create = function(tag, o) {
        var element = document.createElement(tag);
        for (var i in o) {
            var val = o[i];
            if (i === "inside") {
                $(val).appendChild(element);
            } else if (i === "around") {
                var ref = $(val);
                ref.parentNode.insertBefore(element, ref);
                element.appendChild(ref);
                if (ref.getAttribute("autofocus") != null) {
                    ref.focus();
                }
            } else if (i in element) {
                element[i] = val;
            } else {
                element.setAttribute(i, val);
            }
        }
        return element;
    };
    $.bind = function(element, o) {
        if (element) {
            for (var event in o) {
                var callback = o[event];
                event.split(/\s+/).forEach(function(event) {
                    element.addEventListener(event, callback);
                });
            }
        }
    };
    $.unbind = function(element, o) {
        if (element) {
            for (var event in o) {
                var callback = o[event];
                event.split(/\s+/).forEach(function(event) {
                    element.removeEventListener(event, callback);
                });
            }
        }
    };
    $.fire = function(target, type, properties) {
        var evt = document.createEvent("HTMLEvents");
        evt.initEvent(type, true, true);
        for (var j in properties) {
            evt[j] = properties[j];
        }
        return target.dispatchEvent(evt);
    };
    $.regExpEscape = function(s) {
        return s.replace(/[-\\^$*+?.()|[\]{}]/g, "\\$&");
    };
    $.siblingIndex = function(el) {
        for (var i = 0; el = el.previousElementSibling; i++) ;
        return i;
    };
    function init() {
        $$("input.awesomplete").forEach(function(input) {
            new _(input);
        });
    }
    if (typeof self !== "undefined") {
        self.Awesomplete = _;
    }
    if (typeof Document !== "undefined") {
        if (document.readyState !== "loading") {
            init();
        } else {
            document.addEventListener("DOMContentLoaded", init);
        }
    }
    _.$ = $;
    _.$$ = $$;
    if (typeof module === "object" && module.exports) {
        module.exports = _;
    }
    return _;
})();

(function() {
    var lunr = function(config) {
        var builder = new lunr.Builder();
        builder.pipeline.add(lunr.trimmer, lunr.stopWordFilter, lunr.stemmer);
        builder.searchPipeline.add(lunr.stemmer);
        config.call(builder, builder);
        return builder.build();
    };
    lunr.version = "2.3.8";
    lunr.utils = {};
    lunr.utils.warn = function(global) {
        return function(message) {
            if (global.console && console.warn) {
                console.warn(message);
            }
        };
    }(this);
    lunr.utils.asString = function(obj) {
        if (obj === void 0 || obj === null) {
            return "";
        } else {
            return obj.toString();
        }
    };
    lunr.utils.clone = function(obj) {
        if (obj === null || obj === undefined) {
            return obj;
        }
        var clone = Object.create(null), keys = Object.keys(obj);
        for (var i = 0; i < keys.length; i++) {
            var key = keys[i], val = obj[key];
            if (Array.isArray(val)) {
                clone[key] = val.slice();
                continue;
            }
            if (typeof val === "string" || typeof val === "number" || typeof val === "boolean") {
                clone[key] = val;
                continue;
            }
            throw new TypeError("clone is not deep and does not support nested objects");
        }
        return clone;
    };
    lunr.FieldRef = function(docRef, fieldName, stringValue) {
        this.docRef = docRef;
        this.fieldName = fieldName;
        this._stringValue = stringValue;
    };
    lunr.FieldRef.joiner = "/";
    lunr.FieldRef.fromString = function(s) {
        var n = s.indexOf(lunr.FieldRef.joiner);
        if (n === -1) {
            throw "malformed field ref string";
        }
        var fieldRef = s.slice(0, n), docRef = s.slice(n + 1);
        return new lunr.FieldRef(docRef, fieldRef, s);
    };
    lunr.FieldRef.prototype.toString = function() {
        if (this._stringValue == undefined) {
            this._stringValue = this.fieldName + lunr.FieldRef.joiner + this.docRef;
        }
        return this._stringValue;
    };
    lunr.Set = function(elements) {
        this.elements = Object.create(null);
        if (elements) {
            this.length = elements.length;
            for (var i = 0; i < this.length; i++) {
                this.elements[elements[i]] = true;
            }
        } else {
            this.length = 0;
        }
    };
    lunr.Set.complete = {
        intersect: function(other) {
            return other;
        },
        union: function(other) {
            return other;
        },
        contains: function() {
            return true;
        }
    };
    lunr.Set.empty = {
        intersect: function() {
            return this;
        },
        union: function(other) {
            return other;
        },
        contains: function() {
            return false;
        }
    };
    lunr.Set.prototype.contains = function(object) {
        return !!this.elements[object];
    };
    lunr.Set.prototype.intersect = function(other) {
        var a, b, elements, intersection = [];
        if (other === lunr.Set.complete) {
            return this;
        }
        if (other === lunr.Set.empty) {
            return other;
        }
        if (this.length < other.length) {
            a = this;
            b = other;
        } else {
            a = other;
            b = this;
        }
        elements = Object.keys(a.elements);
        for (var i = 0; i < elements.length; i++) {
            var element = elements[i];
            if (element in b.elements) {
                intersection.push(element);
            }
        }
        return new lunr.Set(intersection);
    };
    lunr.Set.prototype.union = function(other) {
        if (other === lunr.Set.complete) {
            return lunr.Set.complete;
        }
        if (other === lunr.Set.empty) {
            return this;
        }
        return new lunr.Set(Object.keys(this.elements).concat(Object.keys(other.elements)));
    };
    lunr.idf = function(posting, documentCount) {
        var documentsWithTerm = 0;
        for (var fieldName in posting) {
            if (fieldName == "_index") continue;
            documentsWithTerm += Object.keys(posting[fieldName]).length;
        }
        var x = (documentCount - documentsWithTerm + .5) / (documentsWithTerm + .5);
        return Math.log(1 + Math.abs(x));
    };
    lunr.Token = function(str, metadata) {
        this.str = str || "";
        this.metadata = metadata || {};
    };
    lunr.Token.prototype.toString = function() {
        return this.str;
    };
    lunr.Token.prototype.update = function(fn) {
        this.str = fn(this.str, this.metadata);
        return this;
    };
    lunr.Token.prototype.clone = function(fn) {
        fn = fn || function(s) {
            return s;
        };
        return new lunr.Token(fn(this.str, this.metadata), this.metadata);
    };
    lunr.tokenizer = function(obj, metadata) {
        if (obj == null || obj == undefined) {
            return [];
        }
        if (Array.isArray(obj)) {
            return obj.map(function(t) {
                return new lunr.Token(lunr.utils.asString(t).toLowerCase(), lunr.utils.clone(metadata));
            });
        }
        var str = obj.toString().toLowerCase(), len = str.length, tokens = [];
        for (var sliceEnd = 0, sliceStart = 0; sliceEnd <= len; sliceEnd++) {
            var char = str.charAt(sliceEnd), sliceLength = sliceEnd - sliceStart;
            if (char.match(lunr.tokenizer.separator) || sliceEnd == len) {
                if (sliceLength > 0) {
                    var tokenMetadata = lunr.utils.clone(metadata) || {};
                    tokenMetadata["position"] = [ sliceStart, sliceLength ];
                    tokenMetadata["index"] = tokens.length;
                    tokens.push(new lunr.Token(str.slice(sliceStart, sliceEnd), tokenMetadata));
                }
                sliceStart = sliceEnd + 1;
            }
        }
        return tokens;
    };
    lunr.tokenizer.separator = /[\s\-]+/;
    lunr.Pipeline = function() {
        this._stack = [];
    };
    lunr.Pipeline.registeredFunctions = Object.create(null);
    lunr.Pipeline.registerFunction = function(fn, label) {
        if (label in this.registeredFunctions) {
            lunr.utils.warn("Overwriting existing registered function: " + label);
        }
        fn.label = label;
        lunr.Pipeline.registeredFunctions[fn.label] = fn;
    };
    lunr.Pipeline.warnIfFunctionNotRegistered = function(fn) {
        var isRegistered = fn.label && fn.label in this.registeredFunctions;
        if (!isRegistered) {
            lunr.utils.warn("Function is not registered with pipeline. This may cause problems when serialising the index.\n", fn);
        }
    };
    lunr.Pipeline.load = function(serialised) {
        var pipeline = new lunr.Pipeline();
        serialised.forEach(function(fnName) {
            var fn = lunr.Pipeline.registeredFunctions[fnName];
            if (fn) {
                pipeline.add(fn);
            } else {
                throw new Error("Cannot load unregistered function: " + fnName);
            }
        });
        return pipeline;
    };
    lunr.Pipeline.prototype.add = function() {
        var fns = Array.prototype.slice.call(arguments);
        fns.forEach(function(fn) {
            lunr.Pipeline.warnIfFunctionNotRegistered(fn);
            this._stack.push(fn);
        }, this);
    };
    lunr.Pipeline.prototype.after = function(existingFn, newFn) {
        lunr.Pipeline.warnIfFunctionNotRegistered(newFn);
        var pos = this._stack.indexOf(existingFn);
        if (pos == -1) {
            throw new Error("Cannot find existingFn");
        }
        pos = pos + 1;
        this._stack.splice(pos, 0, newFn);
    };
    lunr.Pipeline.prototype.before = function(existingFn, newFn) {
        lunr.Pipeline.warnIfFunctionNotRegistered(newFn);
        var pos = this._stack.indexOf(existingFn);
        if (pos == -1) {
            throw new Error("Cannot find existingFn");
        }
        this._stack.splice(pos, 0, newFn);
    };
    lunr.Pipeline.prototype.remove = function(fn) {
        var pos = this._stack.indexOf(fn);
        if (pos == -1) {
            return;
        }
        this._stack.splice(pos, 1);
    };
    lunr.Pipeline.prototype.run = function(tokens) {
        var stackLength = this._stack.length;
        for (var i = 0; i < stackLength; i++) {
            var fn = this._stack[i];
            var memo = [];
            for (var j = 0; j < tokens.length; j++) {
                var result = fn(tokens[j], j, tokens);
                if (result === null || result === void 0 || result === "") continue;
                if (Array.isArray(result)) {
                    for (var k = 0; k < result.length; k++) {
                        memo.push(result[k]);
                    }
                } else {
                    memo.push(result);
                }
            }
            tokens = memo;
        }
        return tokens;
    };
    lunr.Pipeline.prototype.runString = function(str, metadata) {
        var token = new lunr.Token(str, metadata);
        return this.run([ token ]).map(function(t) {
            return t.toString();
        });
    };
    lunr.Pipeline.prototype.reset = function() {
        this._stack = [];
    };
    lunr.Pipeline.prototype.toJSON = function() {
        return this._stack.map(function(fn) {
            lunr.Pipeline.warnIfFunctionNotRegistered(fn);
            return fn.label;
        });
    };
    lunr.Vector = function(elements) {
        this._magnitude = 0;
        this.elements = elements || [];
    };
    lunr.Vector.prototype.positionForIndex = function(index) {
        if (this.elements.length == 0) {
            return 0;
        }
        var start = 0, end = this.elements.length / 2, sliceLength = end - start, pivotPoint = Math.floor(sliceLength / 2), pivotIndex = this.elements[pivotPoint * 2];
        while (sliceLength > 1) {
            if (pivotIndex < index) {
                start = pivotPoint;
            }
            if (pivotIndex > index) {
                end = pivotPoint;
            }
            if (pivotIndex == index) {
                break;
            }
            sliceLength = end - start;
            pivotPoint = start + Math.floor(sliceLength / 2);
            pivotIndex = this.elements[pivotPoint * 2];
        }
        if (pivotIndex == index) {
            return pivotPoint * 2;
        }
        if (pivotIndex > index) {
            return pivotPoint * 2;
        }
        if (pivotIndex < index) {
            return (pivotPoint + 1) * 2;
        }
    };
    lunr.Vector.prototype.insert = function(insertIdx, val) {
        this.upsert(insertIdx, val, function() {
            throw "duplicate index";
        });
    };
    lunr.Vector.prototype.upsert = function(insertIdx, val, fn) {
        this._magnitude = 0;
        var position = this.positionForIndex(insertIdx);
        if (this.elements[position] == insertIdx) {
            this.elements[position + 1] = fn(this.elements[position + 1], val);
        } else {
            this.elements.splice(position, 0, insertIdx, val);
        }
    };
    lunr.Vector.prototype.magnitude = function() {
        if (this._magnitude) return this._magnitude;
        var sumOfSquares = 0, elementsLength = this.elements.length;
        for (var i = 1; i < elementsLength; i += 2) {
            var val = this.elements[i];
            sumOfSquares += val * val;
        }
        return this._magnitude = Math.sqrt(sumOfSquares);
    };
    lunr.Vector.prototype.dot = function(otherVector) {
        var dotProduct = 0, a = this.elements, b = otherVector.elements, aLen = a.length, bLen = b.length, aVal = 0, bVal = 0, i = 0, j = 0;
        while (i < aLen && j < bLen) {
            aVal = a[i], bVal = b[j];
            if (aVal < bVal) {
                i += 2;
            } else if (aVal > bVal) {
                j += 2;
            } else if (aVal == bVal) {
                dotProduct += a[i + 1] * b[j + 1];
                i += 2;
                j += 2;
            }
        }
        return dotProduct;
    };
    lunr.Vector.prototype.similarity = function(otherVector) {
        return this.dot(otherVector) / this.magnitude() || 0;
    };
    lunr.Vector.prototype.toArray = function() {
        var output = new Array(this.elements.length / 2);
        for (var i = 1, j = 0; i < this.elements.length; i += 2, j++) {
            output[j] = this.elements[i];
        }
        return output;
    };
    lunr.Vector.prototype.toJSON = function() {
        return this.elements;
    };
    lunr.stemmer = function() {
        var step2list = {
            ational: "ate",
            tional: "tion",
            enci: "ence",
            anci: "ance",
            izer: "ize",
            bli: "ble",
            alli: "al",
            entli: "ent",
            eli: "e",
            ousli: "ous",
            ization: "ize",
            ation: "ate",
            ator: "ate",
            alism: "al",
            iveness: "ive",
            fulness: "ful",
            ousness: "ous",
            aliti: "al",
            iviti: "ive",
            biliti: "ble",
            logi: "log"
        }, step3list = {
            icate: "ic",
            ative: "",
            alize: "al",
            iciti: "ic",
            ical: "ic",
            ful: "",
            ness: ""
        }, c = "[^aeiou]", v = "[aeiouy]", C = c + "[^aeiouy]*", V = v + "[aeiou]*", mgr0 = "^(" + C + ")?" + V + C, meq1 = "^(" + C + ")?" + V + C + "(" + V + ")?$", mgr1 = "^(" + C + ")?" + V + C + V + C, s_v = "^(" + C + ")?" + v;
        var re_mgr0 = new RegExp(mgr0);
        var re_mgr1 = new RegExp(mgr1);
        var re_meq1 = new RegExp(meq1);
        var re_s_v = new RegExp(s_v);
        var re_1a = /^(.+?)(ss|i)es$/;
        var re2_1a = /^(.+?)([^s])s$/;
        var re_1b = /^(.+?)eed$/;
        var re2_1b = /^(.+?)(ed|ing)$/;
        var re_1b_2 = /.$/;
        var re2_1b_2 = /(at|bl|iz)$/;
        var re3_1b_2 = new RegExp("([^aeiouylsz])\\1$");
        var re4_1b_2 = new RegExp("^" + C + v + "[^aeiouwxy]$");
        var re_1c = /^(.+?[^aeiou])y$/;
        var re_2 = /^(.+?)(ational|tional|enci|anci|izer|bli|alli|entli|eli|ousli|ization|ation|ator|alism|iveness|fulness|ousness|aliti|iviti|biliti|logi)$/;
        var re_3 = /^(.+?)(icate|ative|alize|iciti|ical|ful|ness)$/;
        var re_4 = /^(.+?)(al|ance|ence|er|ic|able|ible|ant|ement|ment|ent|ou|ism|ate|iti|ous|ive|ize)$/;
        var re2_4 = /^(.+?)(s|t)(ion)$/;
        var re_5 = /^(.+?)e$/;
        var re_5_1 = /ll$/;
        var re3_5 = new RegExp("^" + C + v + "[^aeiouwxy]$");
        var porterStemmer = function porterStemmer(w) {
            var stem, suffix, firstch, re, re2, re3, re4;
            if (w.length < 3) {
                return w;
            }
            firstch = w.substr(0, 1);
            if (firstch == "y") {
                w = firstch.toUpperCase() + w.substr(1);
            }
            re = re_1a;
            re2 = re2_1a;
            if (re.test(w)) {
                w = w.replace(re, "$1$2");
            } else if (re2.test(w)) {
                w = w.replace(re2, "$1$2");
            }
            re = re_1b;
            re2 = re2_1b;
            if (re.test(w)) {
                var fp = re.exec(w);
                re = re_mgr0;
                if (re.test(fp[1])) {
                    re = re_1b_2;
                    w = w.replace(re, "");
                }
            } else if (re2.test(w)) {
                var fp = re2.exec(w);
                stem = fp[1];
                re2 = re_s_v;
                if (re2.test(stem)) {
                    w = stem;
                    re2 = re2_1b_2;
                    re3 = re3_1b_2;
                    re4 = re4_1b_2;
                    if (re2.test(w)) {
                        w = w + "e";
                    } else if (re3.test(w)) {
                        re = re_1b_2;
                        w = w.replace(re, "");
                    } else if (re4.test(w)) {
                        w = w + "e";
                    }
                }
            }
            re = re_1c;
            if (re.test(w)) {
                var fp = re.exec(w);
                stem = fp[1];
                w = stem + "i";
            }
            re = re_2;
            if (re.test(w)) {
                var fp = re.exec(w);
                stem = fp[1];
                suffix = fp[2];
                re = re_mgr0;
                if (re.test(stem)) {
                    w = stem + step2list[suffix];
                }
            }
            re = re_3;
            if (re.test(w)) {
                var fp = re.exec(w);
                stem = fp[1];
                suffix = fp[2];
                re = re_mgr0;
                if (re.test(stem)) {
                    w = stem + step3list[suffix];
                }
            }
            re = re_4;
            re2 = re2_4;
            if (re.test(w)) {
                var fp = re.exec(w);
                stem = fp[1];
                re = re_mgr1;
                if (re.test(stem)) {
                    w = stem;
                }
            } else if (re2.test(w)) {
                var fp = re2.exec(w);
                stem = fp[1] + fp[2];
                re2 = re_mgr1;
                if (re2.test(stem)) {
                    w = stem;
                }
            }
            re = re_5;
            if (re.test(w)) {
                var fp = re.exec(w);
                stem = fp[1];
                re = re_mgr1;
                re2 = re_meq1;
                re3 = re3_5;
                if (re.test(stem) || re2.test(stem) && !re3.test(stem)) {
                    w = stem;
                }
            }
            re = re_5_1;
            re2 = re_mgr1;
            if (re.test(w) && re2.test(w)) {
                re = re_1b_2;
                w = w.replace(re, "");
            }
            if (firstch == "y") {
                w = firstch.toLowerCase() + w.substr(1);
            }
            return w;
        };
        return function(token) {
            return token.update(porterStemmer);
        };
    }();
    lunr.Pipeline.registerFunction(lunr.stemmer, "stemmer");
    lunr.generateStopWordFilter = function(stopWords) {
        var words = stopWords.reduce(function(memo, stopWord) {
            memo[stopWord] = stopWord;
            return memo;
        }, {});
        return function(token) {
            if (token && words[token.toString()] !== token.toString()) return token;
        };
    };
    lunr.stopWordFilter = lunr.generateStopWordFilter([ "a", "able", "about", "across", "after", "all", "almost", "also", "am", "among", "an", "and", "any", "are", "as", "at", "be", "because", "been", "but", "by", "can", "cannot", "could", "dear", "did", "do", "does", "either", "else", "ever", "every", "for", "from", "get", "got", "had", "has", "have", "he", "her", "hers", "him", "his", "how", "however", "i", "if", "in", "into", "is", "it", "its", "just", "least", "let", "like", "likely", "may", "me", "might", "most", "must", "my", "neither", "no", "nor", "not", "of", "off", "often", "on", "only", "or", "other", "our", "own", "rather", "said", "say", "says", "she", "should", "since", "so", "some", "than", "that", "the", "their", "them", "then", "there", "these", "they", "this", "tis", "to", "too", "twas", "us", "wants", "was", "we", "were", "what", "when", "where", "which", "while", "who", "whom", "why", "will", "with", "would", "yet", "you", "your" ]);
    lunr.Pipeline.registerFunction(lunr.stopWordFilter, "stopWordFilter");
    lunr.trimmer = function(token) {
        return token.update(function(s) {
            return s.replace(/^\W+/, "").replace(/\W+$/, "");
        });
    };
    lunr.Pipeline.registerFunction(lunr.trimmer, "trimmer");
    lunr.TokenSet = function() {
        this.final = false;
        this.edges = {};
        this.id = lunr.TokenSet._nextId;
        lunr.TokenSet._nextId += 1;
    };
    lunr.TokenSet._nextId = 1;
    lunr.TokenSet.fromArray = function(arr) {
        var builder = new lunr.TokenSet.Builder();
        for (var i = 0, len = arr.length; i < len; i++) {
            builder.insert(arr[i]);
        }
        builder.finish();
        return builder.root;
    };
    lunr.TokenSet.fromClause = function(clause) {
        if ("editDistance" in clause) {
            return lunr.TokenSet.fromFuzzyString(clause.term, clause.editDistance);
        } else {
            return lunr.TokenSet.fromString(clause.term);
        }
    };
    lunr.TokenSet.fromFuzzyString = function(str, editDistance) {
        var root = new lunr.TokenSet();
        var stack = [ {
            node: root,
            editsRemaining: editDistance,
            str: str
        } ];
        while (stack.length) {
            var frame = stack.pop();
            if (frame.str.length > 0) {
                var char = frame.str.charAt(0), noEditNode;
                if (char in frame.node.edges) {
                    noEditNode = frame.node.edges[char];
                } else {
                    noEditNode = new lunr.TokenSet();
                    frame.node.edges[char] = noEditNode;
                }
                if (frame.str.length == 1) {
                    noEditNode.final = true;
                }
                stack.push({
                    node: noEditNode,
                    editsRemaining: frame.editsRemaining,
                    str: frame.str.slice(1)
                });
            }
            if (frame.editsRemaining == 0) {
                continue;
            }
            if ("*" in frame.node.edges) {
                var insertionNode = frame.node.edges["*"];
            } else {
                var insertionNode = new lunr.TokenSet();
                frame.node.edges["*"] = insertionNode;
            }
            if (frame.str.length == 0) {
                insertionNode.final = true;
            }
            stack.push({
                node: insertionNode,
                editsRemaining: frame.editsRemaining - 1,
                str: frame.str
            });
            if (frame.str.length > 1) {
                stack.push({
                    node: frame.node,
                    editsRemaining: frame.editsRemaining - 1,
                    str: frame.str.slice(1)
                });
            }
            if (frame.str.length == 1) {
                frame.node.final = true;
            }
            if (frame.str.length >= 1) {
                if ("*" in frame.node.edges) {
                    var substitutionNode = frame.node.edges["*"];
                } else {
                    var substitutionNode = new lunr.TokenSet();
                    frame.node.edges["*"] = substitutionNode;
                }
                if (frame.str.length == 1) {
                    substitutionNode.final = true;
                }
                stack.push({
                    node: substitutionNode,
                    editsRemaining: frame.editsRemaining - 1,
                    str: frame.str.slice(1)
                });
            }
            if (frame.str.length > 1) {
                var charA = frame.str.charAt(0), charB = frame.str.charAt(1), transposeNode;
                if (charB in frame.node.edges) {
                    transposeNode = frame.node.edges[charB];
                } else {
                    transposeNode = new lunr.TokenSet();
                    frame.node.edges[charB] = transposeNode;
                }
                if (frame.str.length == 1) {
                    transposeNode.final = true;
                }
                stack.push({
                    node: transposeNode,
                    editsRemaining: frame.editsRemaining - 1,
                    str: charA + frame.str.slice(2)
                });
            }
        }
        return root;
    };
    lunr.TokenSet.fromString = function(str) {
        var node = new lunr.TokenSet(), root = node;
        for (var i = 0, len = str.length; i < len; i++) {
            var char = str[i], final = i == len - 1;
            if (char == "*") {
                node.edges[char] = node;
                node.final = final;
            } else {
                var next = new lunr.TokenSet();
                next.final = final;
                node.edges[char] = next;
                node = next;
            }
        }
        return root;
    };
    lunr.TokenSet.prototype.toArray = function() {
        var words = [];
        var stack = [ {
            prefix: "",
            node: this
        } ];
        while (stack.length) {
            var frame = stack.pop(), edges = Object.keys(frame.node.edges), len = edges.length;
            if (frame.node.final) {
                frame.prefix.charAt(0);
                words.push(frame.prefix);
            }
            for (var i = 0; i < len; i++) {
                var edge = edges[i];
                stack.push({
                    prefix: frame.prefix.concat(edge),
                    node: frame.node.edges[edge]
                });
            }
        }
        return words;
    };
    lunr.TokenSet.prototype.toString = function() {
        if (this._str) {
            return this._str;
        }
        var str = this.final ? "1" : "0", labels = Object.keys(this.edges).sort(), len = labels.length;
        for (var i = 0; i < len; i++) {
            var label = labels[i], node = this.edges[label];
            str = str + label + node.id;
        }
        return str;
    };
    lunr.TokenSet.prototype.intersect = function(b) {
        var output = new lunr.TokenSet(), frame = undefined;
        var stack = [ {
            qNode: b,
            output: output,
            node: this
        } ];
        while (stack.length) {
            frame = stack.pop();
            var qEdges = Object.keys(frame.qNode.edges), qLen = qEdges.length, nEdges = Object.keys(frame.node.edges), nLen = nEdges.length;
            for (var q = 0; q < qLen; q++) {
                var qEdge = qEdges[q];
                for (var n = 0; n < nLen; n++) {
                    var nEdge = nEdges[n];
                    if (nEdge == qEdge || qEdge == "*") {
                        var node = frame.node.edges[nEdge], qNode = frame.qNode.edges[qEdge], final = node.final && qNode.final, next = undefined;
                        if (nEdge in frame.output.edges) {
                            next = frame.output.edges[nEdge];
                            next.final = next.final || final;
                        } else {
                            next = new lunr.TokenSet();
                            next.final = final;
                            frame.output.edges[nEdge] = next;
                        }
                        stack.push({
                            qNode: qNode,
                            output: next,
                            node: node
                        });
                    }
                }
            }
        }
        return output;
    };
    lunr.TokenSet.Builder = function() {
        this.previousWord = "";
        this.root = new lunr.TokenSet();
        this.uncheckedNodes = [];
        this.minimizedNodes = {};
    };
    lunr.TokenSet.Builder.prototype.insert = function(word) {
        var node, commonPrefix = 0;
        if (word < this.previousWord) {
            throw new Error("Out of order word insertion");
        }
        for (var i = 0; i < word.length && i < this.previousWord.length; i++) {
            if (word[i] != this.previousWord[i]) break;
            commonPrefix++;
        }
        this.minimize(commonPrefix);
        if (this.uncheckedNodes.length == 0) {
            node = this.root;
        } else {
            node = this.uncheckedNodes[this.uncheckedNodes.length - 1].child;
        }
        for (var i = commonPrefix; i < word.length; i++) {
            var nextNode = new lunr.TokenSet(), char = word[i];
            node.edges[char] = nextNode;
            this.uncheckedNodes.push({
                parent: node,
                char: char,
                child: nextNode
            });
            node = nextNode;
        }
        node.final = true;
        this.previousWord = word;
    };
    lunr.TokenSet.Builder.prototype.finish = function() {
        this.minimize(0);
    };
    lunr.TokenSet.Builder.prototype.minimize = function(downTo) {
        for (var i = this.uncheckedNodes.length - 1; i >= downTo; i--) {
            var node = this.uncheckedNodes[i], childKey = node.child.toString();
            if (childKey in this.minimizedNodes) {
                node.parent.edges[node.char] = this.minimizedNodes[childKey];
            } else {
                node.child._str = childKey;
                this.minimizedNodes[childKey] = node.child;
            }
            this.uncheckedNodes.pop();
        }
    };
    lunr.Index = function(attrs) {
        this.invertedIndex = attrs.invertedIndex;
        this.fieldVectors = attrs.fieldVectors;
        this.tokenSet = attrs.tokenSet;
        this.fields = attrs.fields;
        this.pipeline = attrs.pipeline;
    };
    lunr.Index.prototype.search = function(queryString) {
        return this.query(function(query) {
            var parser = new lunr.QueryParser(queryString, query);
            parser.parse();
        });
    };
    lunr.Index.prototype.query = function(fn) {
        var query = new lunr.Query(this.fields), matchingFields = Object.create(null), queryVectors = Object.create(null), termFieldCache = Object.create(null), requiredMatches = Object.create(null), prohibitedMatches = Object.create(null);
        for (var i = 0; i < this.fields.length; i++) {
            queryVectors[this.fields[i]] = new lunr.Vector();
        }
        fn.call(query, query);
        for (var i = 0; i < query.clauses.length; i++) {
            var clause = query.clauses[i], terms = null, clauseMatches = lunr.Set.complete;
            if (clause.usePipeline) {
                terms = this.pipeline.runString(clause.term, {
                    fields: clause.fields
                });
            } else {
                terms = [ clause.term ];
            }
            for (var m = 0; m < terms.length; m++) {
                var term = terms[m];
                clause.term = term;
                var termTokenSet = lunr.TokenSet.fromClause(clause), expandedTerms = this.tokenSet.intersect(termTokenSet).toArray();
                if (expandedTerms.length === 0 && clause.presence === lunr.Query.presence.REQUIRED) {
                    for (var k = 0; k < clause.fields.length; k++) {
                        var field = clause.fields[k];
                        requiredMatches[field] = lunr.Set.empty;
                    }
                    break;
                }
                for (var j = 0; j < expandedTerms.length; j++) {
                    var expandedTerm = expandedTerms[j], posting = this.invertedIndex[expandedTerm], termIndex = posting._index;
                    for (var k = 0; k < clause.fields.length; k++) {
                        var field = clause.fields[k], fieldPosting = posting[field], matchingDocumentRefs = Object.keys(fieldPosting), termField = expandedTerm + "/" + field, matchingDocumentsSet = new lunr.Set(matchingDocumentRefs);
                        if (clause.presence == lunr.Query.presence.REQUIRED) {
                            clauseMatches = clauseMatches.union(matchingDocumentsSet);
                            if (requiredMatches[field] === undefined) {
                                requiredMatches[field] = lunr.Set.complete;
                            }
                        }
                        if (clause.presence == lunr.Query.presence.PROHIBITED) {
                            if (prohibitedMatches[field] === undefined) {
                                prohibitedMatches[field] = lunr.Set.empty;
                            }
                            prohibitedMatches[field] = prohibitedMatches[field].union(matchingDocumentsSet);
                            continue;
                        }
                        queryVectors[field].upsert(termIndex, clause.boost, function(a, b) {
                            return a + b;
                        });
                        if (termFieldCache[termField]) {
                            continue;
                        }
                        for (var l = 0; l < matchingDocumentRefs.length; l++) {
                            var matchingDocumentRef = matchingDocumentRefs[l], matchingFieldRef = new lunr.FieldRef(matchingDocumentRef, field), metadata = fieldPosting[matchingDocumentRef], fieldMatch;
                            if ((fieldMatch = matchingFields[matchingFieldRef]) === undefined) {
                                matchingFields[matchingFieldRef] = new lunr.MatchData(expandedTerm, field, metadata);
                            } else {
                                fieldMatch.add(expandedTerm, field, metadata);
                            }
                        }
                        termFieldCache[termField] = true;
                    }
                }
            }
            if (clause.presence === lunr.Query.presence.REQUIRED) {
                for (var k = 0; k < clause.fields.length; k++) {
                    var field = clause.fields[k];
                    requiredMatches[field] = requiredMatches[field].intersect(clauseMatches);
                }
            }
        }
        var allRequiredMatches = lunr.Set.complete, allProhibitedMatches = lunr.Set.empty;
        for (var i = 0; i < this.fields.length; i++) {
            var field = this.fields[i];
            if (requiredMatches[field]) {
                allRequiredMatches = allRequiredMatches.intersect(requiredMatches[field]);
            }
            if (prohibitedMatches[field]) {
                allProhibitedMatches = allProhibitedMatches.union(prohibitedMatches[field]);
            }
        }
        var matchingFieldRefs = Object.keys(matchingFields), results = [], matches = Object.create(null);
        if (query.isNegated()) {
            matchingFieldRefs = Object.keys(this.fieldVectors);
            for (var i = 0; i < matchingFieldRefs.length; i++) {
                var matchingFieldRef = matchingFieldRefs[i];
                var fieldRef = lunr.FieldRef.fromString(matchingFieldRef);
                matchingFields[matchingFieldRef] = new lunr.MatchData();
            }
        }
        for (var i = 0; i < matchingFieldRefs.length; i++) {
            var fieldRef = lunr.FieldRef.fromString(matchingFieldRefs[i]), docRef = fieldRef.docRef;
            if (!allRequiredMatches.contains(docRef)) {
                continue;
            }
            if (allProhibitedMatches.contains(docRef)) {
                continue;
            }
            var fieldVector = this.fieldVectors[fieldRef], score = queryVectors[fieldRef.fieldName].similarity(fieldVector), docMatch;
            if ((docMatch = matches[docRef]) !== undefined) {
                docMatch.score += score;
                docMatch.matchData.combine(matchingFields[fieldRef]);
            } else {
                var match = {
                    ref: docRef,
                    score: score,
                    matchData: matchingFields[fieldRef]
                };
                matches[docRef] = match;
                results.push(match);
            }
        }
        return results.sort(function(a, b) {
            return b.score - a.score;
        });
    };
    lunr.Index.prototype.toJSON = function() {
        var invertedIndex = Object.keys(this.invertedIndex).sort().map(function(term) {
            return [ term, this.invertedIndex[term] ];
        }, this);
        var fieldVectors = Object.keys(this.fieldVectors).map(function(ref) {
            return [ ref, this.fieldVectors[ref].toJSON() ];
        }, this);
        return {
            version: lunr.version,
            fields: this.fields,
            fieldVectors: fieldVectors,
            invertedIndex: invertedIndex,
            pipeline: this.pipeline.toJSON()
        };
    };
    lunr.Index.load = function(serializedIndex) {
        var attrs = {}, fieldVectors = {}, serializedVectors = serializedIndex.fieldVectors, invertedIndex = Object.create(null), serializedInvertedIndex = serializedIndex.invertedIndex, tokenSetBuilder = new lunr.TokenSet.Builder(), pipeline = lunr.Pipeline.load(serializedIndex.pipeline);
        if (serializedIndex.version != lunr.version) {
            lunr.utils.warn("Version mismatch when loading serialised index. Current version of lunr '" + lunr.version + "' does not match serialized index '" + serializedIndex.version + "'");
        }
        for (var i = 0; i < serializedVectors.length; i++) {
            var tuple = serializedVectors[i], ref = tuple[0], elements = tuple[1];
            fieldVectors[ref] = new lunr.Vector(elements);
        }
        for (var i = 0; i < serializedInvertedIndex.length; i++) {
            var tuple = serializedInvertedIndex[i], term = tuple[0], posting = tuple[1];
            tokenSetBuilder.insert(term);
            invertedIndex[term] = posting;
        }
        tokenSetBuilder.finish();
        attrs.fields = serializedIndex.fields;
        attrs.fieldVectors = fieldVectors;
        attrs.invertedIndex = invertedIndex;
        attrs.tokenSet = tokenSetBuilder.root;
        attrs.pipeline = pipeline;
        return new lunr.Index(attrs);
    };
    lunr.Builder = function() {
        this._ref = "id";
        this._fields = Object.create(null);
        this._documents = Object.create(null);
        this.invertedIndex = Object.create(null);
        this.fieldTermFrequencies = {};
        this.fieldLengths = {};
        this.tokenizer = lunr.tokenizer;
        this.pipeline = new lunr.Pipeline();
        this.searchPipeline = new lunr.Pipeline();
        this.documentCount = 0;
        this._b = .75;
        this._k1 = 1.2;
        this.termIndex = 0;
        this.metadataWhitelist = [];
    };
    lunr.Builder.prototype.ref = function(ref) {
        this._ref = ref;
    };
    lunr.Builder.prototype.field = function(fieldName, attributes) {
        if (/\//.test(fieldName)) {
            throw new RangeError("Field '" + fieldName + "' contains illegal character '/'");
        }
        this._fields[fieldName] = attributes || {};
    };
    lunr.Builder.prototype.b = function(number) {
        if (number < 0) {
            this._b = 0;
        } else if (number > 1) {
            this._b = 1;
        } else {
            this._b = number;
        }
    };
    lunr.Builder.prototype.k1 = function(number) {
        this._k1 = number;
    };
    lunr.Builder.prototype.add = function(doc, attributes) {
        var docRef = doc[this._ref], fields = Object.keys(this._fields);
        this._documents[docRef] = attributes || {};
        this.documentCount += 1;
        for (var i = 0; i < fields.length; i++) {
            var fieldName = fields[i], extractor = this._fields[fieldName].extractor, field = extractor ? extractor(doc) : doc[fieldName], tokens = this.tokenizer(field, {
                fields: [ fieldName ]
            }), terms = this.pipeline.run(tokens), fieldRef = new lunr.FieldRef(docRef, fieldName), fieldTerms = Object.create(null);
            this.fieldTermFrequencies[fieldRef] = fieldTerms;
            this.fieldLengths[fieldRef] = 0;
            this.fieldLengths[fieldRef] += terms.length;
            for (var j = 0; j < terms.length; j++) {
                var term = terms[j];
                if (fieldTerms[term] == undefined) {
                    fieldTerms[term] = 0;
                }
                fieldTerms[term] += 1;
                if (this.invertedIndex[term] == undefined) {
                    var posting = Object.create(null);
                    posting["_index"] = this.termIndex;
                    this.termIndex += 1;
                    for (var k = 0; k < fields.length; k++) {
                        posting[fields[k]] = Object.create(null);
                    }
                    this.invertedIndex[term] = posting;
                }
                if (this.invertedIndex[term][fieldName][docRef] == undefined) {
                    this.invertedIndex[term][fieldName][docRef] = Object.create(null);
                }
                for (var l = 0; l < this.metadataWhitelist.length; l++) {
                    var metadataKey = this.metadataWhitelist[l], metadata = term.metadata[metadataKey];
                    if (this.invertedIndex[term][fieldName][docRef][metadataKey] == undefined) {
                        this.invertedIndex[term][fieldName][docRef][metadataKey] = [];
                    }
                    this.invertedIndex[term][fieldName][docRef][metadataKey].push(metadata);
                }
            }
        }
    };
    lunr.Builder.prototype.calculateAverageFieldLengths = function() {
        var fieldRefs = Object.keys(this.fieldLengths), numberOfFields = fieldRefs.length, accumulator = {}, documentsWithField = {};
        for (var i = 0; i < numberOfFields; i++) {
            var fieldRef = lunr.FieldRef.fromString(fieldRefs[i]), field = fieldRef.fieldName;
            documentsWithField[field] || (documentsWithField[field] = 0);
            documentsWithField[field] += 1;
            accumulator[field] || (accumulator[field] = 0);
            accumulator[field] += this.fieldLengths[fieldRef];
        }
        var fields = Object.keys(this._fields);
        for (var i = 0; i < fields.length; i++) {
            var fieldName = fields[i];
            accumulator[fieldName] = accumulator[fieldName] / documentsWithField[fieldName];
        }
        this.averageFieldLength = accumulator;
    };
    lunr.Builder.prototype.createFieldVectors = function() {
        var fieldVectors = {}, fieldRefs = Object.keys(this.fieldTermFrequencies), fieldRefsLength = fieldRefs.length, termIdfCache = Object.create(null);
        for (var i = 0; i < fieldRefsLength; i++) {
            var fieldRef = lunr.FieldRef.fromString(fieldRefs[i]), fieldName = fieldRef.fieldName, fieldLength = this.fieldLengths[fieldRef], fieldVector = new lunr.Vector(), termFrequencies = this.fieldTermFrequencies[fieldRef], terms = Object.keys(termFrequencies), termsLength = terms.length;
            var fieldBoost = this._fields[fieldName].boost || 1, docBoost = this._documents[fieldRef.docRef].boost || 1;
            for (var j = 0; j < termsLength; j++) {
                var term = terms[j], tf = termFrequencies[term], termIndex = this.invertedIndex[term]._index, idf, score, scoreWithPrecision;
                if (termIdfCache[term] === undefined) {
                    idf = lunr.idf(this.invertedIndex[term], this.documentCount);
                    termIdfCache[term] = idf;
                } else {
                    idf = termIdfCache[term];
                }
                score = idf * ((this._k1 + 1) * tf) / (this._k1 * (1 - this._b + this._b * (fieldLength / this.averageFieldLength[fieldName])) + tf);
                score *= fieldBoost;
                score *= docBoost;
                scoreWithPrecision = Math.round(score * 1e3) / 1e3;
                fieldVector.insert(termIndex, scoreWithPrecision);
            }
            fieldVectors[fieldRef] = fieldVector;
        }
        this.fieldVectors = fieldVectors;
    };
    lunr.Builder.prototype.createTokenSet = function() {
        this.tokenSet = lunr.TokenSet.fromArray(Object.keys(this.invertedIndex).sort());
    };
    lunr.Builder.prototype.build = function() {
        this.calculateAverageFieldLengths();
        this.createFieldVectors();
        this.createTokenSet();
        return new lunr.Index({
            invertedIndex: this.invertedIndex,
            fieldVectors: this.fieldVectors,
            tokenSet: this.tokenSet,
            fields: Object.keys(this._fields),
            pipeline: this.searchPipeline
        });
    };
    lunr.Builder.prototype.use = function(fn) {
        var args = Array.prototype.slice.call(arguments, 1);
        args.unshift(this);
        fn.apply(this, args);
    };
    lunr.MatchData = function(term, field, metadata) {
        var clonedMetadata = Object.create(null), metadataKeys = Object.keys(metadata || {});
        for (var i = 0; i < metadataKeys.length; i++) {
            var key = metadataKeys[i];
            clonedMetadata[key] = metadata[key].slice();
        }
        this.metadata = Object.create(null);
        if (term !== undefined) {
            this.metadata[term] = Object.create(null);
            this.metadata[term][field] = clonedMetadata;
        }
    };
    lunr.MatchData.prototype.combine = function(otherMatchData) {
        var terms = Object.keys(otherMatchData.metadata);
        for (var i = 0; i < terms.length; i++) {
            var term = terms[i], fields = Object.keys(otherMatchData.metadata[term]);
            if (this.metadata[term] == undefined) {
                this.metadata[term] = Object.create(null);
            }
            for (var j = 0; j < fields.length; j++) {
                var field = fields[j], keys = Object.keys(otherMatchData.metadata[term][field]);
                if (this.metadata[term][field] == undefined) {
                    this.metadata[term][field] = Object.create(null);
                }
                for (var k = 0; k < keys.length; k++) {
                    var key = keys[k];
                    if (this.metadata[term][field][key] == undefined) {
                        this.metadata[term][field][key] = otherMatchData.metadata[term][field][key];
                    } else {
                        this.metadata[term][field][key] = this.metadata[term][field][key].concat(otherMatchData.metadata[term][field][key]);
                    }
                }
            }
        }
    };
    lunr.MatchData.prototype.add = function(term, field, metadata) {
        if (!(term in this.metadata)) {
            this.metadata[term] = Object.create(null);
            this.metadata[term][field] = metadata;
            return;
        }
        if (!(field in this.metadata[term])) {
            this.metadata[term][field] = metadata;
            return;
        }
        var metadataKeys = Object.keys(metadata);
        for (var i = 0; i < metadataKeys.length; i++) {
            var key = metadataKeys[i];
            if (key in this.metadata[term][field]) {
                this.metadata[term][field][key] = this.metadata[term][field][key].concat(metadata[key]);
            } else {
                this.metadata[term][field][key] = metadata[key];
            }
        }
    };
    lunr.Query = function(allFields) {
        this.clauses = [];
        this.allFields = allFields;
    };
    lunr.Query.wildcard = new String("*");
    lunr.Query.wildcard.NONE = 0;
    lunr.Query.wildcard.LEADING = 1;
    lunr.Query.wildcard.TRAILING = 2;
    lunr.Query.presence = {
        OPTIONAL: 1,
        REQUIRED: 2,
        PROHIBITED: 3
    };
    lunr.Query.prototype.clause = function(clause) {
        if (!("fields" in clause)) {
            clause.fields = this.allFields;
        }
        if (!("boost" in clause)) {
            clause.boost = 1;
        }
        if (!("usePipeline" in clause)) {
            clause.usePipeline = true;
        }
        if (!("wildcard" in clause)) {
            clause.wildcard = lunr.Query.wildcard.NONE;
        }
        if (clause.wildcard & lunr.Query.wildcard.LEADING && clause.term.charAt(0) != lunr.Query.wildcard) {
            clause.term = "*" + clause.term;
        }
        if (clause.wildcard & lunr.Query.wildcard.TRAILING && clause.term.slice(-1) != lunr.Query.wildcard) {
            clause.term = "" + clause.term + "*";
        }
        if (!("presence" in clause)) {
            clause.presence = lunr.Query.presence.OPTIONAL;
        }
        this.clauses.push(clause);
        return this;
    };
    lunr.Query.prototype.isNegated = function() {
        for (var i = 0; i < this.clauses.length; i++) {
            if (this.clauses[i].presence != lunr.Query.presence.PROHIBITED) {
                return false;
            }
        }
        return true;
    };
    lunr.Query.prototype.term = function(term, options) {
        if (Array.isArray(term)) {
            term.forEach(function(t) {
                this.term(t, lunr.utils.clone(options));
            }, this);
            return this;
        }
        var clause = options || {};
        clause.term = term.toString();
        this.clause(clause);
        return this;
    };
    lunr.QueryParseError = function(message, start, end) {
        this.name = "QueryParseError";
        this.message = message;
        this.start = start;
        this.end = end;
    };
    lunr.QueryParseError.prototype = new Error();
    lunr.QueryLexer = function(str) {
        this.lexemes = [];
        this.str = str;
        this.length = str.length;
        this.pos = 0;
        this.start = 0;
        this.escapeCharPositions = [];
    };
    lunr.QueryLexer.prototype.run = function() {
        var state = lunr.QueryLexer.lexText;
        while (state) {
            state = state(this);
        }
    };
    lunr.QueryLexer.prototype.sliceString = function() {
        var subSlices = [], sliceStart = this.start, sliceEnd = this.pos;
        for (var i = 0; i < this.escapeCharPositions.length; i++) {
            sliceEnd = this.escapeCharPositions[i];
            subSlices.push(this.str.slice(sliceStart, sliceEnd));
            sliceStart = sliceEnd + 1;
        }
        subSlices.push(this.str.slice(sliceStart, this.pos));
        this.escapeCharPositions.length = 0;
        return subSlices.join("");
    };
    lunr.QueryLexer.prototype.emit = function(type) {
        this.lexemes.push({
            type: type,
            str: this.sliceString(),
            start: this.start,
            end: this.pos
        });
        this.start = this.pos;
    };
    lunr.QueryLexer.prototype.escapeCharacter = function() {
        this.escapeCharPositions.push(this.pos - 1);
        this.pos += 1;
    };
    lunr.QueryLexer.prototype.next = function() {
        if (this.pos >= this.length) {
            return lunr.QueryLexer.EOS;
        }
        var char = this.str.charAt(this.pos);
        this.pos += 1;
        return char;
    };
    lunr.QueryLexer.prototype.width = function() {
        return this.pos - this.start;
    };
    lunr.QueryLexer.prototype.ignore = function() {
        if (this.start == this.pos) {
            this.pos += 1;
        }
        this.start = this.pos;
    };
    lunr.QueryLexer.prototype.backup = function() {
        this.pos -= 1;
    };
    lunr.QueryLexer.prototype.acceptDigitRun = function() {
        var char, charCode;
        do {
            char = this.next();
            charCode = char.charCodeAt(0);
        } while (charCode > 47 && charCode < 58);
        if (char != lunr.QueryLexer.EOS) {
            this.backup();
        }
    };
    lunr.QueryLexer.prototype.more = function() {
        return this.pos < this.length;
    };
    lunr.QueryLexer.EOS = "EOS";
    lunr.QueryLexer.FIELD = "FIELD";
    lunr.QueryLexer.TERM = "TERM";
    lunr.QueryLexer.EDIT_DISTANCE = "EDIT_DISTANCE";
    lunr.QueryLexer.BOOST = "BOOST";
    lunr.QueryLexer.PRESENCE = "PRESENCE";
    lunr.QueryLexer.lexField = function(lexer) {
        lexer.backup();
        lexer.emit(lunr.QueryLexer.FIELD);
        lexer.ignore();
        return lunr.QueryLexer.lexText;
    };
    lunr.QueryLexer.lexTerm = function(lexer) {
        if (lexer.width() > 1) {
            lexer.backup();
            lexer.emit(lunr.QueryLexer.TERM);
        }
        lexer.ignore();
        if (lexer.more()) {
            return lunr.QueryLexer.lexText;
        }
    };
    lunr.QueryLexer.lexEditDistance = function(lexer) {
        lexer.ignore();
        lexer.acceptDigitRun();
        lexer.emit(lunr.QueryLexer.EDIT_DISTANCE);
        return lunr.QueryLexer.lexText;
    };
    lunr.QueryLexer.lexBoost = function(lexer) {
        lexer.ignore();
        lexer.acceptDigitRun();
        lexer.emit(lunr.QueryLexer.BOOST);
        return lunr.QueryLexer.lexText;
    };
    lunr.QueryLexer.lexEOS = function(lexer) {
        if (lexer.width() > 0) {
            lexer.emit(lunr.QueryLexer.TERM);
        }
    };
    lunr.QueryLexer.termSeparator = lunr.tokenizer.separator;
    lunr.QueryLexer.lexText = function(lexer) {
        while (true) {
            var char = lexer.next();
            if (char == lunr.QueryLexer.EOS) {
                return lunr.QueryLexer.lexEOS;
            }
            if (char.charCodeAt(0) == 92) {
                lexer.escapeCharacter();
                continue;
            }
            if (char == ":") {
                return lunr.QueryLexer.lexField;
            }
            if (char == "~") {
                lexer.backup();
                if (lexer.width() > 0) {
                    lexer.emit(lunr.QueryLexer.TERM);
                }
                return lunr.QueryLexer.lexEditDistance;
            }
            if (char == "^") {
                lexer.backup();
                if (lexer.width() > 0) {
                    lexer.emit(lunr.QueryLexer.TERM);
                }
                return lunr.QueryLexer.lexBoost;
            }
            if (char == "+" && lexer.width() === 1) {
                lexer.emit(lunr.QueryLexer.PRESENCE);
                return lunr.QueryLexer.lexText;
            }
            if (char == "-" && lexer.width() === 1) {
                lexer.emit(lunr.QueryLexer.PRESENCE);
                return lunr.QueryLexer.lexText;
            }
            if (char.match(lunr.QueryLexer.termSeparator)) {
                return lunr.QueryLexer.lexTerm;
            }
        }
    };
    lunr.QueryParser = function(str, query) {
        this.lexer = new lunr.QueryLexer(str);
        this.query = query;
        this.currentClause = {};
        this.lexemeIdx = 0;
    };
    lunr.QueryParser.prototype.parse = function() {
        this.lexer.run();
        this.lexemes = this.lexer.lexemes;
        var state = lunr.QueryParser.parseClause;
        while (state) {
            state = state(this);
        }
        return this.query;
    };
    lunr.QueryParser.prototype.peekLexeme = function() {
        return this.lexemes[this.lexemeIdx];
    };
    lunr.QueryParser.prototype.consumeLexeme = function() {
        var lexeme = this.peekLexeme();
        this.lexemeIdx += 1;
        return lexeme;
    };
    lunr.QueryParser.prototype.nextClause = function() {
        var completedClause = this.currentClause;
        this.query.clause(completedClause);
        this.currentClause = {};
    };
    lunr.QueryParser.parseClause = function(parser) {
        var lexeme = parser.peekLexeme();
        if (lexeme == undefined) {
            return;
        }
        switch (lexeme.type) {
          case lunr.QueryLexer.PRESENCE:
            return lunr.QueryParser.parsePresence;

          case lunr.QueryLexer.FIELD:
            return lunr.QueryParser.parseField;

          case lunr.QueryLexer.TERM:
            return lunr.QueryParser.parseTerm;

          default:
            var errorMessage = "expected either a field or a term, found " + lexeme.type;
            if (lexeme.str.length >= 1) {
                errorMessage += " with value '" + lexeme.str + "'";
            }
            throw new lunr.QueryParseError(errorMessage, lexeme.start, lexeme.end);
        }
    };
    lunr.QueryParser.parsePresence = function(parser) {
        var lexeme = parser.consumeLexeme();
        if (lexeme == undefined) {
            return;
        }
        switch (lexeme.str) {
          case "-":
            parser.currentClause.presence = lunr.Query.presence.PROHIBITED;
            break;

          case "+":
            parser.currentClause.presence = lunr.Query.presence.REQUIRED;
            break;

          default:
            var errorMessage = "unrecognised presence operator'" + lexeme.str + "'";
            throw new lunr.QueryParseError(errorMessage, lexeme.start, lexeme.end);
        }
        var nextLexeme = parser.peekLexeme();
        if (nextLexeme == undefined) {
            var errorMessage = "expecting term or field, found nothing";
            throw new lunr.QueryParseError(errorMessage, lexeme.start, lexeme.end);
        }
        switch (nextLexeme.type) {
          case lunr.QueryLexer.FIELD:
            return lunr.QueryParser.parseField;

          case lunr.QueryLexer.TERM:
            return lunr.QueryParser.parseTerm;

          default:
            var errorMessage = "expecting term or field, found '" + nextLexeme.type + "'";
            throw new lunr.QueryParseError(errorMessage, nextLexeme.start, nextLexeme.end);
        }
    };
    lunr.QueryParser.parseField = function(parser) {
        var lexeme = parser.consumeLexeme();
        if (lexeme == undefined) {
            return;
        }
        if (parser.query.allFields.indexOf(lexeme.str) == -1) {
            var possibleFields = parser.query.allFields.map(function(f) {
                return "'" + f + "'";
            }).join(", "), errorMessage = "unrecognised field '" + lexeme.str + "', possible fields: " + possibleFields;
            throw new lunr.QueryParseError(errorMessage, lexeme.start, lexeme.end);
        }
        parser.currentClause.fields = [ lexeme.str ];
        var nextLexeme = parser.peekLexeme();
        if (nextLexeme == undefined) {
            var errorMessage = "expecting term, found nothing";
            throw new lunr.QueryParseError(errorMessage, lexeme.start, lexeme.end);
        }
        switch (nextLexeme.type) {
          case lunr.QueryLexer.TERM:
            return lunr.QueryParser.parseTerm;

          default:
            var errorMessage = "expecting term, found '" + nextLexeme.type + "'";
            throw new lunr.QueryParseError(errorMessage, nextLexeme.start, nextLexeme.end);
        }
    };
    lunr.QueryParser.parseTerm = function(parser) {
        var lexeme = parser.consumeLexeme();
        if (lexeme == undefined) {
            return;
        }
        parser.currentClause.term = lexeme.str.toLowerCase();
        if (lexeme.str.indexOf("*") != -1) {
            parser.currentClause.usePipeline = false;
        }
        var nextLexeme = parser.peekLexeme();
        if (nextLexeme == undefined) {
            parser.nextClause();
            return;
        }
        switch (nextLexeme.type) {
          case lunr.QueryLexer.TERM:
            parser.nextClause();
            return lunr.QueryParser.parseTerm;

          case lunr.QueryLexer.FIELD:
            parser.nextClause();
            return lunr.QueryParser.parseField;

          case lunr.QueryLexer.EDIT_DISTANCE:
            return lunr.QueryParser.parseEditDistance;

          case lunr.QueryLexer.BOOST:
            return lunr.QueryParser.parseBoost;

          case lunr.QueryLexer.PRESENCE:
            parser.nextClause();
            return lunr.QueryParser.parsePresence;

          default:
            var errorMessage = "Unexpected lexeme type '" + nextLexeme.type + "'";
            throw new lunr.QueryParseError(errorMessage, nextLexeme.start, nextLexeme.end);
        }
    };
    lunr.QueryParser.parseEditDistance = function(parser) {
        var lexeme = parser.consumeLexeme();
        if (lexeme == undefined) {
            return;
        }
        var editDistance = parseInt(lexeme.str, 10);
        if (isNaN(editDistance)) {
            var errorMessage = "edit distance must be numeric";
            throw new lunr.QueryParseError(errorMessage, lexeme.start, lexeme.end);
        }
        parser.currentClause.editDistance = editDistance;
        var nextLexeme = parser.peekLexeme();
        if (nextLexeme == undefined) {
            parser.nextClause();
            return;
        }
        switch (nextLexeme.type) {
          case lunr.QueryLexer.TERM:
            parser.nextClause();
            return lunr.QueryParser.parseTerm;

          case lunr.QueryLexer.FIELD:
            parser.nextClause();
            return lunr.QueryParser.parseField;

          case lunr.QueryLexer.EDIT_DISTANCE:
            return lunr.QueryParser.parseEditDistance;

          case lunr.QueryLexer.BOOST:
            return lunr.QueryParser.parseBoost;

          case lunr.QueryLexer.PRESENCE:
            parser.nextClause();
            return lunr.QueryParser.parsePresence;

          default:
            var errorMessage = "Unexpected lexeme type '" + nextLexeme.type + "'";
            throw new lunr.QueryParseError(errorMessage, nextLexeme.start, nextLexeme.end);
        }
    };
    lunr.QueryParser.parseBoost = function(parser) {
        var lexeme = parser.consumeLexeme();
        if (lexeme == undefined) {
            return;
        }
        var boost = parseInt(lexeme.str, 10);
        if (isNaN(boost)) {
            var errorMessage = "boost must be numeric";
            throw new lunr.QueryParseError(errorMessage, lexeme.start, lexeme.end);
        }
        parser.currentClause.boost = boost;
        var nextLexeme = parser.peekLexeme();
        if (nextLexeme == undefined) {
            parser.nextClause();
            return;
        }
        switch (nextLexeme.type) {
          case lunr.QueryLexer.TERM:
            parser.nextClause();
            return lunr.QueryParser.parseTerm;

          case lunr.QueryLexer.FIELD:
            parser.nextClause();
            return lunr.QueryParser.parseField;

          case lunr.QueryLexer.EDIT_DISTANCE:
            return lunr.QueryParser.parseEditDistance;

          case lunr.QueryLexer.BOOST:
            return lunr.QueryParser.parseBoost;

          case lunr.QueryLexer.PRESENCE:
            parser.nextClause();
            return lunr.QueryParser.parsePresence;

          default:
            var errorMessage = "Unexpected lexeme type '" + nextLexeme.type + "'";
            throw new lunr.QueryParseError(errorMessage, nextLexeme.start, nextLexeme.end);
        }
    };
    (function(root, factory) {
        if (typeof define === "function" && define.amd) {
            define(factory);
        } else if (typeof exports === "object") {
            module.exports = factory();
        } else {
            root.lunr = factory();
        }
    })(this, function() {
        return lunr;
    });
})();

(function(root, factory) {
    if (typeof define === "function" && define.amd) {
        define([ "jquery" ], function(a0) {
            return factory(a0);
        });
    } else if (typeof module === "object" && module.exports) {
        module.exports = factory(require("jquery"));
    } else {
        factory(root["jQuery"]);
    }
})(this, function($) {
    (function() {
        "use strict";
        var defaults = {
            mode: "lg-slide",
            cssEasing: "ease",
            easing: "linear",
            speed: 600,
            height: "100%",
            width: "100%",
            addClass: "",
            startClass: "lg-start-zoom",
            backdropDuration: 150,
            hideBarsDelay: 6e3,
            useLeft: false,
            closable: true,
            loop: true,
            escKey: true,
            keyPress: true,
            controls: true,
            slideEndAnimatoin: true,
            hideControlOnEnd: false,
            mousewheel: true,
            getCaptionFromTitleOrAlt: true,
            appendSubHtmlTo: ".lg-sub-html",
            subHtmlSelectorRelative: false,
            preload: 1,
            showAfterLoad: true,
            selector: "",
            selectWithin: "",
            nextHtml: "",
            prevHtml: "",
            index: false,
            iframeMaxWidth: "100%",
            download: true,
            counter: true,
            appendCounterTo: ".lg-toolbar",
            swipeThreshold: 50,
            enableSwipe: true,
            enableDrag: true,
            dynamic: false,
            dynamicEl: [],
            galleryId: 1
        };
        function Plugin(element, options) {
            this.el = element;
            this.$el = $(element);
            this.s = $.extend({}, defaults, options);
            if (this.s.dynamic && this.s.dynamicEl !== "undefined" && this.s.dynamicEl.constructor === Array && !this.s.dynamicEl.length) {
                throw "When using dynamic mode, you must also define dynamicEl as an Array.";
            }
            this.modules = {};
            this.lGalleryOn = false;
            this.lgBusy = false;
            this.hideBartimeout = false;
            this.isTouch = "ontouchstart" in document.documentElement;
            if (this.s.slideEndAnimatoin) {
                this.s.hideControlOnEnd = false;
            }
            if (this.s.dynamic) {
                this.$items = this.s.dynamicEl;
            } else {
                if (this.s.selector === "this") {
                    this.$items = this.$el;
                } else if (this.s.selector !== "") {
                    if (this.s.selectWithin) {
                        this.$items = $(this.s.selectWithin).find(this.s.selector);
                    } else {
                        this.$items = this.$el.find($(this.s.selector));
                    }
                } else {
                    this.$items = this.$el.children();
                }
            }
            this.$slide = "";
            this.$outer = "";
            this.init();
            return this;
        }
        Plugin.prototype.init = function() {
            var _this = this;
            if (_this.s.preload > _this.$items.length) {
                _this.s.preload = _this.$items.length;
            }
            var _hash = window.location.hash;
            if (_hash.indexOf("lg=" + this.s.galleryId) > 0) {
                _this.index = parseInt(_hash.split("&slide=")[1], 10);
                $("body").addClass("lg-from-hash");
                if (!$("body").hasClass("lg-on")) {
                    setTimeout(function() {
                        _this.build(_this.index);
                    });
                    $("body").addClass("lg-on");
                }
            }
            if (_this.s.dynamic) {
                _this.$el.trigger("onBeforeOpen.lg");
                _this.index = _this.s.index || 0;
                if (!$("body").hasClass("lg-on")) {
                    setTimeout(function() {
                        _this.build(_this.index);
                        $("body").addClass("lg-on");
                    });
                }
            } else {
                _this.$items.on("click.lgcustom", function(event) {
                    try {
                        event.preventDefault();
                        event.preventDefault();
                    } catch (er) {
                        event.returnValue = false;
                    }
                    _this.$el.trigger("onBeforeOpen.lg");
                    _this.index = _this.s.index || _this.$items.index(this);
                    if (!$("body").hasClass("lg-on")) {
                        _this.build(_this.index);
                        $("body").addClass("lg-on");
                    }
                });
            }
        };
        Plugin.prototype.build = function(index) {
            var _this = this;
            _this.structure();
            $.each($.fn.lightGallery.modules, function(key) {
                _this.modules[key] = new $.fn.lightGallery.modules[key](_this.el);
            });
            _this.slide(index, false, false, false);
            if (_this.s.keyPress) {
                _this.keyPress();
            }
            if (_this.$items.length > 1) {
                _this.arrow();
                setTimeout(function() {
                    _this.enableDrag();
                    _this.enableSwipe();
                }, 50);
                if (_this.s.mousewheel) {
                    _this.mousewheel();
                }
            } else {
                _this.$slide.on("click.lg", function() {
                    _this.$el.trigger("onSlideClick.lg");
                });
            }
            _this.counter();
            _this.closeGallery();
            _this.$el.trigger("onAfterOpen.lg");
            _this.$outer.on("mousemove.lg click.lg touchstart.lg", function() {
                _this.$outer.removeClass("lg-hide-items");
                clearTimeout(_this.hideBartimeout);
                _this.hideBartimeout = setTimeout(function() {
                    _this.$outer.addClass("lg-hide-items");
                }, _this.s.hideBarsDelay);
            });
            _this.$outer.trigger("mousemove.lg");
        };
        Plugin.prototype.structure = function() {
            var list = "";
            var controls = "";
            var i = 0;
            var subHtmlCont = "";
            var template;
            var _this = this;
            $("body").append('<div class="lg-backdrop"></div>');
            $(".lg-backdrop").css("transition-duration", this.s.backdropDuration + "ms");
            for (i = 0; i < this.$items.length; i++) {
                list += '<div class="lg-item"></div>';
            }
            if (this.s.controls && this.$items.length > 1) {
                controls = '<div class="lg-actions">' + '<button class="lg-prev lg-icon">' + this.s.prevHtml + "</button>" + '<button class="lg-next lg-icon">' + this.s.nextHtml + "</button>" + "</div>";
            }
            if (this.s.appendSubHtmlTo === ".lg-sub-html") {
                subHtmlCont = '<div class="lg-sub-html"></div>';
            }
            template = '<div class="lg-outer ' + this.s.addClass + " " + this.s.startClass + '">' + '<div class="lg" style="width:' + this.s.width + "; height:" + this.s.height + '">' + '<div class="lg-inner">' + list + "</div>" + '<div class="lg-toolbar lg-group">' + '<span class="lg-close lg-icon"></span>' + "</div>" + controls + subHtmlCont + "</div>" + "</div>";
            $("body").append(template);
            this.$outer = $(".lg-outer");
            this.$slide = this.$outer.find(".lg-item");
            if (this.s.useLeft) {
                this.$outer.addClass("lg-use-left");
                this.s.mode = "lg-slide";
            } else {
                this.$outer.addClass("lg-use-css3");
            }
            _this.setTop();
            $(window).on("resize.lg orientationchange.lg", function() {
                setTimeout(function() {
                    _this.setTop();
                }, 100);
            });
            this.$slide.eq(this.index).addClass("lg-current");
            if (this.doCss()) {
                this.$outer.addClass("lg-css3");
            } else {
                this.$outer.addClass("lg-css");
                this.s.speed = 0;
            }
            this.$outer.addClass(this.s.mode);
            if (this.s.enableDrag && this.$items.length > 1) {
                this.$outer.addClass("lg-grab");
            }
            if (this.s.showAfterLoad) {
                this.$outer.addClass("lg-show-after-load");
            }
            if (this.doCss()) {
                var $inner = this.$outer.find(".lg-inner");
                $inner.css("transition-timing-function", this.s.cssEasing);
                $inner.css("transition-duration", this.s.speed + "ms");
            }
            setTimeout(function() {
                $(".lg-backdrop").addClass("in");
            });
            setTimeout(function() {
                _this.$outer.addClass("lg-visible");
            }, this.s.backdropDuration);
            if (this.s.download) {
                this.$outer.find(".lg-toolbar").append('<a id="lg-download" target="_blank" download class="lg-download lg-icon"></a>');
            }
            this.prevScrollTop = $(window).scrollTop();
        };
        Plugin.prototype.setTop = function() {
            if (this.s.height !== "100%") {
                var wH = $(window).height();
                var top = (wH - parseInt(this.s.height, 10)) / 2;
                var $lGallery = this.$outer.find(".lg");
                if (wH >= parseInt(this.s.height, 10)) {
                    $lGallery.css("top", top + "px");
                } else {
                    $lGallery.css("top", "0px");
                }
            }
        };
        Plugin.prototype.doCss = function() {
            var support = function() {
                var transition = [ "transition", "MozTransition", "WebkitTransition", "OTransition", "msTransition", "KhtmlTransition" ];
                var root = document.documentElement;
                var i = 0;
                for (i = 0; i < transition.length; i++) {
                    if (transition[i] in root.style) {
                        return true;
                    }
                }
            };
            if (support()) {
                return true;
            }
            return false;
        };
        Plugin.prototype.isVideo = function(src, index) {
            var html;
            if (this.s.dynamic) {
                html = this.s.dynamicEl[index].html;
            } else {
                html = this.$items.eq(index).attr("data-html");
            }
            if (!src) {
                if (html) {
                    return {
                        html5: true
                    };
                } else {
                    console.error("lightGallery :- data-src is not pvovided on slide item " + (index + 1) + ". Please make sure the selector property is properly configured. More info - http://sachinchoolur.github.io/lightGallery/demos/html-markup.html");
                    return false;
                }
            }
            var youtube = src.match(/\/\/(?:www\.)?youtu(?:\.be|be\.com|be-nocookie\.com)\/(?:watch\?v=|embed\/)?([a-z0-9\-\_\%]+)/i);
            var vimeo = src.match(/\/\/(?:www\.)?vimeo.com\/([0-9a-z\-_]+)/i);
            var dailymotion = src.match(/\/\/(?:www\.)?dai.ly\/([0-9a-z\-_]+)/i);
            var vk = src.match(/\/\/(?:www\.)?(?:vk\.com|vkontakte\.ru)\/(?:video_ext\.php\?)(.*)/i);
            if (youtube) {
                return {
                    youtube: youtube
                };
            } else if (vimeo) {
                return {
                    vimeo: vimeo
                };
            } else if (dailymotion) {
                return {
                    dailymotion: dailymotion
                };
            } else if (vk) {
                return {
                    vk: vk
                };
            }
        };
        Plugin.prototype.counter = function() {
            if (this.s.counter) {
                $(this.s.appendCounterTo).append('<div id="lg-counter"><span id="lg-counter-current">' + (parseInt(this.index, 10) + 1) + '</span> / <span id="lg-counter-all">' + this.$items.length + "</span></div>");
            }
        };
        Plugin.prototype.addHtml = function(index) {
            var subHtml = null;
            var subHtmlUrl;
            var $currentEle;
            if (this.s.dynamic) {
                if (this.s.dynamicEl[index].subHtmlUrl) {
                    subHtmlUrl = this.s.dynamicEl[index].subHtmlUrl;
                } else {
                    subHtml = this.s.dynamicEl[index].subHtml;
                }
            } else {
                $currentEle = this.$items.eq(index);
                if ($currentEle.attr("data-sub-html-url")) {
                    subHtmlUrl = $currentEle.attr("data-sub-html-url");
                } else {
                    subHtml = $currentEle.attr("data-sub-html");
                    if (this.s.getCaptionFromTitleOrAlt && !subHtml) {
                        subHtml = $currentEle.attr("title") || $currentEle.find("img").first().attr("alt");
                    }
                }
            }
            if (!subHtmlUrl) {
                if (typeof subHtml !== "undefined" && subHtml !== null) {
                    var fL = subHtml.substring(0, 1);
                    if (fL === "." || fL === "#") {
                        if (this.s.subHtmlSelectorRelative && !this.s.dynamic) {
                            subHtml = $currentEle.find(subHtml).html();
                        } else {
                            subHtml = $(subHtml).html();
                        }
                    }
                } else {
                    subHtml = "";
                }
            }
            if (this.s.appendSubHtmlTo === ".lg-sub-html") {
                if (subHtmlUrl) {
                    this.$outer.find(this.s.appendSubHtmlTo).load(subHtmlUrl);
                } else {
                    this.$outer.find(this.s.appendSubHtmlTo).html(subHtml);
                }
            } else {
                if (subHtmlUrl) {
                    this.$slide.eq(index).load(subHtmlUrl);
                } else {
                    this.$slide.eq(index).append(subHtml);
                }
            }
            if (typeof subHtml !== "undefined" && subHtml !== null) {
                if (subHtml === "") {
                    this.$outer.find(this.s.appendSubHtmlTo).addClass("lg-empty-html");
                } else {
                    this.$outer.find(this.s.appendSubHtmlTo).removeClass("lg-empty-html");
                }
            }
            this.$el.trigger("onAfterAppendSubHtml.lg", [ index ]);
        };
        Plugin.prototype.preload = function(index) {
            var i = 1;
            var j = 1;
            for (i = 1; i <= this.s.preload; i++) {
                if (i >= this.$items.length - index) {
                    break;
                }
                this.loadContent(index + i, false, 0);
            }
            for (j = 1; j <= this.s.preload; j++) {
                if (index - j < 0) {
                    break;
                }
                this.loadContent(index - j, false, 0);
            }
        };
        Plugin.prototype.loadContent = function(index, rec, delay) {
            var _this = this;
            var _hasPoster = false;
            var _$img;
            var _src;
            var _poster;
            var _srcset;
            var _sizes;
            var _html;
            var getResponsiveSrc = function(srcItms) {
                var rsWidth = [];
                var rsSrc = [];
                for (var i = 0; i < srcItms.length; i++) {
                    var __src = srcItms[i].split(" ");
                    if (__src[0] === "") {
                        __src.splice(0, 1);
                    }
                    rsSrc.push(__src[0]);
                    rsWidth.push(__src[1]);
                }
                var wWidth = $(window).width();
                for (var j = 0; j < rsWidth.length; j++) {
                    if (parseInt(rsWidth[j], 10) > wWidth) {
                        _src = rsSrc[j];
                        break;
                    }
                }
            };
            if (_this.s.dynamic) {
                if (_this.s.dynamicEl[index].poster) {
                    _hasPoster = true;
                    _poster = _this.s.dynamicEl[index].poster;
                }
                _html = _this.s.dynamicEl[index].html;
                _src = _this.s.dynamicEl[index].src;
                if (_this.s.dynamicEl[index].responsive) {
                    var srcDyItms = _this.s.dynamicEl[index].responsive.split(",");
                    getResponsiveSrc(srcDyItms);
                }
                _srcset = _this.s.dynamicEl[index].srcset;
                _sizes = _this.s.dynamicEl[index].sizes;
            } else {
                if (_this.$items.eq(index).attr("data-poster")) {
                    _hasPoster = true;
                    _poster = _this.$items.eq(index).attr("data-poster");
                }
                _html = _this.$items.eq(index).attr("data-html");
                _src = _this.$items.eq(index).attr("href") || _this.$items.eq(index).attr("data-src");
                if (_this.$items.eq(index).attr("data-responsive")) {
                    var srcItms = _this.$items.eq(index).attr("data-responsive").split(",");
                    getResponsiveSrc(srcItms);
                }
                _srcset = _this.$items.eq(index).attr("data-srcset");
                _sizes = _this.$items.eq(index).attr("data-sizes");
            }
            var iframe = false;
            if (_this.s.dynamic) {
                if (_this.s.dynamicEl[index].iframe) {
                    iframe = true;
                }
            } else {
                if (_this.$items.eq(index).attr("data-iframe") === "true") {
                    iframe = true;
                }
            }
            var _isVideo = _this.isVideo(_src, index);
            if (!_this.$slide.eq(index).hasClass("lg-loaded")) {
                if (iframe) {
                    _this.$slide.eq(index).prepend('<div class="lg-video-cont lg-has-iframe" style="max-width:' + _this.s.iframeMaxWidth + '"><div class="lg-video"><iframe class="lg-object" frameborder="0" src="' + _src + '"  allowfullscreen="true"></iframe></div></div>');
                } else if (_hasPoster) {
                    var videoClass = "";
                    if (_isVideo && _isVideo.youtube) {
                        videoClass = "lg-has-youtube";
                    } else if (_isVideo && _isVideo.vimeo) {
                        videoClass = "lg-has-vimeo";
                    } else {
                        videoClass = "lg-has-html5";
                    }
                    _this.$slide.eq(index).prepend('<div class="lg-video-cont ' + videoClass + ' "><div class="lg-video"><span class="lg-video-play"></span><img class="lg-object lg-has-poster" src="' + _poster + '" /></div></div>');
                } else if (_isVideo) {
                    _this.$slide.eq(index).prepend('<div class="lg-video-cont "><div class="lg-video"></div></div>');
                    _this.$el.trigger("hasVideo.lg", [ index, _src, _html ]);
                } else {
                    _this.$slide.eq(index).prepend('<div class="lg-img-wrap"><img class="lg-object lg-image" src="' + _src + '" /></div>');
                }
                _this.$el.trigger("onAferAppendSlide.lg", [ index ]);
                _$img = _this.$slide.eq(index).find(".lg-object");
                if (_sizes) {
                    _$img.attr("sizes", _sizes);
                }
                if (_srcset) {
                    _$img.attr("srcset", _srcset);
                    try {
                        picturefill({
                            elements: [ _$img[0] ]
                        });
                    } catch (e) {
                        console.warn("lightGallery :- If you want srcset to be supported for older browser please include picturefil version 2 javascript library in your document.");
                    }
                }
                if (this.s.appendSubHtmlTo !== ".lg-sub-html") {
                    _this.addHtml(index);
                }
                _this.$slide.eq(index).addClass("lg-loaded");
            }
            _this.$slide.eq(index).find(".lg-object").on("load.lg error.lg", function() {
                var _speed = 0;
                if (delay && !$("body").hasClass("lg-from-hash")) {
                    _speed = delay;
                }
                setTimeout(function() {
                    _this.$slide.eq(index).addClass("lg-complete");
                    _this.$el.trigger("onSlideItemLoad.lg", [ index, delay || 0 ]);
                }, _speed);
            });
            if (_isVideo && _isVideo.html5 && !_hasPoster) {
                _this.$slide.eq(index).addClass("lg-complete");
            }
            if (rec === true) {
                if (!_this.$slide.eq(index).hasClass("lg-complete")) {
                    _this.$slide.eq(index).find(".lg-object").on("load.lg error.lg", function() {
                        _this.preload(index);
                    });
                } else {
                    _this.preload(index);
                }
            }
        };
        Plugin.prototype.slide = function(index, fromTouch, fromThumb, direction) {
            var _prevIndex = this.$outer.find(".lg-current").index();
            var _this = this;
            if (_this.lGalleryOn && _prevIndex === index) {
                return;
            }
            var _length = this.$slide.length;
            var _time = _this.lGalleryOn ? this.s.speed : 0;
            if (!_this.lgBusy) {
                if (this.s.download) {
                    var _src;
                    if (_this.s.dynamic) {
                        _src = _this.s.dynamicEl[index].downloadUrl !== false && (_this.s.dynamicEl[index].downloadUrl || _this.s.dynamicEl[index].src);
                    } else {
                        _src = _this.$items.eq(index).attr("data-download-url") !== "false" && (_this.$items.eq(index).attr("data-download-url") || _this.$items.eq(index).attr("href") || _this.$items.eq(index).attr("data-src"));
                    }
                    if (_src) {
                        $("#lg-download").attr("href", _src);
                        _this.$outer.removeClass("lg-hide-download");
                    } else {
                        _this.$outer.addClass("lg-hide-download");
                    }
                }
                this.$el.trigger("onBeforeSlide.lg", [ _prevIndex, index, fromTouch, fromThumb ]);
                _this.lgBusy = true;
                clearTimeout(_this.hideBartimeout);
                if (this.s.appendSubHtmlTo === ".lg-sub-html") {
                    setTimeout(function() {
                        _this.addHtml(index);
                    }, _time);
                }
                this.arrowDisable(index);
                if (!direction) {
                    if (index < _prevIndex) {
                        direction = "prev";
                    } else if (index > _prevIndex) {
                        direction = "next";
                    }
                }
                if (!fromTouch) {
                    _this.$outer.addClass("lg-no-trans");
                    this.$slide.removeClass("lg-prev-slide lg-next-slide");
                    if (direction === "prev") {
                        this.$slide.eq(index).addClass("lg-prev-slide");
                        this.$slide.eq(_prevIndex).addClass("lg-next-slide");
                    } else {
                        this.$slide.eq(index).addClass("lg-next-slide");
                        this.$slide.eq(_prevIndex).addClass("lg-prev-slide");
                    }
                    setTimeout(function() {
                        _this.$slide.removeClass("lg-current");
                        _this.$slide.eq(index).addClass("lg-current");
                        _this.$outer.removeClass("lg-no-trans");
                    }, 50);
                } else {
                    this.$slide.removeClass("lg-prev-slide lg-current lg-next-slide");
                    var touchPrev;
                    var touchNext;
                    if (_length > 2) {
                        touchPrev = index - 1;
                        touchNext = index + 1;
                        if (index === 0 && _prevIndex === _length - 1) {
                            touchNext = 0;
                            touchPrev = _length - 1;
                        } else if (index === _length - 1 && _prevIndex === 0) {
                            touchNext = 0;
                            touchPrev = _length - 1;
                        }
                    } else {
                        touchPrev = 0;
                        touchNext = 1;
                    }
                    if (direction === "prev") {
                        _this.$slide.eq(touchNext).addClass("lg-next-slide");
                    } else {
                        _this.$slide.eq(touchPrev).addClass("lg-prev-slide");
                    }
                    _this.$slide.eq(index).addClass("lg-current");
                }
                if (_this.lGalleryOn) {
                    setTimeout(function() {
                        _this.loadContent(index, true, 0);
                    }, this.s.speed + 50);
                    setTimeout(function() {
                        _this.lgBusy = false;
                        _this.$el.trigger("onAfterSlide.lg", [ _prevIndex, index, fromTouch, fromThumb ]);
                    }, this.s.speed);
                } else {
                    _this.loadContent(index, true, _this.s.backdropDuration);
                    _this.lgBusy = false;
                    _this.$el.trigger("onAfterSlide.lg", [ _prevIndex, index, fromTouch, fromThumb ]);
                }
                _this.lGalleryOn = true;
                if (this.s.counter) {
                    $("#lg-counter-current").text(index + 1);
                }
            }
            _this.index = index;
        };
        Plugin.prototype.goToNextSlide = function(fromTouch) {
            var _this = this;
            var _loop = _this.s.loop;
            if (fromTouch && _this.$slide.length < 3) {
                _loop = false;
            }
            if (!_this.lgBusy) {
                if (_this.index + 1 < _this.$slide.length) {
                    _this.index++;
                    _this.$el.trigger("onBeforeNextSlide.lg", [ _this.index ]);
                    _this.slide(_this.index, fromTouch, false, "next");
                } else {
                    if (_loop) {
                        _this.index = 0;
                        _this.$el.trigger("onBeforeNextSlide.lg", [ _this.index ]);
                        _this.slide(_this.index, fromTouch, false, "next");
                    } else if (_this.s.slideEndAnimatoin && !fromTouch) {
                        _this.$outer.addClass("lg-right-end");
                        setTimeout(function() {
                            _this.$outer.removeClass("lg-right-end");
                        }, 400);
                    }
                }
            }
        };
        Plugin.prototype.goToPrevSlide = function(fromTouch) {
            var _this = this;
            var _loop = _this.s.loop;
            if (fromTouch && _this.$slide.length < 3) {
                _loop = false;
            }
            if (!_this.lgBusy) {
                if (_this.index > 0) {
                    _this.index--;
                    _this.$el.trigger("onBeforePrevSlide.lg", [ _this.index, fromTouch ]);
                    _this.slide(_this.index, fromTouch, false, "prev");
                } else {
                    if (_loop) {
                        _this.index = _this.$items.length - 1;
                        _this.$el.trigger("onBeforePrevSlide.lg", [ _this.index, fromTouch ]);
                        _this.slide(_this.index, fromTouch, false, "prev");
                    } else if (_this.s.slideEndAnimatoin && !fromTouch) {
                        _this.$outer.addClass("lg-left-end");
                        setTimeout(function() {
                            _this.$outer.removeClass("lg-left-end");
                        }, 400);
                    }
                }
            }
        };
        Plugin.prototype.keyPress = function() {
            var _this = this;
            if (this.$items.length > 1) {
                $(window).on("keyup.lg", function(e) {
                    if (_this.$items.length > 1) {
                        if (e.keyCode === 37) {
                            e.preventDefault();
                            _this.goToPrevSlide();
                        }
                        if (e.keyCode === 39) {
                            e.preventDefault();
                            _this.goToNextSlide();
                        }
                    }
                });
            }
            $(window).on("keydown.lg", function(e) {
                if (_this.s.escKey === true && e.keyCode === 27) {
                    e.preventDefault();
                    if (!_this.$outer.hasClass("lg-thumb-open")) {
                        _this.destroy();
                    } else {
                        _this.$outer.removeClass("lg-thumb-open");
                    }
                }
            });
        };
        Plugin.prototype.arrow = function() {
            var _this = this;
            this.$outer.find(".lg-prev").on("click.lg", function() {
                _this.goToPrevSlide();
            });
            this.$outer.find(".lg-next").on("click.lg", function() {
                _this.goToNextSlide();
            });
        };
        Plugin.prototype.arrowDisable = function(index) {
            if (!this.s.loop && this.s.hideControlOnEnd) {
                if (index + 1 < this.$slide.length) {
                    this.$outer.find(".lg-next").removeAttr("disabled").removeClass("disabled");
                } else {
                    this.$outer.find(".lg-next").attr("disabled", "disabled").addClass("disabled");
                }
                if (index > 0) {
                    this.$outer.find(".lg-prev").removeAttr("disabled").removeClass("disabled");
                } else {
                    this.$outer.find(".lg-prev").attr("disabled", "disabled").addClass("disabled");
                }
            }
        };
        Plugin.prototype.setTranslate = function($el, xValue, yValue) {
            if (this.s.useLeft) {
                $el.css("left", xValue);
            } else {
                $el.css({
                    transform: "translate3d(" + xValue + "px, " + yValue + "px, 0px)"
                });
            }
        };
        Plugin.prototype.touchMove = function(startCoords, endCoords) {
            var distance = endCoords - startCoords;
            if (Math.abs(distance) > 15) {
                this.$outer.addClass("lg-dragging");
                this.setTranslate(this.$slide.eq(this.index), distance, 0);
                this.setTranslate($(".lg-prev-slide"), -this.$slide.eq(this.index).width() + distance, 0);
                this.setTranslate($(".lg-next-slide"), this.$slide.eq(this.index).width() + distance, 0);
            }
        };
        Plugin.prototype.touchEnd = function(distance) {
            var _this = this;
            if (_this.s.mode !== "lg-slide") {
                _this.$outer.addClass("lg-slide");
            }
            this.$slide.not(".lg-current, .lg-prev-slide, .lg-next-slide").css("opacity", "0");
            setTimeout(function() {
                _this.$outer.removeClass("lg-dragging");
                if (distance < 0 && Math.abs(distance) > _this.s.swipeThreshold) {
                    _this.goToNextSlide(true);
                } else if (distance > 0 && Math.abs(distance) > _this.s.swipeThreshold) {
                    _this.goToPrevSlide(true);
                } else if (Math.abs(distance) < 5) {
                    _this.$el.trigger("onSlideClick.lg");
                }
                _this.$slide.removeAttr("style");
            });
            setTimeout(function() {
                if (!_this.$outer.hasClass("lg-dragging") && _this.s.mode !== "lg-slide") {
                    _this.$outer.removeClass("lg-slide");
                }
            }, _this.s.speed + 100);
        };
        Plugin.prototype.enableSwipe = function() {
            var _this = this;
            var startCoords = 0;
            var endCoords = 0;
            var isMoved = false;
            if (_this.s.enableSwipe && _this.doCss()) {
                _this.$slide.on("touchstart.lg", function(e) {
                    if (!_this.$outer.hasClass("lg-zoomed") && !_this.lgBusy) {
                        e.preventDefault();
                        _this.manageSwipeClass();
                        startCoords = e.originalEvent.targetTouches[0].pageX;
                    }
                });
                _this.$slide.on("touchmove.lg", function(e) {
                    if (!_this.$outer.hasClass("lg-zoomed")) {
                        e.preventDefault();
                        endCoords = e.originalEvent.targetTouches[0].pageX;
                        _this.touchMove(startCoords, endCoords);
                        isMoved = true;
                    }
                });
                _this.$slide.on("touchend.lg", function() {
                    if (!_this.$outer.hasClass("lg-zoomed")) {
                        if (isMoved) {
                            isMoved = false;
                            _this.touchEnd(endCoords - startCoords);
                        } else {
                            _this.$el.trigger("onSlideClick.lg");
                        }
                    }
                });
            }
        };
        Plugin.prototype.enableDrag = function() {
            var _this = this;
            var startCoords = 0;
            var endCoords = 0;
            var isDraging = false;
            var isMoved = false;
            if (_this.s.enableDrag && _this.doCss()) {
                _this.$slide.on("mousedown.lg", function(e) {
                    if (!_this.$outer.hasClass("lg-zoomed") && !_this.lgBusy && !$(e.target).text().trim()) {
                        e.preventDefault();
                        _this.manageSwipeClass();
                        startCoords = e.pageX;
                        isDraging = true;
                        _this.$outer.scrollLeft += 1;
                        _this.$outer.scrollLeft -= 1;
                        _this.$outer.removeClass("lg-grab").addClass("lg-grabbing");
                        _this.$el.trigger("onDragstart.lg");
                    }
                });
                $(window).on("mousemove.lg", function(e) {
                    if (isDraging) {
                        isMoved = true;
                        endCoords = e.pageX;
                        _this.touchMove(startCoords, endCoords);
                        _this.$el.trigger("onDragmove.lg");
                    }
                });
                $(window).on("mouseup.lg", function(e) {
                    if (isMoved) {
                        isMoved = false;
                        _this.touchEnd(endCoords - startCoords);
                        _this.$el.trigger("onDragend.lg");
                    } else if ($(e.target).hasClass("lg-object") || $(e.target).hasClass("lg-video-play")) {
                        _this.$el.trigger("onSlideClick.lg");
                    }
                    if (isDraging) {
                        isDraging = false;
                        _this.$outer.removeClass("lg-grabbing").addClass("lg-grab");
                    }
                });
            }
        };
        Plugin.prototype.manageSwipeClass = function() {
            var _touchNext = this.index + 1;
            var _touchPrev = this.index - 1;
            if (this.s.loop && this.$slide.length > 2) {
                if (this.index === 0) {
                    _touchPrev = this.$slide.length - 1;
                } else if (this.index === this.$slide.length - 1) {
                    _touchNext = 0;
                }
            }
            this.$slide.removeClass("lg-next-slide lg-prev-slide");
            if (_touchPrev > -1) {
                this.$slide.eq(_touchPrev).addClass("lg-prev-slide");
            }
            this.$slide.eq(_touchNext).addClass("lg-next-slide");
        };
        Plugin.prototype.mousewheel = function() {
            var _this = this;
            _this.$outer.on("mousewheel.lg", function(e) {
                if (!e.deltaY) {
                    return;
                }
                if (e.deltaY > 0) {
                    _this.goToPrevSlide();
                } else {
                    _this.goToNextSlide();
                }
                e.preventDefault();
            });
        };
        Plugin.prototype.closeGallery = function() {
            var _this = this;
            var mousedown = false;
            this.$outer.find(".lg-close").on("click.lg", function() {
                _this.destroy();
            });
            if (_this.s.closable) {
                _this.$outer.on("mousedown.lg", function(e) {
                    if ($(e.target).is(".lg-outer") || $(e.target).is(".lg-item ") || $(e.target).is(".lg-img-wrap")) {
                        mousedown = true;
                    } else {
                        mousedown = false;
                    }
                });
                _this.$outer.on("mousemove.lg", function() {
                    mousedown = false;
                });
                _this.$outer.on("mouseup.lg", function(e) {
                    if ($(e.target).is(".lg-outer") || $(e.target).is(".lg-item ") || $(e.target).is(".lg-img-wrap") && mousedown) {
                        if (!_this.$outer.hasClass("lg-dragging")) {
                            _this.destroy();
                        }
                    }
                });
            }
        };
        Plugin.prototype.destroy = function(d) {
            var _this = this;
            if (!d) {
                _this.$el.trigger("onBeforeClose.lg");
                $(window).scrollTop(_this.prevScrollTop);
            }
            if (d) {
                if (!_this.s.dynamic) {
                    this.$items.off("click.lg click.lgcustom");
                }
                $.removeData(_this.el, "lightGallery");
            }
            this.$el.off(".lg.tm");
            $.each($.fn.lightGallery.modules, function(key) {
                if (_this.modules[key]) {
                    _this.modules[key].destroy();
                }
            });
            this.lGalleryOn = false;
            clearTimeout(_this.hideBartimeout);
            this.hideBartimeout = false;
            $(window).off(".lg");
            $("body").removeClass("lg-on lg-from-hash");
            if (_this.$outer) {
                _this.$outer.removeClass("lg-visible");
            }
            $(".lg-backdrop").removeClass("in");
            setTimeout(function() {
                if (_this.$outer) {
                    _this.$outer.remove();
                }
                $(".lg-backdrop").remove();
                if (!d) {
                    _this.$el.trigger("onCloseAfter.lg");
                }
            }, _this.s.backdropDuration + 50);
        };
        $.fn.lightGallery = function(options) {
            return this.each(function() {
                if (!$.data(this, "lightGallery")) {
                    $.data(this, "lightGallery", new Plugin(this, options));
                } else {
                    try {
                        $(this).data("lightGallery").init();
                    } catch (err) {
                        console.error("lightGallery has not initiated properly");
                    }
                }
            });
        };
        $.fn.lightGallery.modules = {};
    })();
});

(function(root, factory) {
    if (typeof define === "function" && define.amd) {
        define([ "jquery" ], function(a0) {
            return factory(a0);
        });
    } else if (typeof exports === "object") {
        module.exports = factory(require("jquery"));
    } else {
        factory(jQuery);
    }
})(this, function($) {
    (function() {
        "use strict";
        var defaults = {
            autoplay: false,
            pause: 5e3,
            progressBar: true,
            fourceAutoplay: false,
            autoplayControls: true,
            appendAutoplayControlsTo: ".lg-toolbar"
        };
        var Autoplay = function(element) {
            this.core = $(element).data("lightGallery");
            this.$el = $(element);
            if (this.core.$items.length < 2) {
                return false;
            }
            this.core.s = $.extend({}, defaults, this.core.s);
            this.interval = false;
            this.fromAuto = true;
            this.canceledOnTouch = false;
            this.fourceAutoplayTemp = this.core.s.fourceAutoplay;
            if (!this.core.doCss()) {
                this.core.s.progressBar = false;
            }
            this.init();
            return this;
        };
        Autoplay.prototype.init = function() {
            var _this = this;
            if (_this.core.s.autoplayControls) {
                _this.controls();
            }
            if (_this.core.s.progressBar) {
                _this.core.$outer.find(".lg").append('<div class="lg-progress-bar"><div class="lg-progress"></div></div>');
            }
            _this.progress();
            if (_this.core.s.autoplay) {
                _this.$el.one("onSlideItemLoad.lg.tm", function() {
                    _this.startlAuto();
                });
            }
            _this.$el.on("onDragstart.lg.tm touchstart.lg.tm", function() {
                if (_this.interval) {
                    _this.cancelAuto();
                    _this.canceledOnTouch = true;
                }
            });
            _this.$el.on("onDragend.lg.tm touchend.lg.tm onSlideClick.lg.tm", function() {
                if (!_this.interval && _this.canceledOnTouch) {
                    _this.startlAuto();
                    _this.canceledOnTouch = false;
                }
            });
        };
        Autoplay.prototype.progress = function() {
            var _this = this;
            var _$progressBar;
            var _$progress;
            _this.$el.on("onBeforeSlide.lg.tm", function() {
                if (_this.core.s.progressBar && _this.fromAuto) {
                    _$progressBar = _this.core.$outer.find(".lg-progress-bar");
                    _$progress = _this.core.$outer.find(".lg-progress");
                    if (_this.interval) {
                        _$progress.removeAttr("style");
                        _$progressBar.removeClass("lg-start");
                        setTimeout(function() {
                            _$progress.css("transition", "width " + (_this.core.s.speed + _this.core.s.pause) + "ms ease 0s");
                            _$progressBar.addClass("lg-start");
                        }, 20);
                    }
                }
                if (!_this.fromAuto && !_this.core.s.fourceAutoplay) {
                    _this.cancelAuto();
                }
                _this.fromAuto = false;
            });
        };
        Autoplay.prototype.controls = function() {
            var _this = this;
            var _html = '<span class="lg-autoplay-button lg-icon"></span>';
            $(this.core.s.appendAutoplayControlsTo).append(_html);
            _this.core.$outer.find(".lg-autoplay-button").on("click.lg", function() {
                if ($(_this.core.$outer).hasClass("lg-show-autoplay")) {
                    _this.cancelAuto();
                    _this.core.s.fourceAutoplay = false;
                } else {
                    if (!_this.interval) {
                        _this.startlAuto();
                        _this.core.s.fourceAutoplay = _this.fourceAutoplayTemp;
                    }
                }
            });
        };
        Autoplay.prototype.startlAuto = function() {
            var _this = this;
            _this.core.$outer.find(".lg-progress").css("transition", "width " + (_this.core.s.speed + _this.core.s.pause) + "ms ease 0s");
            _this.core.$outer.addClass("lg-show-autoplay");
            _this.core.$outer.find(".lg-progress-bar").addClass("lg-start");
            _this.interval = setInterval(function() {
                if (_this.core.index + 1 < _this.core.$items.length) {
                    _this.core.index++;
                } else {
                    _this.core.index = 0;
                }
                _this.fromAuto = true;
                _this.core.slide(_this.core.index, false, false, "next");
            }, _this.core.s.speed + _this.core.s.pause);
        };
        Autoplay.prototype.cancelAuto = function() {
            clearInterval(this.interval);
            this.interval = false;
            this.core.$outer.find(".lg-progress").removeAttr("style");
            this.core.$outer.removeClass("lg-show-autoplay");
            this.core.$outer.find(".lg-progress-bar").removeClass("lg-start");
        };
        Autoplay.prototype.destroy = function() {
            this.cancelAuto();
            this.core.$outer.find(".lg-progress-bar").remove();
        };
        $.fn.lightGallery.modules.autoplay = Autoplay;
    })();
});

(function(root, factory) {
    if (typeof define === "function" && define.amd) {
        define([ "jquery" ], function(a0) {
            return factory(a0);
        });
    } else if (typeof module === "object" && module.exports) {
        module.exports = factory(require("jquery"));
    } else {
        factory(root["jQuery"]);
    }
})(this, function($) {
    (function() {
        "use strict";
        var defaults = {
            fullScreen: true
        };
        function isFullScreen() {
            return document.fullscreenElement || document.mozFullScreenElement || document.webkitFullscreenElement || document.msFullscreenElement;
        }
        var Fullscreen = function(element) {
            this.core = $(element).data("lightGallery");
            this.$el = $(element);
            this.core.s = $.extend({}, defaults, this.core.s);
            this.init();
            return this;
        };
        Fullscreen.prototype.init = function() {
            var fullScreen = "";
            if (this.core.s.fullScreen) {
                if (!document.fullscreenEnabled && !document.webkitFullscreenEnabled && !document.mozFullScreenEnabled && !document.msFullscreenEnabled) {
                    return;
                } else {
                    fullScreen = '<span class="lg-fullscreen lg-icon"></span>';
                    this.core.$outer.find(".lg-toolbar").append(fullScreen);
                    this.fullScreen();
                }
            }
        };
        Fullscreen.prototype.requestFullscreen = function() {
            var el = document.documentElement;
            if (el.requestFullscreen) {
                el.requestFullscreen();
            } else if (el.msRequestFullscreen) {
                el.msRequestFullscreen();
            } else if (el.mozRequestFullScreen) {
                el.mozRequestFullScreen();
            } else if (el.webkitRequestFullscreen) {
                el.webkitRequestFullscreen();
            }
        };
        Fullscreen.prototype.exitFullscreen = function() {
            if (document.exitFullscreen) {
                document.exitFullscreen();
            } else if (document.msExitFullscreen) {
                document.msExitFullscreen();
            } else if (document.mozCancelFullScreen) {
                document.mozCancelFullScreen();
            } else if (document.webkitExitFullscreen) {
                document.webkitExitFullscreen();
            }
        };
        Fullscreen.prototype.fullScreen = function() {
            var _this = this;
            $(document).on("fullscreenchange.lg webkitfullscreenchange.lg mozfullscreenchange.lg MSFullscreenChange.lg", function() {
                _this.core.$outer.toggleClass("lg-fullscreen-on");
            });
            this.core.$outer.find(".lg-fullscreen").on("click.lg", function() {
                if (isFullScreen()) {
                    _this.exitFullscreen();
                } else {
                    _this.requestFullscreen();
                }
            });
        };
        Fullscreen.prototype.destroy = function() {
            if (isFullScreen()) {
                this.exitFullscreen();
            }
            $(document).off("fullscreenchange.lg webkitfullscreenchange.lg mozfullscreenchange.lg MSFullscreenChange.lg");
        };
        $.fn.lightGallery.modules.fullscreen = Fullscreen;
    })();
});

(function(root, factory) {
    if (typeof define === "function" && define.amd) {
        define([ "jquery" ], function(a0) {
            return factory(a0);
        });
    } else if (typeof exports === "object") {
        module.exports = factory(require("jquery"));
    } else {
        factory(jQuery);
    }
})(this, function($) {
    (function() {
        "use strict";
        var defaults = {
            pager: false
        };
        var Pager = function(element) {
            this.core = $(element).data("lightGallery");
            this.$el = $(element);
            this.core.s = $.extend({}, defaults, this.core.s);
            if (this.core.s.pager && this.core.$items.length > 1) {
                this.init();
            }
            return this;
        };
        Pager.prototype.init = function() {
            var _this = this;
            var pagerList = "";
            var $pagerCont;
            var $pagerOuter;
            var timeout;
            _this.core.$outer.find(".lg").append('<div class="lg-pager-outer"></div>');
            if (_this.core.s.dynamic) {
                for (var i = 0; i < _this.core.s.dynamicEl.length; i++) {
                    pagerList += '<span class="lg-pager-cont"> <span class="lg-pager"></span><div class="lg-pager-thumb-cont"><span class="lg-caret"></span> <img src="' + _this.core.s.dynamicEl[i].thumb + '" /></div></span>';
                }
            } else {
                _this.core.$items.each(function() {
                    if (!_this.core.s.exThumbImage) {
                        pagerList += '<span class="lg-pager-cont"> <span class="lg-pager"></span><div class="lg-pager-thumb-cont"><span class="lg-caret"></span> <img src="' + $(this).find("img").attr("src") + '" /></div></span>';
                    } else {
                        pagerList += '<span class="lg-pager-cont"> <span class="lg-pager"></span><div class="lg-pager-thumb-cont"><span class="lg-caret"></span> <img src="' + $(this).attr(_this.core.s.exThumbImage) + '" /></div></span>';
                    }
                });
            }
            $pagerOuter = _this.core.$outer.find(".lg-pager-outer");
            $pagerOuter.html(pagerList);
            $pagerCont = _this.core.$outer.find(".lg-pager-cont");
            $pagerCont.on("click.lg touchend.lg", function() {
                var _$this = $(this);
                _this.core.index = _$this.index();
                _this.core.slide(_this.core.index, false, true, false);
            });
            $pagerOuter.on("mouseover.lg", function() {
                clearTimeout(timeout);
                $pagerOuter.addClass("lg-pager-hover");
            });
            $pagerOuter.on("mouseout.lg", function() {
                timeout = setTimeout(function() {
                    $pagerOuter.removeClass("lg-pager-hover");
                });
            });
            _this.core.$el.on("onBeforeSlide.lg.tm", function(e, prevIndex, index) {
                $pagerCont.removeClass("lg-pager-active");
                $pagerCont.eq(index).addClass("lg-pager-active");
            });
        };
        Pager.prototype.destroy = function() {};
        $.fn.lightGallery.modules.pager = Pager;
    })();
});

(function(root, factory) {
    if (typeof define === "function" && define.amd) {
        define([ "jquery" ], function(a0) {
            return factory(a0);
        });
    } else if (typeof exports === "object") {
        module.exports = factory(require("jquery"));
    } else {
        factory(jQuery);
    }
})(this, function($) {
    (function() {
        "use strict";
        var defaults = {
            thumbnail: true,
            animateThumb: true,
            currentPagerPosition: "middle",
            thumbWidth: 100,
            thumbHeight: "80px",
            thumbContHeight: 100,
            thumbMargin: 5,
            exThumbImage: false,
            showThumbByDefault: true,
            toogleThumb: true,
            pullCaptionUp: true,
            enableThumbDrag: true,
            enableThumbSwipe: true,
            swipeThreshold: 50,
            loadYoutubeThumbnail: true,
            youtubeThumbSize: 1,
            loadVimeoThumbnail: true,
            vimeoThumbSize: "thumbnail_small",
            loadDailymotionThumbnail: true
        };
        var Thumbnail = function(element) {
            this.core = $(element).data("lightGallery");
            this.core.s = $.extend({}, defaults, this.core.s);
            this.$el = $(element);
            this.$thumbOuter = null;
            this.thumbOuterWidth = 0;
            this.thumbTotalWidth = this.core.$items.length * (this.core.s.thumbWidth + this.core.s.thumbMargin);
            this.thumbIndex = this.core.index;
            if (this.core.s.animateThumb) {
                this.core.s.thumbHeight = "100%";
            }
            this.left = 0;
            this.init();
            return this;
        };
        Thumbnail.prototype.init = function() {
            var _this = this;
            if (this.core.s.thumbnail && this.core.$items.length > 1) {
                if (this.core.s.showThumbByDefault) {
                    setTimeout(function() {
                        _this.core.$outer.addClass("lg-thumb-open");
                    }, 700);
                }
                if (this.core.s.pullCaptionUp) {
                    this.core.$outer.addClass("lg-pull-caption-up");
                }
                this.build();
                if (this.core.s.animateThumb && this.core.doCss()) {
                    if (this.core.s.enableThumbDrag) {
                        this.enableThumbDrag();
                    }
                    if (this.core.s.enableThumbSwipe) {
                        this.enableThumbSwipe();
                    }
                    this.thumbClickable = false;
                } else {
                    this.thumbClickable = true;
                }
                this.toogle();
                this.thumbkeyPress();
            }
        };
        Thumbnail.prototype.build = function() {
            var _this = this;
            var thumbList = "";
            var vimeoErrorThumbSize = "";
            var $thumb;
            var html = '<div class="lg-thumb-outer">' + '<div class="lg-thumb lg-group">' + "</div>" + "</div>";
            switch (this.core.s.vimeoThumbSize) {
              case "thumbnail_large":
                vimeoErrorThumbSize = "640";
                break;

              case "thumbnail_medium":
                vimeoErrorThumbSize = "200x150";
                break;

              case "thumbnail_small":
                vimeoErrorThumbSize = "100x75";
            }
            _this.core.$outer.addClass("lg-has-thumb");
            _this.core.$outer.find(".lg").append(html);
            _this.$thumbOuter = _this.core.$outer.find(".lg-thumb-outer");
            _this.thumbOuterWidth = _this.$thumbOuter.width();
            if (_this.core.s.animateThumb) {
                _this.core.$outer.find(".lg-thumb").css({
                    width: _this.thumbTotalWidth + "px",
                    position: "relative"
                });
            }
            if (this.core.s.animateThumb) {
                _this.$thumbOuter.css("height", _this.core.s.thumbContHeight + "px");
            }
            function getThumb(src, thumb, index) {
                var isVideo = _this.core.isVideo(src, index) || {};
                var thumbImg;
                var vimeoId = "";
                if (isVideo.youtube || isVideo.vimeo || isVideo.dailymotion) {
                    if (isVideo.youtube) {
                        if (_this.core.s.loadYoutubeThumbnail) {
                            thumbImg = "//img.youtube.com/vi/" + isVideo.youtube[1] + "/" + _this.core.s.youtubeThumbSize + ".jpg";
                        } else {
                            thumbImg = thumb;
                        }
                    } else if (isVideo.vimeo) {
                        if (_this.core.s.loadVimeoThumbnail) {
                            thumbImg = "//i.vimeocdn.com/video/error_" + vimeoErrorThumbSize + ".jpg";
                            vimeoId = isVideo.vimeo[1];
                        } else {
                            thumbImg = thumb;
                        }
                    } else if (isVideo.dailymotion) {
                        if (_this.core.s.loadDailymotionThumbnail) {
                            thumbImg = "//www.dailymotion.com/thumbnail/video/" + isVideo.dailymotion[1];
                        } else {
                            thumbImg = thumb;
                        }
                    }
                } else {
                    thumbImg = thumb;
                }
                thumbList += '<div data-vimeo-id="' + vimeoId + '" class="lg-thumb-item" style="width:' + _this.core.s.thumbWidth + "px; height: " + _this.core.s.thumbHeight + "; margin-right: " + _this.core.s.thumbMargin + 'px"><img src="' + thumbImg + '" /></div>';
                vimeoId = "";
            }
            if (_this.core.s.dynamic) {
                for (var i = 0; i < _this.core.s.dynamicEl.length; i++) {
                    getThumb(_this.core.s.dynamicEl[i].src, _this.core.s.dynamicEl[i].thumb, i);
                }
            } else {
                _this.core.$items.each(function(i) {
                    if (!_this.core.s.exThumbImage) {
                        getThumb($(this).attr("href") || $(this).attr("data-src"), $(this).find("img").attr("src"), i);
                    } else {
                        getThumb($(this).attr("href") || $(this).attr("data-src"), $(this).attr(_this.core.s.exThumbImage), i);
                    }
                });
            }
            _this.core.$outer.find(".lg-thumb").html(thumbList);
            $thumb = _this.core.$outer.find(".lg-thumb-item");
            $thumb.each(function() {
                var $this = $(this);
                var vimeoVideoId = $this.attr("data-vimeo-id");
                if (vimeoVideoId) {
                    $.getJSON("//www.vimeo.com/public/v2/video/" + vimeoVideoId + ".json?callback=?", {
                        format: "json"
                    }, function(data) {
                        $this.find("img").attr("src", data[0][_this.core.s.vimeoThumbSize]);
                    });
                }
            });
            $thumb.eq(_this.core.index).addClass("active");
            _this.core.$el.on("onBeforeSlide.lg.tm", function() {
                $thumb.removeClass("active");
                $thumb.eq(_this.core.index).addClass("active");
            });
            $thumb.on("click.lg touchend.lg", function() {
                var _$this = $(this);
                setTimeout(function() {
                    if (_this.thumbClickable && !_this.core.lgBusy || !_this.core.doCss()) {
                        _this.core.index = _$this.index();
                        _this.core.slide(_this.core.index, false, true, false);
                    }
                }, 50);
            });
            _this.core.$el.on("onBeforeSlide.lg.tm", function() {
                _this.animateThumb(_this.core.index);
            });
            $(window).on("resize.lg.thumb orientationchange.lg.thumb", function() {
                setTimeout(function() {
                    _this.animateThumb(_this.core.index);
                    _this.thumbOuterWidth = _this.$thumbOuter.width();
                }, 200);
            });
        };
        Thumbnail.prototype.setTranslate = function(value) {
            this.core.$outer.find(".lg-thumb").css({
                transform: "translate3d(-" + value + "px, 0px, 0px)"
            });
        };
        Thumbnail.prototype.animateThumb = function(index) {
            var $thumb = this.core.$outer.find(".lg-thumb");
            if (this.core.s.animateThumb) {
                var position;
                switch (this.core.s.currentPagerPosition) {
                  case "left":
                    position = 0;
                    break;

                  case "middle":
                    position = this.thumbOuterWidth / 2 - this.core.s.thumbWidth / 2;
                    break;

                  case "right":
                    position = this.thumbOuterWidth - this.core.s.thumbWidth;
                }
                this.left = (this.core.s.thumbWidth + this.core.s.thumbMargin) * index - 1 - position;
                if (this.left > this.thumbTotalWidth - this.thumbOuterWidth) {
                    this.left = this.thumbTotalWidth - this.thumbOuterWidth;
                }
                if (this.left < 0) {
                    this.left = 0;
                }
                if (this.core.lGalleryOn) {
                    if (!$thumb.hasClass("on")) {
                        this.core.$outer.find(".lg-thumb").css("transition-duration", this.core.s.speed + "ms");
                    }
                    if (!this.core.doCss()) {
                        $thumb.animate({
                            left: -this.left + "px"
                        }, this.core.s.speed);
                    }
                } else {
                    if (!this.core.doCss()) {
                        $thumb.css("left", -this.left + "px");
                    }
                }
                this.setTranslate(this.left);
            }
        };
        Thumbnail.prototype.enableThumbDrag = function() {
            var _this = this;
            var startCoords = 0;
            var endCoords = 0;
            var isDraging = false;
            var isMoved = false;
            var tempLeft = 0;
            _this.$thumbOuter.addClass("lg-grab");
            _this.core.$outer.find(".lg-thumb").on("mousedown.lg.thumb", function(e) {
                if (_this.thumbTotalWidth > _this.thumbOuterWidth) {
                    e.preventDefault();
                    startCoords = e.pageX;
                    isDraging = true;
                    _this.core.$outer.scrollLeft += 1;
                    _this.core.$outer.scrollLeft -= 1;
                    _this.thumbClickable = false;
                    _this.$thumbOuter.removeClass("lg-grab").addClass("lg-grabbing");
                }
            });
            $(window).on("mousemove.lg.thumb", function(e) {
                if (isDraging) {
                    tempLeft = _this.left;
                    isMoved = true;
                    endCoords = e.pageX;
                    _this.$thumbOuter.addClass("lg-dragging");
                    tempLeft = tempLeft - (endCoords - startCoords);
                    if (tempLeft > _this.thumbTotalWidth - _this.thumbOuterWidth) {
                        tempLeft = _this.thumbTotalWidth - _this.thumbOuterWidth;
                    }
                    if (tempLeft < 0) {
                        tempLeft = 0;
                    }
                    _this.setTranslate(tempLeft);
                }
            });
            $(window).on("mouseup.lg.thumb", function() {
                if (isMoved) {
                    isMoved = false;
                    _this.$thumbOuter.removeClass("lg-dragging");
                    _this.left = tempLeft;
                    if (Math.abs(endCoords - startCoords) < _this.core.s.swipeThreshold) {
                        _this.thumbClickable = true;
                    }
                } else {
                    _this.thumbClickable = true;
                }
                if (isDraging) {
                    isDraging = false;
                    _this.$thumbOuter.removeClass("lg-grabbing").addClass("lg-grab");
                }
            });
        };
        Thumbnail.prototype.enableThumbSwipe = function() {
            var _this = this;
            var startCoords = 0;
            var endCoords = 0;
            var isMoved = false;
            var tempLeft = 0;
            _this.core.$outer.find(".lg-thumb").on("touchstart.lg", function(e) {
                if (_this.thumbTotalWidth > _this.thumbOuterWidth) {
                    e.preventDefault();
                    startCoords = e.originalEvent.targetTouches[0].pageX;
                    _this.thumbClickable = false;
                }
            });
            _this.core.$outer.find(".lg-thumb").on("touchmove.lg", function(e) {
                if (_this.thumbTotalWidth > _this.thumbOuterWidth) {
                    e.preventDefault();
                    endCoords = e.originalEvent.targetTouches[0].pageX;
                    isMoved = true;
                    _this.$thumbOuter.addClass("lg-dragging");
                    tempLeft = _this.left;
                    tempLeft = tempLeft - (endCoords - startCoords);
                    if (tempLeft > _this.thumbTotalWidth - _this.thumbOuterWidth) {
                        tempLeft = _this.thumbTotalWidth - _this.thumbOuterWidth;
                    }
                    if (tempLeft < 0) {
                        tempLeft = 0;
                    }
                    _this.setTranslate(tempLeft);
                }
            });
            _this.core.$outer.find(".lg-thumb").on("touchend.lg", function() {
                if (_this.thumbTotalWidth > _this.thumbOuterWidth) {
                    if (isMoved) {
                        isMoved = false;
                        _this.$thumbOuter.removeClass("lg-dragging");
                        if (Math.abs(endCoords - startCoords) < _this.core.s.swipeThreshold) {
                            _this.thumbClickable = true;
                        }
                        _this.left = tempLeft;
                    } else {
                        _this.thumbClickable = true;
                    }
                } else {
                    _this.thumbClickable = true;
                }
            });
        };
        Thumbnail.prototype.toogle = function() {
            var _this = this;
            if (_this.core.s.toogleThumb) {
                _this.core.$outer.addClass("lg-can-toggle");
                _this.$thumbOuter.append('<span class="lg-toogle-thumb lg-icon"></span>');
                _this.core.$outer.find(".lg-toogle-thumb").on("click.lg", function() {
                    _this.core.$outer.toggleClass("lg-thumb-open");
                });
            }
        };
        Thumbnail.prototype.thumbkeyPress = function() {
            var _this = this;
            $(window).on("keydown.lg.thumb", function(e) {
                if (e.keyCode === 38) {
                    e.preventDefault();
                    _this.core.$outer.addClass("lg-thumb-open");
                } else if (e.keyCode === 40) {
                    e.preventDefault();
                    _this.core.$outer.removeClass("lg-thumb-open");
                }
            });
        };
        Thumbnail.prototype.destroy = function() {
            if (this.core.s.thumbnail && this.core.$items.length > 1) {
                $(window).off("resize.lg.thumb orientationchange.lg.thumb keydown.lg.thumb");
                this.$thumbOuter.remove();
                this.core.$outer.removeClass("lg-has-thumb");
            }
        };
        $.fn.lightGallery.modules.Thumbnail = Thumbnail;
    })();
});

(function(root, factory) {
    if (typeof define === "function" && define.amd) {
        define([ "jquery" ], function(a0) {
            return factory(a0);
        });
    } else if (typeof module === "object" && module.exports) {
        module.exports = factory(require("jquery"));
    } else {
        factory(root["jQuery"]);
    }
})(this, function($) {
    (function() {
        "use strict";
        var defaults = {
            videoMaxWidth: "855px",
            autoplayFirstVideo: true,
            youtubePlayerParams: false,
            vimeoPlayerParams: false,
            dailymotionPlayerParams: false,
            vkPlayerParams: false,
            videojs: false,
            videojsOptions: {}
        };
        var Video = function(element) {
            this.core = $(element).data("lightGallery");
            this.$el = $(element);
            this.core.s = $.extend({}, defaults, this.core.s);
            this.videoLoaded = false;
            this.init();
            return this;
        };
        Video.prototype.init = function() {
            var _this = this;
            _this.core.$el.on("hasVideo.lg.tm", onHasVideo.bind(this));
            _this.core.$el.on("onAferAppendSlide.lg.tm", onAferAppendSlide.bind(this));
            if (_this.core.doCss() && _this.core.$items.length > 1 && (_this.core.s.enableSwipe || _this.core.s.enableDrag)) {
                _this.core.$el.on("onSlideClick.lg.tm", function() {
                    var $el = _this.core.$slide.eq(_this.core.index);
                    _this.loadVideoOnclick($el);
                });
            } else {
                _this.core.$slide.on("click.lg", function() {
                    _this.loadVideoOnclick($(this));
                });
            }
            _this.core.$el.on("onBeforeSlide.lg.tm", onBeforeSlide.bind(this));
            _this.core.$el.on("onAfterSlide.lg.tm", function(event, prevIndex) {
                _this.core.$slide.eq(prevIndex).removeClass("lg-video-playing");
            });
            if (_this.core.s.autoplayFirstVideo) {
                _this.core.$el.on("onAferAppendSlide.lg.tm", function(e, index) {
                    if (!_this.core.lGalleryOn) {
                        var $el = _this.core.$slide.eq(index);
                        setTimeout(function() {
                            _this.loadVideoOnclick($el);
                        }, 100);
                    }
                });
            }
        };
        Video.prototype.loadVideo = function(src, addClass, noPoster, index, html) {
            var video = "";
            var autoplay = 1;
            var a = "";
            var isVideo = this.core.isVideo(src, index) || {};
            if (noPoster) {
                if (this.videoLoaded) {
                    autoplay = 0;
                } else {
                    autoplay = this.core.s.autoplayFirstVideo ? 1 : 0;
                }
            }
            if (isVideo.youtube) {
                a = "?wmode=opaque&autoplay=" + autoplay + "&enablejsapi=1";
                if (this.core.s.youtubePlayerParams) {
                    a = a + "&" + $.param(this.core.s.youtubePlayerParams);
                }
                video = '<iframe class="lg-video-object lg-youtube ' + addClass + '" width="560" height="315" src="//www.youtube.com/embed/' + isVideo.youtube[1] + a + '" frameborder="0" allowfullscreen></iframe>';
            } else if (isVideo.vimeo) {
                a = "?autoplay=" + autoplay + "&public=1";
                if (this.core.s.vimeoPlayerParams) {
                    a = a + "&" + $.param(this.core.s.vimeoPlayerParams);
                }
                video = '<iframe class="lg-video-object lg-vimeo ' + addClass + '" width="560" height="315"  src="//player.vimeo.com/video/' + isVideo.vimeo[1] + a + '" frameborder="0" webkitAllowFullScreen mozallowfullscreen allowFullScreen></iframe>';
            } else if (isVideo.dailymotion) {
                a = "?wmode=opaque&autoplay=" + autoplay + "&public=postMessage";
                if (this.core.s.dailymotionPlayerParams) {
                    a = a + "&" + $.param(this.core.s.dailymotionPlayerParams);
                }
                video = '<iframe class="lg-video-object lg-dailymotion ' + addClass + '" width="560" height="315" src="//www.dailymotion.com/embed/video/' + isVideo.dailymotion[1] + a + '" frameborder="0" allowfullscreen></iframe>';
            } else if (isVideo.html5) {
                var fL = html.substring(0, 1);
                if (fL === "." || fL === "#") {
                    html = $(html).html();
                }
                video = html;
            } else if (isVideo.vk) {
                a = "&autoplay=" + autoplay;
                if (this.core.s.vkPlayerParams) {
                    a = a + "&" + $.param(this.core.s.vkPlayerParams);
                }
                video = '<iframe class="lg-video-object lg-vk ' + addClass + '" width="560" height="315" src="//vk.com/video_ext.php?' + isVideo.vk[1] + a + '" frameborder="0" allowfullscreen></iframe>';
            }
            return video;
        };
        Video.prototype.loadVideoOnclick = function($el) {
            var _this = this;
            if ($el.find(".lg-object").hasClass("lg-has-poster") && $el.find(".lg-object").is(":visible")) {
                if (!$el.hasClass("lg-has-video")) {
                    $el.addClass("lg-video-playing lg-has-video");
                    var _src;
                    var _html;
                    var _loadVideo = function(_src, _html) {
                        $el.find(".lg-video").append(_this.loadVideo(_src, "", false, _this.core.index, _html));
                        if (_html) {
                            if (_this.core.s.videojs) {
                                try {
                                    videojs(_this.core.$slide.eq(_this.core.index).find(".lg-html5").get(0), _this.core.s.videojsOptions, function() {
                                        this.play();
                                    });
                                } catch (e) {
                                    console.error("Make sure you have included videojs");
                                }
                            } else {
                                _this.core.$slide.eq(_this.core.index).find(".lg-html5").get(0).play();
                            }
                        }
                    };
                    if (_this.core.s.dynamic) {
                        _src = _this.core.s.dynamicEl[_this.core.index].src;
                        _html = _this.core.s.dynamicEl[_this.core.index].html;
                        _loadVideo(_src, _html);
                    } else {
                        _src = _this.core.$items.eq(_this.core.index).attr("href") || _this.core.$items.eq(_this.core.index).attr("data-src");
                        _html = _this.core.$items.eq(_this.core.index).attr("data-html");
                        _loadVideo(_src, _html);
                    }
                    var $tempImg = $el.find(".lg-object");
                    $el.find(".lg-video").append($tempImg);
                    if (!$el.find(".lg-video-object").hasClass("lg-html5")) {
                        $el.removeClass("lg-complete");
                        $el.find(".lg-video-object").on("load.lg error.lg", function() {
                            $el.addClass("lg-complete");
                        });
                    }
                } else {
                    var youtubePlayer = $el.find(".lg-youtube").get(0);
                    var vimeoPlayer = $el.find(".lg-vimeo").get(0);
                    var dailymotionPlayer = $el.find(".lg-dailymotion").get(0);
                    var html5Player = $el.find(".lg-html5").get(0);
                    if (youtubePlayer) {
                        youtubePlayer.contentWindow.postMessage('{"event":"command","func":"playVideo","args":""}', "*");
                    } else if (vimeoPlayer) {
                        try {
                            $f(vimeoPlayer).api("play");
                        } catch (e) {
                            console.error("Make sure you have included froogaloop2 js");
                        }
                    } else if (dailymotionPlayer) {
                        dailymotionPlayer.contentWindow.postMessage("play", "*");
                    } else if (html5Player) {
                        if (_this.core.s.videojs) {
                            try {
                                videojs(html5Player).play();
                            } catch (e) {
                                console.error("Make sure you have included videojs");
                            }
                        } else {
                            html5Player.play();
                        }
                    }
                    $el.addClass("lg-video-playing");
                }
            }
        };
        Video.prototype.destroy = function() {
            this.videoLoaded = false;
        };
        function onHasVideo(event, index, src, html) {
            var _this = this;
            _this.core.$slide.eq(index).find(".lg-video").append(_this.loadVideo(src, "lg-object", true, index, html));
            if (html) {
                if (_this.core.s.videojs) {
                    try {
                        videojs(_this.core.$slide.eq(index).find(".lg-html5").get(0), _this.core.s.videojsOptions, function() {
                            if (!_this.videoLoaded && _this.core.s.autoplayFirstVideo) {
                                this.play();
                            }
                        });
                    } catch (e) {
                        console.error("Make sure you have included videojs");
                    }
                } else {
                    if (!_this.videoLoaded && _this.core.s.autoplayFirstVideo) {
                        _this.core.$slide.eq(index).find(".lg-html5").get(0).play();
                    }
                }
            }
        }
        function onAferAppendSlide(event, index) {
            var $videoCont = this.core.$slide.eq(index).find(".lg-video-cont");
            if (!$videoCont.hasClass("lg-has-iframe")) {
                $videoCont.css("max-width", this.core.s.videoMaxWidth);
                this.videoLoaded = true;
            }
        }
        function onBeforeSlide(event, prevIndex, index) {
            var _this = this;
            var $videoSlide = _this.core.$slide.eq(prevIndex);
            var youtubePlayer = $videoSlide.find(".lg-youtube").get(0);
            var vimeoPlayer = $videoSlide.find(".lg-vimeo").get(0);
            var dailymotionPlayer = $videoSlide.find(".lg-dailymotion").get(0);
            var vkPlayer = $videoSlide.find(".lg-vk").get(0);
            var html5Player = $videoSlide.find(".lg-html5").get(0);
            if (youtubePlayer) {
                youtubePlayer.contentWindow.postMessage('{"event":"command","func":"pauseVideo","args":""}', "*");
            } else if (vimeoPlayer) {
                try {
                    $f(vimeoPlayer).api("pause");
                } catch (e) {
                    console.error("Make sure you have included froogaloop2 js");
                }
            } else if (dailymotionPlayer) {
                dailymotionPlayer.contentWindow.postMessage("pause", "*");
            } else if (html5Player) {
                if (_this.core.s.videojs) {
                    try {
                        videojs(html5Player).pause();
                    } catch (e) {
                        console.error("Make sure you have included videojs");
                    }
                } else {
                    html5Player.pause();
                }
            }
            if (vkPlayer) {
                $(vkPlayer).attr("src", $(vkPlayer).attr("src").replace("&autoplay", "&noplay"));
            }
            var _src;
            if (_this.core.s.dynamic) {
                _src = _this.core.s.dynamicEl[index].src;
            } else {
                _src = _this.core.$items.eq(index).attr("href") || _this.core.$items.eq(index).attr("data-src");
            }
            var _isVideo = _this.core.isVideo(_src, index) || {};
            if (_isVideo.youtube || _isVideo.vimeo || _isVideo.dailymotion || _isVideo.vk) {
                _this.core.$outer.addClass("lg-hide-download");
            }
        }
        $.fn.lightGallery.modules.video = Video;
    })();
});

(function(root, factory) {
    if (typeof define === "function" && define.amd) {
        define([ "jquery" ], function(a0) {
            return factory(a0);
        });
    } else if (typeof exports === "object") {
        module.exports = factory(require("jquery"));
    } else {
        factory(jQuery);
    }
})(this, function($) {
    (function() {
        "use strict";
        var getUseLeft = function() {
            var useLeft = false;
            var isChrome = navigator.userAgent.match(/Chrom(e|ium)\/([0-9]+)\./);
            if (isChrome && parseInt(isChrome[2], 10) < 54) {
                useLeft = true;
            }
            return useLeft;
        };
        var defaults = {
            scale: 1,
            zoom: true,
            actualSize: true,
            enableZoomAfter: 300,
            useLeftForZoom: getUseLeft()
        };
        var Zoom = function(element) {
            this.core = $(element).data("lightGallery");
            this.core.s = $.extend({}, defaults, this.core.s);
            if (this.core.s.zoom && this.core.doCss()) {
                this.init();
                this.zoomabletimeout = false;
                this.pageX = $(window).width() / 2;
                this.pageY = $(window).height() / 2 + $(window).scrollTop();
            }
            return this;
        };
        Zoom.prototype.init = function() {
            var _this = this;
            var zoomIcons = '<span id="lg-zoom-in" class="lg-icon"></span><span id="lg-zoom-out" class="lg-icon"></span>';
            if (_this.core.s.actualSize) {
                zoomIcons += '<span id="lg-actual-size" class="lg-icon"></span>';
            }
            if (_this.core.s.useLeftForZoom) {
                _this.core.$outer.addClass("lg-use-left-for-zoom");
            } else {
                _this.core.$outer.addClass("lg-use-transition-for-zoom");
            }
            this.core.$outer.find(".lg-toolbar").append(zoomIcons);
            _this.core.$el.on("onSlideItemLoad.lg.tm.zoom", function(event, index, delay) {
                var _speed = _this.core.s.enableZoomAfter + delay;
                if ($("body").hasClass("lg-from-hash") && delay) {
                    _speed = 0;
                } else {
                    $("body").removeClass("lg-from-hash");
                }
                _this.zoomabletimeout = setTimeout(function() {
                    _this.core.$slide.eq(index).addClass("lg-zoomable");
                }, _speed + 30);
            });
            var scale = 1;
            var zoom = function(scaleVal) {
                var $image = _this.core.$outer.find(".lg-current .lg-image");
                var _x;
                var _y;
                var offsetX = ($(window).width() - $image.prop("offsetWidth")) / 2;
                var offsetY = ($(window).height() - $image.prop("offsetHeight")) / 2 + $(window).scrollTop();
                _x = _this.pageX - offsetX;
                _y = _this.pageY - offsetY;
                var x = (scaleVal - 1) * _x;
                var y = (scaleVal - 1) * _y;
                $image.css("transform", "scale3d(" + scaleVal + ", " + scaleVal + ", 1)").attr("data-scale", scaleVal);
                if (_this.core.s.useLeftForZoom) {
                    $image.parent().css({
                        left: -x + "px",
                        top: -y + "px"
                    }).attr("data-x", x).attr("data-y", y);
                } else {
                    $image.parent().css("transform", "translate3d(-" + x + "px, -" + y + "px, 0)").attr("data-x", x).attr("data-y", y);
                }
            };
            var callScale = function() {
                if (scale > 1) {
                    _this.core.$outer.addClass("lg-zoomed");
                } else {
                    _this.resetZoom();
                }
                if (scale < 1) {
                    scale = 1;
                }
                zoom(scale);
            };
            var actualSize = function(event, $image, index, fromIcon) {
                var w = $image.prop("offsetWidth");
                var nw;
                if (_this.core.s.dynamic) {
                    nw = _this.core.s.dynamicEl[index].width || $image[0].naturalWidth || w;
                } else {
                    nw = _this.core.$items.eq(index).attr("data-width") || $image[0].naturalWidth || w;
                }
                var _scale;
                if (_this.core.$outer.hasClass("lg-zoomed")) {
                    scale = 1;
                } else {
                    if (nw > w) {
                        _scale = nw / w;
                        scale = _scale || 2;
                    }
                }
                if (fromIcon) {
                    _this.pageX = $(window).width() / 2;
                    _this.pageY = $(window).height() / 2 + $(window).scrollTop();
                } else {
                    _this.pageX = event.pageX || event.originalEvent.targetTouches[0].pageX;
                    _this.pageY = event.pageY || event.originalEvent.targetTouches[0].pageY;
                }
                callScale();
                setTimeout(function() {
                    _this.core.$outer.removeClass("lg-grabbing").addClass("lg-grab");
                }, 10);
            };
            var tapped = false;
            _this.core.$el.on("onAferAppendSlide.lg.tm.zoom", function(event, index) {
                var $image = _this.core.$slide.eq(index).find(".lg-image");
                $image.on("dblclick", function(event) {
                    actualSize(event, $image, index);
                });
                $image.on("touchstart", function(event) {
                    if (!tapped) {
                        tapped = setTimeout(function() {
                            tapped = null;
                        }, 300);
                    } else {
                        clearTimeout(tapped);
                        tapped = null;
                        actualSize(event, $image, index);
                    }
                    event.preventDefault();
                });
            });
            $(window).on("resize.lg.zoom scroll.lg.zoom orientationchange.lg.zoom", function() {
                _this.pageX = $(window).width() / 2;
                _this.pageY = $(window).height() / 2 + $(window).scrollTop();
                zoom(scale);
            });
            $("#lg-zoom-out").on("click.lg", function() {
                if (_this.core.$outer.find(".lg-current .lg-image").length) {
                    scale -= _this.core.s.scale;
                    callScale();
                }
            });
            $("#lg-zoom-in").on("click.lg", function() {
                if (_this.core.$outer.find(".lg-current .lg-image").length) {
                    scale += _this.core.s.scale;
                    callScale();
                }
            });
            $("#lg-actual-size").on("click.lg", function(event) {
                actualSize(event, _this.core.$slide.eq(_this.core.index).find(".lg-image"), _this.core.index, true);
            });
            _this.core.$el.on("onBeforeSlide.lg.tm", function() {
                scale = 1;
                _this.resetZoom();
            });
            _this.zoomDrag();
            _this.zoomSwipe();
        };
        Zoom.prototype.resetZoom = function() {
            this.core.$outer.removeClass("lg-zoomed");
            this.core.$slide.find(".lg-img-wrap").removeAttr("style data-x data-y");
            this.core.$slide.find(".lg-image").removeAttr("style data-scale");
            this.pageX = $(window).width() / 2;
            this.pageY = $(window).height() / 2 + $(window).scrollTop();
        };
        Zoom.prototype.zoomSwipe = function() {
            var _this = this;
            var startCoords = {};
            var endCoords = {};
            var isMoved = false;
            var allowX = false;
            var allowY = false;
            _this.core.$slide.on("touchstart.lg", function(e) {
                if (_this.core.$outer.hasClass("lg-zoomed")) {
                    var $image = _this.core.$slide.eq(_this.core.index).find(".lg-object");
                    allowY = $image.prop("offsetHeight") * $image.attr("data-scale") > _this.core.$outer.find(".lg").height();
                    allowX = $image.prop("offsetWidth") * $image.attr("data-scale") > _this.core.$outer.find(".lg").width();
                    if (allowX || allowY) {
                        e.preventDefault();
                        startCoords = {
                            x: e.originalEvent.targetTouches[0].pageX,
                            y: e.originalEvent.targetTouches[0].pageY
                        };
                    }
                }
            });
            _this.core.$slide.on("touchmove.lg", function(e) {
                if (_this.core.$outer.hasClass("lg-zoomed")) {
                    var _$el = _this.core.$slide.eq(_this.core.index).find(".lg-img-wrap");
                    var distanceX;
                    var distanceY;
                    e.preventDefault();
                    isMoved = true;
                    endCoords = {
                        x: e.originalEvent.targetTouches[0].pageX,
                        y: e.originalEvent.targetTouches[0].pageY
                    };
                    _this.core.$outer.addClass("lg-zoom-dragging");
                    if (allowY) {
                        distanceY = -Math.abs(_$el.attr("data-y")) + (endCoords.y - startCoords.y);
                    } else {
                        distanceY = -Math.abs(_$el.attr("data-y"));
                    }
                    if (allowX) {
                        distanceX = -Math.abs(_$el.attr("data-x")) + (endCoords.x - startCoords.x);
                    } else {
                        distanceX = -Math.abs(_$el.attr("data-x"));
                    }
                    if (Math.abs(endCoords.x - startCoords.x) > 15 || Math.abs(endCoords.y - startCoords.y) > 15) {
                        if (_this.core.s.useLeftForZoom) {
                            _$el.css({
                                left: distanceX + "px",
                                top: distanceY + "px"
                            });
                        } else {
                            _$el.css("transform", "translate3d(" + distanceX + "px, " + distanceY + "px, 0)");
                        }
                    }
                }
            });
            _this.core.$slide.on("touchend.lg", function() {
                if (_this.core.$outer.hasClass("lg-zoomed")) {
                    if (isMoved) {
                        isMoved = false;
                        _this.core.$outer.removeClass("lg-zoom-dragging");
                        _this.touchendZoom(startCoords, endCoords, allowX, allowY);
                    }
                }
            });
        };
        Zoom.prototype.zoomDrag = function() {
            var _this = this;
            var startCoords = {};
            var endCoords = {};
            var isDraging = false;
            var isMoved = false;
            var allowX = false;
            var allowY = false;
            _this.core.$slide.on("mousedown.lg.zoom", function(e) {
                var $image = _this.core.$slide.eq(_this.core.index).find(".lg-object");
                allowY = $image.prop("offsetHeight") * $image.attr("data-scale") > _this.core.$outer.find(".lg").height();
                allowX = $image.prop("offsetWidth") * $image.attr("data-scale") > _this.core.$outer.find(".lg").width();
                if (_this.core.$outer.hasClass("lg-zoomed")) {
                    if ($(e.target).hasClass("lg-object") && (allowX || allowY)) {
                        e.preventDefault();
                        startCoords = {
                            x: e.pageX,
                            y: e.pageY
                        };
                        isDraging = true;
                        _this.core.$outer.scrollLeft += 1;
                        _this.core.$outer.scrollLeft -= 1;
                        _this.core.$outer.removeClass("lg-grab").addClass("lg-grabbing");
                    }
                }
            });
            $(window).on("mousemove.lg.zoom", function(e) {
                if (isDraging) {
                    var _$el = _this.core.$slide.eq(_this.core.index).find(".lg-img-wrap");
                    var distanceX;
                    var distanceY;
                    isMoved = true;
                    endCoords = {
                        x: e.pageX,
                        y: e.pageY
                    };
                    _this.core.$outer.addClass("lg-zoom-dragging");
                    if (allowY) {
                        distanceY = -Math.abs(_$el.attr("data-y")) + (endCoords.y - startCoords.y);
                    } else {
                        distanceY = -Math.abs(_$el.attr("data-y"));
                    }
                    if (allowX) {
                        distanceX = -Math.abs(_$el.attr("data-x")) + (endCoords.x - startCoords.x);
                    } else {
                        distanceX = -Math.abs(_$el.attr("data-x"));
                    }
                    if (_this.core.s.useLeftForZoom) {
                        _$el.css({
                            left: distanceX + "px",
                            top: distanceY + "px"
                        });
                    } else {
                        _$el.css("transform", "translate3d(" + distanceX + "px, " + distanceY + "px, 0)");
                    }
                }
            });
            $(window).on("mouseup.lg.zoom", function(e) {
                if (isDraging) {
                    isDraging = false;
                    _this.core.$outer.removeClass("lg-zoom-dragging");
                    if (isMoved && (startCoords.x !== endCoords.x || startCoords.y !== endCoords.y)) {
                        endCoords = {
                            x: e.pageX,
                            y: e.pageY
                        };
                        _this.touchendZoom(startCoords, endCoords, allowX, allowY);
                    }
                    isMoved = false;
                }
                _this.core.$outer.removeClass("lg-grabbing").addClass("lg-grab");
            });
        };
        Zoom.prototype.touchendZoom = function(startCoords, endCoords, allowX, allowY) {
            var _this = this;
            var _$el = _this.core.$slide.eq(_this.core.index).find(".lg-img-wrap");
            var $image = _this.core.$slide.eq(_this.core.index).find(".lg-object");
            var distanceX = -Math.abs(_$el.attr("data-x")) + (endCoords.x - startCoords.x);
            var distanceY = -Math.abs(_$el.attr("data-y")) + (endCoords.y - startCoords.y);
            var minY = (_this.core.$outer.find(".lg").height() - $image.prop("offsetHeight")) / 2;
            var maxY = Math.abs($image.prop("offsetHeight") * Math.abs($image.attr("data-scale")) - _this.core.$outer.find(".lg").height() + minY);
            var minX = (_this.core.$outer.find(".lg").width() - $image.prop("offsetWidth")) / 2;
            var maxX = Math.abs($image.prop("offsetWidth") * Math.abs($image.attr("data-scale")) - _this.core.$outer.find(".lg").width() + minX);
            if (Math.abs(endCoords.x - startCoords.x) > 15 || Math.abs(endCoords.y - startCoords.y) > 15) {
                if (allowY) {
                    if (distanceY <= -maxY) {
                        distanceY = -maxY;
                    } else if (distanceY >= -minY) {
                        distanceY = -minY;
                    }
                }
                if (allowX) {
                    if (distanceX <= -maxX) {
                        distanceX = -maxX;
                    } else if (distanceX >= -minX) {
                        distanceX = -minX;
                    }
                }
                if (allowY) {
                    _$el.attr("data-y", Math.abs(distanceY));
                } else {
                    distanceY = -Math.abs(_$el.attr("data-y"));
                }
                if (allowX) {
                    _$el.attr("data-x", Math.abs(distanceX));
                } else {
                    distanceX = -Math.abs(_$el.attr("data-x"));
                }
                if (_this.core.s.useLeftForZoom) {
                    _$el.css({
                        left: distanceX + "px",
                        top: distanceY + "px"
                    });
                } else {
                    _$el.css("transform", "translate3d(" + distanceX + "px, " + distanceY + "px, 0)");
                }
            }
        };
        Zoom.prototype.destroy = function() {
            var _this = this;
            _this.core.$el.off(".lg.zoom");
            $(window).off(".lg.zoom");
            _this.core.$slide.off(".lg.zoom");
            _this.core.$el.off(".lg.tm.zoom");
            _this.resetZoom();
            clearTimeout(_this.zoomabletimeout);
            _this.zoomabletimeout = false;
        };
        $.fn.lightGallery.modules.zoom = Zoom;
    })();
});

(function(root, factory) {
    if (typeof define === "function" && define.amd) {
        define([ "jquery" ], function(a0) {
            return factory(a0);
        });
    } else if (typeof exports === "object") {
        module.exports = factory(require("jquery"));
    } else {
        factory(jQuery);
    }
})(this, function($) {
    (function() {
        "use strict";
        var defaults = {
            hash: true
        };
        var Hash = function(element) {
            this.core = $(element).data("lightGallery");
            this.core.s = $.extend({}, defaults, this.core.s);
            if (this.core.s.hash) {
                this.oldHash = window.location.hash;
                this.init();
            }
            return this;
        };
        Hash.prototype.init = function() {
            var _this = this;
            var _hash;
            _this.core.$el.on("onAfterSlide.lg.tm", function(event, prevIndex, index) {
                if (history.replaceState) {
                    history.replaceState(null, null, window.location.pathname + window.location.search + "#lg=" + _this.core.s.galleryId + "&slide=" + index);
                } else {
                    window.location.hash = "lg=" + _this.core.s.galleryId + "&slide=" + index;
                }
            });
            $(window).on("hashchange.lg.hash", function() {
                _hash = window.location.hash;
                var _idx = parseInt(_hash.split("&slide=")[1], 10);
                if (_hash.indexOf("lg=" + _this.core.s.galleryId) > -1) {
                    _this.core.slide(_idx, false, false);
                } else if (_this.core.lGalleryOn) {
                    _this.core.destroy();
                }
            });
        };
        Hash.prototype.destroy = function() {
            if (!this.core.s.hash) {
                return;
            }
            if (this.oldHash && this.oldHash.indexOf("lg=" + this.core.s.galleryId) < 0) {
                if (history.replaceState) {
                    history.replaceState(null, null, this.oldHash);
                } else {
                    window.location.hash = this.oldHash;
                }
            } else {
                if (history.replaceState) {
                    history.replaceState(null, document.title, window.location.pathname + window.location.search);
                } else {
                    window.location.hash = "";
                }
            }
            this.core.$el.off(".lg.hash");
        };
        $.fn.lightGallery.modules.hash = Hash;
    })();
});

(function(root, factory) {
    if (typeof define === "function" && define.amd) {
        define([ "jquery" ], function(a0) {
            return factory(a0);
        });
    } else if (typeof exports === "object") {
        module.exports = factory(require("jquery"));
    } else {
        factory(jQuery);
    }
})(this, function($) {
    (function() {
        "use strict";
        var defaults = {
            share: true,
            facebook: true,
            facebookDropdownText: "Facebook",
            twitter: true,
            twitterDropdownText: "Twitter",
            googlePlus: true,
            googlePlusDropdownText: "GooglePlus",
            pinterest: true,
            pinterestDropdownText: "Pinterest"
        };
        var Share = function(element) {
            this.core = $(element).data("lightGallery");
            this.core.s = $.extend({}, defaults, this.core.s);
            if (this.core.s.share) {
                this.init();
            }
            return this;
        };
        Share.prototype.init = function() {
            var _this = this;
            var shareHtml = '<span id="lg-share" class="lg-icon">' + '<ul class="lg-dropdown" style="position: absolute;">';
            shareHtml += _this.core.s.facebook ? '<li><a id="lg-share-facebook" target="_blank"><span class="lg-icon"></span><span class="lg-dropdown-text">' + this.core.s.facebookDropdownText + "</span></a></li>" : "";
            shareHtml += _this.core.s.twitter ? '<li><a id="lg-share-twitter" target="_blank"><span class="lg-icon"></span><span class="lg-dropdown-text">' + this.core.s.twitterDropdownText + "</span></a></li>" : "";
            shareHtml += _this.core.s.googlePlus ? '<li><a id="lg-share-googleplus" target="_blank"><span class="lg-icon"></span><span class="lg-dropdown-text">' + this.core.s.googlePlusDropdownText + "</span></a></li>" : "";
            shareHtml += _this.core.s.pinterest ? '<li><a id="lg-share-pinterest" target="_blank"><span class="lg-icon"></span><span class="lg-dropdown-text">' + this.core.s.pinterestDropdownText + "</span></a></li>" : "";
            shareHtml += "</ul></span>";
            this.core.$outer.find(".lg-toolbar").append(shareHtml);
            this.core.$outer.find(".lg").append('<div id="lg-dropdown-overlay"></div>');
            $("#lg-share").on("click.lg", function() {
                _this.core.$outer.toggleClass("lg-dropdown-active");
            });
            $("#lg-dropdown-overlay").on("click.lg", function() {
                _this.core.$outer.removeClass("lg-dropdown-active");
            });
            _this.core.$el.on("onAfterSlide.lg.tm", function(event, prevIndex, index) {
                setTimeout(function() {
                    $("#lg-share-facebook").attr("href", "https://www.facebook.com/sharer/sharer.php?u=" + encodeURIComponent(_this.getSahreProps(index, "facebookShareUrl") || window.location.href));
                    $("#lg-share-twitter").attr("href", "https://twitter.com/intent/tweet?text=" + _this.getSahreProps(index, "tweetText") + "&url=" + encodeURIComponent(_this.getSahreProps(index, "twitterShareUrl") || window.location.href));
                    $("#lg-share-googleplus").attr("href", "https://plus.google.com/share?url=" + encodeURIComponent(_this.getSahreProps(index, "googleplusShareUrl") || window.location.href));
                    $("#lg-share-pinterest").attr("href", "http://www.pinterest.com/pin/create/button/?url=" + encodeURIComponent(_this.getSahreProps(index, "pinterestShareUrl") || window.location.href) + "&media=" + encodeURIComponent(_this.getSahreProps(index, "src")) + "&description=" + _this.getSahreProps(index, "pinterestText"));
                }, 100);
            });
        };
        Share.prototype.getSahreProps = function(index, prop) {
            var shareProp = "";
            if (this.core.s.dynamic) {
                shareProp = this.core.s.dynamicEl[index][prop];
            } else {
                var _href = this.core.$items.eq(index).attr("href");
                var _prop = this.core.$items.eq(index).data(prop);
                shareProp = prop === "src" ? _href || _prop : _prop;
            }
            return shareProp;
        };
        Share.prototype.destroy = function() {};
        $.fn.lightGallery.modules.share = Share;
    })();
});

!function() {
    function _dynamicSort(property) {
        let sortOrder = 1;
        if (property[0] === "-") {
            sortOrder = -1;
            property = property.substr(1);
        }
        return function(a, b) {
            let result;
            if (isNaN(a[property]) || isNaN(b[property])) {
                result = a[property] < b[property] ? -1 : a[property] > b[property] ? 1 : 0;
            } else {
                result = a[property] - b[property];
            }
            return result * sortOrder;
        };
    }
    function _dynamicSortMultiple() {
        var props = arguments;
        return function(obj1, obj2) {
            let i = 0, result = 0, numberOfProperties = props.length;
            while (result === 0 && i < numberOfProperties) {
                result = _dynamicSort(props[i])(obj1, obj2);
                i++;
            }
            return result;
        };
    }
    Object.defineProperty(Array.prototype, "sortBy", {
        enumerable: false,
        writable: true,
        value: function() {
            return this.sort(_dynamicSortMultiple.apply(null, arguments));
        }
    });
    Object.defineProperty(Array.prototype, "shuffle", {
        enumerable: false,
        writable: true,
        value: function() {
            let i = this.length, j, temp;
            if (i == 0) return this;
            while (--i) {
                j = Math.floor(Math.random() * (i + 1));
                temp = this[i];
                this[i] = this[j];
                this[j] = temp;
            }
            return this;
        }
    });
}();

(function($) {
    $.fn.onImpression = function(options) {
        var settings = $.extend({
            offset: 0,
            callback: null,
            attribute: "",
            alwayscallback: false,
            scrollable: ""
        }, options);
        var $window = $(window), $scrollable = $(settings.scrollable), onImpressionElements = this, loaded;
        this.one("onImpression", function() {
            if (typeof settings.callback === "function") settings.callback.call(this, this.getAttribute(settings.attribute));
        });
        this.on("alwaysOnImpression", function() {
            if (typeof settings.callback === "function") settings.callback.call(this, this.getAttribute(settings.attribute));
        });
        function onImpression() {
            var inview = onImpressionElements.filter(function() {
                var $e = $(this);
                if ($e.is(":hidden")) return;
                var wt = $window.scrollTop(), wb = wt + $window.height(), et = $e.offset().top, eb = et + $e.height();
                var inScrollable = false;
                if ($scrollable.length) {
                    var scrollTop = $scrollable.scrollTop(), scrollBottom = scrollTop + $scrollable.height();
                    inScrollable = eb >= scrollTop - settings.offset && et <= scrollBottom + settings.offset;
                }
                return eb >= wt - settings.offset && et <= wb + settings.offset || inScrollable;
            });
            if (settings.alwayscallback) {
                loaded = inview.trigger("alwaysOnImpression");
            } else {
                loaded = inview.trigger("onImpression");
                onImpressionElements = onImpressionElements.not(loaded);
            }
        }
        if (typeof settings.callback === "function") {
            if ($scrollable.length) {
                $scrollable.on("scroll.onImpression resize.onImpression lookup.onImpression", onImpression);
            } else {
                $window.on("scroll.onImpression resize.onImpression lookup.onImpression", onImpression);
            }
            onImpression();
        }
        return this;
    };
})(jQuery);

class Logger {
    constructor(options) {
        const defaults = {
            loglevel: 1,
            group: "logger"
        };
        this.options = Object.assign({}, defaults, options);
        this.loglevel = parseInt(this.getUrlParameter("loglevel") || window.loglevel) || this.options.loglevel;
    }
    getUrlParameter(name) {
        name = name.replace(/[[]/, "\\[").replace(/[\]]/, "\\]");
        var regex = new RegExp("[\\?&]" + name + "=([^&#]*)");
        var results = regex.exec(location.search);
        return results === null ? "" : decodeURIComponent(results[1].replace(/\+/g, " "));
    }
    groupEnd() {
        console.groupEnd();
    }
    error() {
        console.error(...arguments);
    }
    warn() {
        if (this.loglevel >= 1) console.warn(...arguments);
    }
    info() {
        if (this.loglevel >= 2) console.info(...arguments);
    }
    log() {
        if (this.loglevel >= 2) console.log(...arguments);
    }
    group(group, collapse = false) {
        if (this.loglevel >= 2) {
            if (collapse) {
                console.groupCollapsed(group || this.options.group);
            } else {
                console.group(group || this.options.group);
            }
        }
    }
    debug() {
        if (this.loglevel >= 3) console.debug(...arguments);
    }
    count() {
        if (this.loglevel >= 3) console.count(...arguments);
    }
    table() {
        if (this.loglevel >= 3) console.table(...arguments);
    }
    timer(label, callback, alwaysRun = false) {
        if (this.loglevel >= 3) {
            console.time(label);
            try {
                callback();
            } finally {
                console.timeEnd(label);
            }
        } else {
            if (alwaysRun === true) callback();
        }
    }
}

"use strict";

class TotalCMS {
    constructor(options = {}) {
        this.collection = null;
        const defaults = {
            passport: null,
            cache: true,
            cors: false,
            loglevel: 1,
            locale: "en",
            expireStorage: 30,
            rgbOffset: 50,
            hslOffset: 15,
            localizeStrings: {},
            config: {},
            uri: "/rw_common/plugins/stacks/dynamics/public.php"
        };
        const globals = typeof window.totalcms === "object" ? window.totalcms.options : {};
        this.options = Object.assign({}, defaults, globals, options);
        this.cache = this.options.cache;
        this.config = this.options.config || {};
        this.expireStorage = this.options.expireStorage * 1e3 * 60 * 60 * 24;
        this.expireKey = "expire";
        this.localStorage = Storages.localStorage;
        this.sessionStorage = Storages.sessionStorage;
        this.log = new Logger({
            loglevel: this.options.loglevel,
            group: "totalcms"
        });
    }
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
    postAPI(api, data, method = "POST") {
        this.localStorage.remove(api);
        this.localStorage.remove(this.expireKey + api);
        this.sessionStorage.remove(api);
        this.log.debug(`postAPI ${this.options.uri + api}`, data);
        return fetch(this.options.uri + api, {
            method: method,
            mode: this.options.cors ? "cors" : "same-origin",
            headers: new Headers({
                "Content-Type": "application/json"
            }),
            body: JSON.stringify(data)
        }).then(response => {
            if (!response.ok) {
                response.json().then(json => console.error("postAPI Error", json));
                throw Error(response.statusText);
            }
            return response.json();
        });
    }
    fetchCachedAPI(api) {
        this.log.debug("fetchCachedAPI:" + api);
        this.log.debug("localstorage expire:" + this.localStorage.get(this.expireKey + api));
        this.log.debug("now:" + Date.now());
        if (this.cache && this.localStorage.isSet(api) && this.localStorage.get(this.expireKey + api) > Date.now()) {
            this.log.debug("Using localstorage. returning promise");
            return new Promise((resolve, reject) => {
                if (!this.sessionStorage.isSet(api)) {
                    this.log.debug("Caching fresh data for public", api);
                    this.fetchAPI(api);
                }
                resolve(this.localStorage.get(api));
            });
        }
        return this.fetchAPI(api);
    }
    fetchAPI(api) {
        this.log.debug("fetchAPI:" + api);
        const promise = fetch(this.options.uri + api).then(response => {
            if (!response.ok) {
                response.json().then(json => console.error("fetchAPI Error", json));
                throw Error(response.statusText);
            }
            return response.json();
        });
        promise.then(json => {
            this.log.info("API Request Succeeded", json);
            if (Array.isArray(json)) {
                json = json.map(object => this.addObjectHelpers(object));
            } else {
                json = this.addObjectHelpers(json);
            }
            this.localStorage.set(this.expireKey + api, Date.now() + this.expireStorage);
            this.localStorage.set(api, json);
            this.sessionStorage.set(api, true);
        }).catch(error => {
            console.error("API Request Failed", error);
        });
        return promise;
    }
    locateHelperFields(object) {
        const colors = [];
        const images = [];
        const galleries = [];
        for (const property in object) {
            if (!object[property]) continue;
            if (object[property].colors) images.push(property);
            if (object[property].hex) colors.push(property);
            if (object[property][0] && object[property][0].colors) galleries.push(property);
        }
        return [ colors, images, galleries ];
    }
    maxColor(value, min = 0, max = 255) {
        return Math.min(Math.max(value, min), max);
    }
    rgbaString(color, offset = 0) {
        if (typeof color !== "object") {
            console.warn("Could not recognize color oject. Returning rgba(255,255,255,1)");
            return "rgba(255,255,255,1)";
        }
        const red = this.maxColor(color.rgb[0] + offset);
        const green = this.maxColor(color.rgb[1] + offset);
        const blue = this.maxColor(color.rgb[2] + offset);
        return `rgba(${red},${green},${blue},${color.alpha})`;
    }
    hslaString(color, offset = 0) {
        if (typeof color !== "object") {
            console.warn("Could not recognize color oject. Returning rgba(255,255,255,1)");
            return "rgba(255,255,255,1)";
        }
        const hue = color.hsl[0];
        const saturation = color.hsl[1];
        const lightness = this.maxColor(color.hsl[2] - offset, 0, 100);
        return `hsla(${hue},${saturation}%,${lightness}%,${color.alpha})`;
    }
    colorObjectHelpers(color) {
        return {
            rgba: this.rgbaString(color),
            hsla: this.hslaString(color),
            rgbaOffset: this.rgbaString(color, this.options.rgbOffset),
            hslaOffset: this.hslaString(color, this.options.hslOffset)
        };
    }
    addObjectHelpers(object) {
        if (typeof object !== "object") {
            console.warn("The API request does not contian an object. Not adding object helpers...");
            return;
        }
        object.collection = this.collection;
        const [colors, images, galleries] = this.locateHelperFields(object);
        colors.forEach(colorName => object[colorName] = Object.assign(this.colorObjectHelpers(object[colorName]), object[colorName]));
        images.forEach(imageName => {
            object[imageName]["colors"].forEach((color, colorIndex) => {
                object[imageName]["colors"][colorIndex] = Object.assign(this.colorObjectHelpers(color), color);
            });
        });
        galleries.forEach(galleryName => {
            object[galleryName].forEach((image, imageIndex) => {
                object[galleryName][imageIndex]["id"] = object.id;
                object[galleryName][imageIndex]["collection"] = object.collection;
                object[galleryName][imageIndex]["colors"].forEach((color, colorIndex) => {
                    object[galleryName][imageIndex]["colors"][colorIndex] = Object.assign(this.colorObjectHelpers(color), color);
                });
            });
        });
        return object;
    }
    isTouch() {
        return "ontouchstart" in window || window.DocumentTouch && document instanceof DocumentTouch || false;
    }
    basename(str) {
        var base = str.substring(str.lastIndexOf("/") + 1);
        if (base.lastIndexOf(".") != -1) base = base.substring(0, base.lastIndexOf("."));
        return base;
    }
    stringToElement(string) {
        return document.createRange().createContextualFragment(string);
    }
    stringToArray(string) {
        return string.replace(/\s+/g, "").split(",").filter(Boolean);
    }
    listToArray(list) {
        return list.trim().replace(/,/g, " ").replace(/\s+/g, ",").split(",");
    }
    imageLoadTransition(node) {
        node.classList.add("template-layout", "template-loading");
        imagesLoaded(node, () => node.classList.remove("template-loading"));
    }
    processTemplate(data, template, dest) {
        template = template.replace(/(\r\n|\n|\r)+/gm, " ");
        this.log.debug("processTemplate", data, template, dest);
        const output = Mustache.render(template, data);
        this.log.debug("Mustache output", output);
        const node = this.stringToElement(output);
        if (dest instanceof HTMLElement) {
            dest.appendChild(node);
            this.imageLoadTransition(dest.children[dest.children.length - 1]);
        }
        return node;
    }
    getUrlParameter(name) {
        name = name.replace(/[[]/, "\\[").replace(/[\]]/, "\\]");
        const regex = new RegExp("[\\?&]" + name + "=([^&#]*)");
        const results = regex.exec(location.search);
        return results === null ? "" : decodeURIComponent(results[1].replace(/\+/g, " "));
    }
    getObjectId() {
        const param = this.getUrlParameter("id");
        if (param) return param;
        if (window.totalPreview && window.totalPreview.hasOwnProperty("id")) {
            return window.totalPreview["id"];
        }
        const prettyId = document.location.pathname.substr(document.location.pathname.lastIndexOf("/") + 1);
        if (!prettyId.match(/[.?=]/)) return prettyId;
        console.error("Unable to locate ID for query");
    }
    buildUrlQuery(api, params) {
        const baseapi = this.options.uri + api;
        if (typeof params !== "object") return baseapi;
        const queryString = Object.keys(params).map(key => key + "=" + params[key]).join("&");
        return `${baseapi}?${queryString}`;
    }
}

class Schema {
    constructor(form) {
        this.form = form;
        this.collection = this.form.collection;
        this.baseapi = `/collections/${this.collection}`;
        this.type = "object";
        this.index = this.namesByAttribute("data-index");
        this.required = this.namesByAttribute("data-required");
        this.properties = this.processFieldsets();
        this.getServerSchema().then(serverSchema => this.compareSchema(serverSchema));
    }
    compareSchema(serverSchema) {
        if (window.localpreview === true) return;
        if (JSON.stringify(this.generateLocalSchema()) !== JSON.stringify(serverSchema)) {
            this.form.log.info("Need to save local schema to server");
            this.saveSchema();
        }
    }
    getServerSchema() {
        return this.form.api.fetchCachedAPI(`${this.baseapi}/schema`);
    }
    namesByAttribute(attr) {
        const fields = this.form.fieldsets.filter(field => field.getAttribute(attr) !== null);
        return fields.map(field => field.dataset.name);
    }
    processFieldsets() {
        const properties = {};
        for (const name in this.form.fieldObjects) {
            properties[name] = this.form.fieldObjects[name].schema();
        }
        return properties;
    }
    generateLocalSchema() {
        return {
            index: this.index,
            required: this.required,
            type: this.type,
            title: this.collection,
            properties: this.properties
        };
    }
    saveSchema() {
        this.form.api.postAPI(`${this.baseapi}/schema`, this.generateLocalSchema());
    }
}

class TotalForm {
    constructor(formRef, options) {
        if (!formRef) {
            return false;
        }
        this.form = this.setForm(formRef);
        const defaults = {
            loglevel: 1,
            newAction: "none",
            newLink: "none",
            editAction: window.location.href,
            editLink: window.location.href
        };
        const local = this.form.dataset.options ? JSON.parse(this.form.dataset.options) : {};
        this.options = Object.assign({}, defaults, options, local);
        this.log = new Logger({
            loglevel: this.options.loglevel,
            group: "totalform"
        });
        this.api = new TotalCMS({
            loglevel: this.options.loglevel
        });
        this.log.debug(this.constructor.name + " Options", this.options);
        this.collection = this.find("input[name=collection]").value;
        this.baseapi = `/collections/${this.collection}`;
        this.id = this.api.getUrlParameter("id") || this.api.getUrlParameter("permalink");
        this.indicator = null;
        this.processingStart = Date.now();
        this.processingLimit = 1500;
        this.states = [ "success", "error", "processing", "clear" ];
        this.fieldsets = this.findAll("fieldset").filter(field => !this.insideDeck(field));
        this.droplets = this.fieldsets.filter(field => field.classList.contains("droplet"));
        this.fieldObjects = this.processFieldsets();
        this.schema = new Schema(this);
        this.addTemplates();
        this.saveListener();
        this.registerButtons();
        if (this.id) this.getServerObject();
    }
    find(selector) {
        return this.findAll(selector).shift();
    }
    findAll(selector) {
        return Array.from(this.form.querySelectorAll(selector));
    }
    insideDeck(node) {
        return node.parentNode.closest("fieldset.deck-box") ? true : false;
    }
    isDomNode(node) {
        return typeof node === "object" && "nodeType" in node && node.nodeType === 1;
    }
    setForm(formRef) {
        switch (typeof formRef) {
          case "string":
            return document.getElementById(formRef);

          case "object":
            if (this.isDomNode(formRef)) {
                return formRef;
            }
            break;
        }
        return null;
    }
    processFieldsets() {
        const data = {};
        this.fieldsets.forEach(field => {
            const object = this.generateFieldObject(field);
            if (object === null) return;
            data[field.dataset.name] = object;
            field.addEventListener("change", event => {
                this.unsaved();
            });
        });
        return data;
    }
    registerButton(buttonClass, callback) {
        const allButtons = Array.from(document.getElementsByClassName(buttonClass));
        const buttons = allButtons.filter(button => {
            const form = button.closest("form");
            if (form) return form === this.form;
            return true;
        });
        buttons.forEach(button => {
            button.addEventListener("click", event => {
                event.preventDefault();
                if (typeof callback === "function") callback(button);
                return false;
            });
        });
    }
    registerButtons() {
        this.registerButton("cms-save", () => this.save());
        this.registerButton("cms-delete", () => this.delete());
    }
    generateFieldObject(field) {
        const options = JSON.parse(field.dataset.options || "{}");
        options.form = this;
        switch (field.dataset.type) {
          case "text":
          case "video":
            return new Fieldset(field, options);

          case "styledtext":
            return new StyledTextField(field, options);

          case "markdown":
            return new MarkdownField(field, options);

          case "svg":
            return new SVGField(field, options);

          case "select":
            return new SelectField(field, options);

          case "multiselect":
            return new MultiSelectField(field, options);

          case "number":
            return new NumberField(field, options);

          case "checkbox":
          case "toggle":
            return new Checkbox(field, options);

          case "permalink":
            return this.initPermalink(field, options);

          case "range":
            return new RangeSlider(field, options);

          case "color":
            return new ColorPicker(field, options);

          case "date":
            return new DatePicker(field, options);

          case "deck":
            return new Deck(field, options);

          case "image":
          case "file":
            return this.initDroplet(field, options);

          case "gallery":
          case "depot":
            return this.initArrayDroplet(field, options);

          case "list":
            return new ListComplete(field, options);

          default:
            this.log.warn("Unknown fieldset", fieldset);
            return null;
        }
    }
    initPermalink(field, options) {
        this.permalink = new Permalink(field, options);
        field.addEventListener("change", event => this.updatePermalink());
        return this.permalink;
    }
    initArrayDroplet(field, options) {
        options.type = field.dataset.type;
        const droplet = new ArrayDroplet(field, options);
        droplet.updateUri();
        return droplet;
    }
    initDroplet(field, options) {
        options.type = field.dataset.type;
        const droplet = new Droplet(field, options);
        droplet.updateUri();
        return droplet;
    }
    getServerObject() {
        this.api.fetchAPI(`${this.baseapi}/${this.id}`).then(object => this.populateForm(object));
    }
    populateForm(object) {
        this.log.group("populateForm", true);
        this.log.debug("Form Object", object);
        this.id = object.id;
        this.log.info(`Set Form ID to ${this.id}`);
        for (const property in object) {
            const field = this.fieldObjects[property];
            this.log.group(property);
            if (!field) {
                console.warn(`Unable to find form field for object property: ${property}`);
                this.log.groupEnd();
                continue;
            }
            field.setValue(object[property]);
            this.log.groupEnd();
        }
        this.log.groupEnd();
        this.editMode();
    }
    saveListener() {
        this.form.addEventListener("submit", event => {
            event.preventDefault();
            this.save();
        });
        document.addEventListener("keydown", event => {
            if (this.isUnsaved()) {
                if (event.key === "s" && (event.ctrlKey || event.metaKey)) {
                    event.preventDefault();
                    this.save();
                }
            }
        });
    }
    save() {
        this.updatePermalink();
        this.processing();
        this.api.postAPI(this.baseapi, this.generateData()).then(response => this.afterSave(response)).catch(error => this.error(error));
    }
    delete() {
        if (!this.isEditMode()) return;
        if (window.confirm("Are you sure that you want to delete this? This cannot be undone.")) {
            this.updatePermalink();
            this.processing();
            this.options.editAction = "redirect";
            this.options.editLink = location.origin + location.pathname;
            this.api.postAPI(`/collections/${this.collection}/${this.id}`, {}, "DELETE").then(response => this.afterSave(response)).catch(error => this.error(error));
        }
    }
    submit() {
        this.save();
    }
    updatePermalink() {
        this.log.debug("update permalink");
        this.id = this.permalink.id;
    }
    afterSave(response) {
        if (!response) return;
        this.log.debug("afterSave", response);
        if (this.droplets.length > 0) {
            this.log.debug("Waiting for droplets to save to perform action");
            this.saveDroplets(() => this.afterSaveAction(response));
        } else {
            this.afterSaveAction(response);
        }
    }
    afterSaveAction(response) {
        this.success();
        const waitUntilSaved = () => {
            if (!this.saving()) {
                return this.isEditMode() ? this.runEditAction() : this.runNewAction();
            }
            window.setTimeout(waitUntilSaved, 100);
        };
        waitUntilSaved();
    }
    runAction(action, url) {
        switch (action) {
          case "refresh":
            location.reload(true);
            break;

          case "redirect-object":
            document.location = url + this.id;
            break;

          case "redirect":
            document.location = url;
            break;

          case "back":
            if (window.history.length > 1) {
                document.location = document.referrer;
            }
            break;
        }
    }
    runNewAction() {
        this.runAction(this.options.newAction, this.options.newLink);
    }
    runEditAction() {
        this.runAction(this.options.editAction, this.options.editLink);
    }
    addTemplates() {
        this.templateSaveIndicator();
    }
    templateSaveIndicator() {
        const indicator = document.getElementById("form-save-indicator");
        if (indicator) {
            this.indicator = indicator;
        } else {
            this.api.fetchCachedAPI("/templates/admin/form-save").then(json => {
                const body = document.getElementsByTagName("body")[0];
                this.api.processTemplate({}, json.template, body);
                this.indicator = document.getElementById("form-save-indicator");
                this.indicator.addEventListener("click", () => this.indicator.classList = "");
            });
        }
    }
    isUnsaved() {
        return this.form.classList.contains("unsaved");
    }
    unsaved() {
        return this.form.classList.add("unsaved");
    }
    isEditMode() {
        return this.form.classList.contains("edit-form");
    }
    editMode() {
        this.form.classList.add("edit-form");
        for (const name in this.fieldObjects) {
            const field = this.fieldObjects[name];
            if (field.dropzone) field.autoProcessQueue();
        }
    }
    saving() {
        const current = this.states.filter(state => this.form.classList.contains(state));
        return current.length > 0;
    }
    changeState(newState) {
        const remove = this.states.filter(e => e !== newState);
        const elements = [ this.indicator, this.form ];
        for (const element of elements) {
            if (newState) element.classList.add(newState);
            element.classList.remove(...remove);
        }
    }
    delayProcessing(callback) {
        const processingTime = Date.now() - this.processingStart;
        const delay = this.processingLimit - processingTime;
        this.log.debug(`Delay Processing for ${delay}`);
        window.setTimeout(() => {
            if (typeof callback === "function") callback();
        }, delay);
    }
    error(error) {
        console.error("Form Error: " + error);
        this.delayProcessing(() => {
            this.changeState("error");
        });
    }
    clear() {
        this.changeState("clear");
        window.setTimeout(() => {
            this.changeState();
        }, 1e3);
    }
    success() {
        this.delayProcessing(() => {
            this.changeState("success");
            this.form.classList.remove("unsaved");
            for (const field of this.fieldsets) {
                field.classList.remove("unsaved");
            }
            window.setTimeout(() => {
                this.clear();
            }, 2e3);
        });
    }
    processing() {
        this.processingStart = Date.now();
        this.changeState("clear");
        window.setTimeout(() => {
            this.changeState("processing");
        }, 100);
    }
    updateDropletUri() {
        this.log.debug("updateDropletUri");
        for (const name in this.fieldObjects) {
            const field = this.fieldObjects[name];
            if (field.dropzone) field.updateUri();
        }
    }
    saveDroplets(callback) {
        this.log.debug("saveDroplets");
        let dropletCount = 0;
        for (const name in this.fieldObjects) {
            if (this.fieldObjects[name].dropzone) dropletCount++;
        }
        const dropletComplete = callback => {
            dropletCount--;
            this.log.count("queuecomplete");
            if (dropletCount === 0) {
                if (typeof callback === "function") callback();
            }
        };
        for (const name in this.fieldObjects) {
            const field = this.fieldObjects[name];
            if (!field.dropzone) continue;
            if (field.isComplete()) {
                dropletComplete(callback);
                continue;
            }
            field.updateUri();
            field.onQueueComplete(() => dropletComplete(callback));
            field.processQueue();
        }
    }
    generateData() {
        const data = {};
        for (const name in this.fieldObjects) {
            const value = this.fieldObjects[name].getValue();
            if (value !== null) data[name] = value;
        }
        this.log.debug("generateData", data);
        return data;
    }
}

"use strict";

class BoxGallery {
    constructor(node, options = {}) {
        this.node = node;
        const defaults = {
            selector: ".boxgallery-item"
        };
        this.options = Object.assign({}, defaults, options);
        this.initLightGallery();
    }
    initLightGallery() {
        $(this.node).lightGallery({
            selector: this.options.selector,
            thumbnail: true
        });
    }
}

"use strict";

class ImageWorks extends TotalCMS {
    constructor(options) {
        super(options);
        const defaults = {
            collection: "defaultCollection",
            id: "defaultId",
            property: "defaultImage",
            file: null,
            date: ""
        };
        this.options = Object.assign({}, defaults, window.totalcms.options, options);
        if (this.options.file === null) this.options.file = this.options.property;
        this.log = new Logger({
            loglevel: this.options.loglevel,
            group: "imageworks"
        });
    }
    buildQuery(params) {
        let api = `/imageworks/${this.options.collection}/${this.options.id}/${this.options.property}/${this.options.file}`;
        if (params.format && params.format !== "auto") api += `.${params.format}`;
        if (this.options.date) params.date = this.options.date;
        const query = this.buildUrlQuery(api, params);
        this.log.debug("ImageWorks", query);
        return query;
    }
}

class TotalQuery extends TotalCMS {
    constructor(options) {
        super(...arguments);
        const defaults = {
            collection: "totalcms",
            scope: "all",
            property: "cards",
            template: "",
            display: "all",
            count: 10,
            sort: "none",
            sortBy: "",
            jsql: {}
        };
        this.options = Object.assign({}, this.options, defaults, options);
        this.displayAll = this.options.display === "all";
        this.collection = this.options.collection;
        this.property = this.options.property;
        this.count = parseInt(this.options.count);
        this.sortBy = this.options.sortBy.trim().replace(/\s+/g, "").split(",");
        this.scope = this.options.scope;
        this.sort = this.options.sort;
        this.baseapi = "/collections/" + this.collection;
        this.jsql = this.options.jsql;
        this.queryData = [];
        if (this.scope === "property") {
            this.baseapi = `${this.baseapi}/${this.getObjectId()}`;
        }
        if (this.options.template.length > 0) {
            const template = document.getElementById(this.options.template);
            if (template) this.jsql = JSON.parse(template.innerHTML);
        }
    }
    sortData(data, options = {}) {
        const sort = typeof options.sort === "string" ? options.sort : this.sort;
        if (sort === "none") return data;
        if (sort === "shuffle") return data.shuffle();
        let sortBy = options.sortBy || this.sortBy;
        if (typeof sortBy === "string") sortBy = [ sortBy ];
        if (sortBy.length > 0) {
            if (sort === "reverse") {
                return data.sortBy(...sortBy).reverse();
            }
            return data.sortBy(...sortBy);
        }
        return data;
    }
    paginateData(data, page = 1) {
        this.log.debug(`paginateData for page ${page}`);
        if (!this.displayAll) {
            const start = (page - 1) * this.count;
            const end = start + this.count;
            this.log.debug(`paginateData start: ${start} end: ${end}`);
            return data.slice(start, end);
        }
        return data;
    }
    dateConvert(string) {
        if (typeof string !== "string") return string;
        if (string === "now") {
            return new Date();
        }
        if (string.match(/^\s*moment\(/)) {
            const moment = Function('"use strict";return (' + string + ")")();
            return moment.toDate();
        }
        const date = new Date(string);
        if (date instanceof Date && !isNaN(date)) {
            return date;
        }
        console.warn(`Warning: dateConvert unable to convert to date (${string})`);
        return string;
    }
    dateKeys(object) {
        return Object.keys(object).filter(key => key.match(/date/i));
    }
    enrichData(data) {
        const keys = this.dateKeys(data[0]);
        data.map(object => {
            keys.map(k => object[k] = this.dateConvert(object[k]));
        });
        return data;
    }
    enrichJSQL() {
        if (this.enrichedJSQL === true) return;
        const keys = this.dateKeys(this.jsql);
        keys.map(key => {
            [ "to", "from" ].map(k => {
                if (this.jsql[key].hasOwnProperty(k)) {
                    this.jsql[key][k] = this.dateConvert(this.jsql[key][k]);
                }
            });
        });
        this.enrichedJSQL = true;
    }
    filterData(data) {
        data = this.enrichData(data);
        this.enrichJSQL();
        return SEARCHJS.matchArray(data, this.jsql);
    }
    fetchAllData() {
        return this.fetchCachedAPI(this.baseapi);
    }
    clearStoredData() {
        this.queryData = [];
    }
    fetchQueryData(page = 1, options = {}) {
        this.log.debug(`fetchQueryData for page ${page}`);
        return new Promise((resolve, reject) => {
            if (this.queryData.length > 0 && Object.keys(options).length === 0) {
                resolve(this.paginateData(this.queryData, page));
                return;
            }
            this.fetchAllData().then(data => {
                if (this.scope === "property") {
                    data = data[this.property];
                } else if (this.scope !== "all") {
                    data = this.filterData(data);
                }
                data = this.sortData(data, options);
                if (this.queryData.length === 0) {
                    this.queryData = data;
                }
                resolve(this.paginateData(data, page));
            }).catch(error => {
                this.log.error("Error fetching query data: " + error);
                reject(error);
            });
        });
    }
}

class TotalTemplate extends TotalCMS {
    constructor(options) {
        super(...arguments);
        const defaults = {
            query: {},
            template: null,
            layout: null
        };
        this.options = Object.assign({}, this.options, defaults, options);
        this.query = new TotalQuery(this.options.query);
        this.collection = this.query.collection || null;
        this.template = this.options.template;
        this.layout = this.options.layout;
        this.static = Object.keys(this.options.query).length === 0;
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
                collection: this.collection,
                id: image.dataset.id,
                property: image.dataset.property,
                file: image.dataset.file || null
            });
            const rules = JSON.parse(image.dataset.imageworks);
            image.src = imageWorks.buildQuery(rules);
        }
    }
    processItemBoxGallery(node) {
        const galleries = Array.from(node.querySelectorAll(".boxgallery"));
        galleries.forEach(gallery => new BoxGallery(gallery));
    }
    enhanceItem(node) {
        this.processImageWorks(node);
        this.processMacros(node);
        this.processItemBoxGallery(node);
    }
    processLayoutBoxGallery() {
        const items = Array.from(this.layout.querySelectorAll(".boxgallery-item"));
        if (items.length > 0) new BoxGallery(this.layout);
    }
    enhanceLayout() {
        this.processLayoutBoxGallery();
    }
    insertIntoLayout(object) {
        if (!this.isImageObject(object) && this.objectExistsInLayout(object)) {
            return;
        }
        const item = this.processTemplate(object, this.template.innerHTML);
        this.enhanceItem(item);
        this.layout.appendChild(item);
        this.imageLoadTransition(this.layout.children[this.layout.children.length - 1]);
    }
    populateTemplate(page, options = {}) {
        if (this.static) {
            if (page === 1) this.insertIntoLayout({});
            return new Promise((resolve, reject) => resolve(true));
        }
        const queryPromise = this.query.fetchQueryData(page, options);
        queryPromise.then(data => {
            data.forEach(object => this.insertIntoLayout(object));
            if (this.search) this.search.index();
            this.enhanceLayout();
        });
        return queryPromise;
    }
    objectExistsInLayout(object) {
        return this.objectNode(object) !== null;
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
        if (this.static) return this.removeStaticTemplates();
        if (!query) return this.clearSearch();
        this.query.fetchQueryData().then(layoutObjects => {
            const results = this.search.search(query);
            const objects = results.map(result => layoutObjects.find(obj => obj.id === result.ref)).filter(object => typeof object === "object");
            const remove = this.query.queryData.filter(lo => objects.find(ro => ro.id === lo.id) === undefined);
            remove.forEach(object => this.removeLayoutObject(object));
            objects.forEach(object => this.insertIntoLayout(object));
        });
    }
}

class BentoTemplate extends TotalTemplate {
    constructor(options) {
        super(...arguments);
        const defaults = {
            items: [ 1 ]
        };
        this.options = Object.assign({}, this.options, defaults, options);
        this.items = this.options.items;
        this.count = this.items.length;
    }
    replaceItemInGrid(newItem) {
        const newNode = newItem.querySelector(".grid-item");
        const nodeId = newNode.getAttribute("id");
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
        const layoutItems = Array.from(this.layout.getElementsByClassName("grid-item"));
        if (layoutItems.length > 0) {
            const newNode = newItem.querySelector(".grid-item");
            const nodeId = newNode.getAttribute("id");
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
        this.imageLoadTransition(this.layout.children[this.layout.children.length - 1]);
    }
    insertItemIntoGrid(object, itemNumber) {
        const newItem = this.processTemplate(object, this.template.innerHTML);
        this.processImageWorks(newItem);
        this.processMacros(newItem);
        if (!this.replaceItemInGrid(newItem)) {
            this.insertItemInOrder(newItem, itemNumber);
        }
    }
    populateTemplate() {
        if (Object.keys(this.options.query).length === 0) {
            this.insertItemIntoGrid({}, this.items[0]);
            return new Promise((resolve, reject) => resolve(true));
        }
        return this.query.fetchQueryData().then(objects => {
            this.items.forEach(item => {
                for (const object of objects) {
                    objects.push(objects.shift());
                    if (!this.objectExistsInLayout(object)) {
                        object.item = item;
                        this.insertItemIntoGrid(object, item);
                        break;
                    }
                }
            });
        });
    }
}

class TotalLayout extends TotalCMS {
    constructor(layout) {
        super();
        this.layout = layout;
        layout.cmslayout = this;
        this.id = this.layout.id || this.layout.dataset.id;
        this.templateContollers = [];
        this.filters = [];
        this.registerButtons();
    }
    generateFilters(templates) {
        const filters = templates.filter(template => "filter" in template.dataset);
        filters.forEach(filter => {
            const jsql = JSON.parse(filter.innerHTML);
            const query = new TotalQuery({
                jsql: jsql
            });
            query.filterClass = filter.dataset.filterClass;
            this.filters.push(query);
        });
    }
    applyFilters(data) {
        this.filters.forEach(filter => {
            filter.filterData(data).forEach(object => {
                const node = this.objectNode(object);
                node.classList.add(filter.filterClass);
            });
        });
    }
    populateLayout(options = {}) {
        this.templateContollers.forEach(templateContoller => {
            templateContoller.populateTemplate(this.nextPage, options).then(data => {
                this.log.debug("populateLayout Complete");
                if (!templateContoller.static) this.loadmore();
                this.applyFilters(data);
            });
        });
    }
    shuffleLayout() {
        this.sortLayout("shuffle");
    }
    sortLayout(sort = "", sortBy = []) {
        this.nextPage = 1;
        this.layout.innerHTML = "";
        this.populateLayout({
            sort: sort,
            sortBy: sortBy
        });
    }
    resetLayout() {
        this.nextPage = 1;
        this.layout.innerHTML = "";
        this.populateLayout();
    }
    buildLayout() {
        const templatesFor = Array.from(document.querySelectorAll(`template[data-for=${this.id}]`));
        this.log.debug("Layout Templates", templatesFor);
        this.generateFilters(templatesFor);
        this.nextPage = 1;
        const templates = templatesFor.filter(template => !("filter" in template.dataset));
        templates.forEach(template => {
            const templateContoller = this.createTemplateController(template);
            this.templateContollers.push(templateContoller);
        });
        this.populateLayout();
    }
    objectNode(object) {
        return this.layout.querySelector(`[data-id="${object.id}"]`);
    }
    loadmore() {
        const method = this.layout.dataset.loadmore || "none";
        switch (method.trim()) {
          case "infinite":
            this.infiniteLoad();
            break;

          case "infinite-touch-button":
            this.isTouch() ? this.buttonLoad() : this.infiniteLoad();
            break;

          case "button":
            this.buttonLoad();
            break;

          case "paginate":
            this.paginateLoad();
            break;
        }
    }
    buttonLoad() {
        if (this.buttonTrigger) return;
        this.showButton();
        this.buttonTrigger = this.layout.parentNode.querySelector(".loadmore-button a,.loadmore-button button");
        this.buttonTrigger.addEventListener("click", event => {
            this.loadmoreItems();
            event.preventDefault();
        });
    }
    showButton() {
        const buttonWrapper = this.layout.parentNode.querySelector(".loadmore-button");
        if (buttonWrapper) buttonWrapper.classList.add("show");
    }
    deleteButton() {
        const buttonWrapper = this.layout.parentNode.querySelector(".loadmore-button");
        if (buttonWrapper) this.layout.parentNode.removeChild(buttonWrapper);
    }
    paginateLoad() {}
    infiniteLoad() {
        if (!this.infiniteTrigger) {
            const trigger = document.createElement("div");
            trigger.classList.add("scroll-loadmore");
            this.infiniteTrigger = this.layout.parentNode.insertBefore(trigger, this.layout.nextSibling);
            this.infiniteTrigger.triggered = false;
        }
        this.deleteButton();
        if (this.infiniteTrigger.triggered !== true) {
            this.infiniteTrigger.triggered = true;
            imagesLoaded(this.layout, () => {
                this.infiniteTrigger.triggered = false;
                $(this.infiniteTrigger).onImpression({
                    offset: 600,
                    callback: () => this.loadmoreItems()
                });
            });
        }
    }
    loadmoreItems() {
        if (this.isSearching()) return;
        this.nextPage++;
        this.log.debug(`loadmoreItems: page ${this.nextPage}`);
        this.templateContollers.forEach(templateContoller => {
            templateContoller.populateTemplate(this.nextPage).then(data => {
                if (data.length === 0) return;
                if (!templateContoller.static) this.loadmore();
                if (typeof DISQUSWIDGETS !== "undefined") {
                    DISQUSWIDGETS.getCount({
                        reset: true
                    });
                }
                this.applyFilters(data);
            });
        });
    }
    createTemplateController(template) {
        let query = {};
        if (template.getAttribute("data-static") === null) {
            if (template.dataset.query) {
                query = JSON.parse(template.dataset.query);
                if (query.scope === "inherit") query = JSON.parse(this.layout.dataset.query);
            } else {
                if (!this.layout.dataset.query) {
                    console.warn("Layout Template has no query defined");
                    return null;
                }
                query = JSON.parse(this.layout.dataset.query);
            }
            this.log.debug("Layout Query", query);
        }
        return new TotalTemplate({
            query: query,
            template: template,
            layout: this.layout
        });
    }
    clearSearch() {
        this.isSearching(false);
        this.resetLayout();
    }
    isSearching(enable) {
        const searchClass = "search-results";
        if (enable === true) this.layout.classList.add(searchClass);
        if (enable === false) this.layout.classList.remove(searchClass);
        return this.layout.classList.contains(searchClass);
    }
    registerButton(buttonClass, callback) {
        const buttons = Array.from(document.getElementsByClassName(buttonClass));
        buttons.forEach(button => {
            if (button.dataset.key && this.layout.dataset.key && button.dataset.key !== this.layout.dataset.key) return;
            button.addEventListener("click", event => {
                if (typeof callback === "function") callback(button);
                event.preventDefault();
                return false;
            });
        });
    }
    registerButtons() {
        this.registerButton("cms-quick-search", button => this.search(button.dataset.search));
        this.registerButton("cms-reset-search", () => this.clearSearch());
        this.registerButton("cms-shuffle", () => this.shuffleLayout());
        this.registerButton("cms-sort", button => {
            const sort = button.dataset.sort || "";
            const sortBy = button.dataset.sortby ? button.dataset.sortby.split(",") : [];
            this.sortLayout(sort, sortBy);
        });
    }
    search(query) {
        if (!query) return this.clearSearch();
        this.isSearching(true);
        this.templateContollers.forEach(tc => tc.searchLayout(query));
    }
}

class BentoLayout extends TotalLayout {
    constructor(layout) {
        super(...arguments);
        this.sets = [];
    }
    isNumber(n) {
        return isFinite(n) && !isNaN(parseFloat(n));
    }
    getNumberRange(stringNumbers) {
        const entries = stringNumbers.trim().replace(/,/g, " ").replace(/\s+/g, ",").split(",");
        const nums = [];
        for (const entry of entries) {
            if (this.isNumber(entry)) {
                nums.push(+entry);
                continue;
            }
            const range = entry.split("-");
            if (!this.isNumber(range[0]) || !this.isNumber(range[1])) continue;
            let low = +range[0];
            const high = +range[1];
            while (low <= high) {
                nums.push(low++);
            }
        }
        return nums.sort((a, b) => a - b);
    }
    buildLayout() {
        const templates = Array.from(document.querySelectorAll(`template[data-for=${this.layout.id}]`));
        this.log.debug("Templates", templates);
        const dummies = {};
        for (const template of templates) {
            const templateItems = this.getNumberRange(template.dataset.items);
            templateItems.map(num => dummies[num] = template.dataset.dummy);
        }
        for (const key of Object.keys(dummies).sort((a, b) => a - b)) {
            this.processTemplate(key, dummies[key], this.layout);
        }
        for (const template of templates) {
            let query = {};
            if (template.getAttribute("data-static") === null) {
                if (template.dataset.query) {
                    query = JSON.parse(template.dataset.query);
                    if (query.scope === "inherit") query = JSON.parse(this.layout.dataset.query);
                } else {
                    query = JSON.parse(this.layout.dataset.query);
                }
                this.log.debug("Bento Query", query);
            }
            const set = new BentoTemplate({
                items: this.getNumberRange(template.dataset.items),
                query: query,
                template: template,
                layout: this.layout
            });
            this.log.debug("populateTemplate for " + template.dataset.items);
            set.populateTemplate().then(() => {
                this.log.debug("populateTemplate complete for " + template.dataset.items);
            });
            this.sets.push(set);
        }
    }
}

class TotalMacro extends TotalCMS {
    constructor(node) {
        super();
        this.node = node;
        this.type = node.getAttribute("type") || "text";
        this.collection = this.getCollection();
        this.id = this.getObjectId();
        this.prop = this.getProperty();
        this.file = this.getFilename();
        if (!(this.collection && this.id && this.prop)) return;
        const settings = node.getAttribute("options") || "";
        this.settings = this.settingsToJson(settings);
    }
    settingsToJson(settings) {
        const json = settings.trim().replace(/(['"])?(\w+)(['"])?\s*:\s*/g, '"$2":').replace(/:\s*(['"])?(\S+?)(['"])?\s*(,|$)/g, ':"$2",').replace(/,$/, "");
        return JSON.parse(`{${json}}`);
    }
    populateMacro() {
        this.fetchCachedAPI(`/collections/${this.collection}/${this.id}`).then(object => {
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
            collection: this.collection,
            id: this.id,
            property: this.prop,
            file: this.file
        });
        this.settings.date = object[this.prop]["uploadDate"];
        image.src = imageWorks.buildQuery(this.settings);
        image.setAttribute("alt", object[this.prop]["alt"]);
        this.node.parentNode.insertBefore(image, this.node);
        this.removeMacro();
    }
    getFilename() {
        return this.node.getAttribute("file");
    }
    getProperty() {
        const property = this.locateValue("prop");
        if (property) return property;
        return this.type;
    }
    getCollection() {
        const collection = this.locateValue("collection");
        if (collection) return collection;
        return this.type;
    }
    locateValue(property) {
        const attribute = this.node.getAttribute(property);
        if (attribute) return attribute;
        const param = this.getUrlParameter(property);
        if (param) return param;
        if (window.totalPreview && window.totalPreview.hasOwnProperty(property)) {
            return window.totalPreview[property];
        }
        return null;
    }
}

class LunrSearch extends TotalCMS {
    constructor(options) {
        super(...arguments);
        const defaults = {
            query: {}
        };
        this.options = Object.assign({}, this.options, defaults, options);
        this.query = new TotalQuery(this.options.query);
        this.key = `lunr${this.query.baseapi}`;
        this.collection = this.query.collection || null;
        this.idx = null;
        if (!this.collection) {
            console.warn("Unable to find collection name inside query for search");
        }
    }
    index() {
        if (this.sessionStorage.isSet(this.key) && this.localStorage.isSet(this.key) && this.localStorage.get(this.expireKey + this.key) > Date.now()) {
            return this.loadCachedIndex();
        }
        this.newIndex();
    }
    loadCachedIndex() {
        this.idx = lunr.Index.load(this.localStorage.get(this.key));
    }
    newIndex() {
        this.query.fetchAllData().then(data => {
            this.idx = lunr(function() {
                this.ref("id");
                for (const property in data[0]) {
                    this.field(property);
                }
                data.forEach(object => this.add(object));
            });
            this.localStorage.set(`${this.expireKey}/${this.key}`, Date.now() + this.expireStorage);
            this.localStorage.set(this.key, this.idx);
            this.sessionStorage.set(this.key, true);
        });
    }
    search(searchString) {
        if (this.idx === null) return;
        const results = this.idx.search(searchString);
        results.push(...this.searchMore(searchString));
        this.log.debug("Search Results", results);
        return results;
    }
    searchMore(searchString) {
        if (this.idx === null) return;
        const titleCase = function(string) {
            return string.charAt(0).toUpperCase() + string.substr(1).toLowerCase();
        };
        if (searchString.length > 2) {
            searchString = searchString.substring(0, searchString.length - 1);
        }
        const results = this.idx.query(q => {
            q.term(searchString, {
                boost: 75
            });
            q.term(searchString.toLowerCase(), {
                boost: 50,
                wildcard: lunr.Query.wildcard.TRAILING | lunr.Query.wildcard.LEADING
            });
            q.term(searchString.toUpperCase(), {
                boost: 50,
                wildcard: lunr.Query.wildcard.TRAILING | lunr.Query.wildcard.LEADING
            });
            q.term(titleCase(searchString), {
                boost: 50,
                wildcard: lunr.Query.wildcard.TRAILING | lunr.Query.wildcard.LEADING
            });
        });
        this.log.debug("Search More Results", results);
        return results;
    }
}

class TotalSearch {
    constructor(node) {
        const defaults = {
            key: "myLayoutKey",
            wait: 500,
            maxTop: 50,
            sticky: true,
            minChars: 1,
            maxItems: 10,
            modal: false
        };
        const options = node.dataset.options ? JSON.parse(node.dataset.options) : {};
        this.options = Object.assign({}, defaults, options);
        this.timeout = null;
        this.wrapper = node;
        this.wait = this.options.wait;
        this.input = this.wrapper.querySelector("input");
        this.key = this.options.key;
        this.maxTop = this.options.maxTop;
        this.sticky = this.options.sticky;
        this.layouts = Array.from(document.querySelectorAll(`.totalcms-grid[data-key=${this.key}]`));
        if (this.layouts.length === 0) {
            console.warn("Unable to find any layouts for search.");
        }
        if (this.options.modal) {
            this.node.classList.add("modal");
            this.initAwesomplete();
        }
    }
    listen() {
        this.input.onkeydown = function(e) {
            this.onkeydown = null;
            const placeholder = this.parentNode.querySelector(".placeholder");
            if (placeholder) {
                placeholder.style.opacity = 0;
            }
        };
        this.input.onkeyup = (e => {
            const enterKey = 13;
            if (e.which == enterKey) {
                this.searchLayouts();
                this.closeSearchModal();
                return;
            }
            clearTimeout(this.timeout);
            this.timeout = setTimeout(() => this.searchLayouts(), this.wait);
        });
        this.wrapper.onclick = (e => this.toggleSearchFocus(e));
    }
    toggleSearchFocus(e) {
        if (this.wrapper.classList.contains("focus")) {
            if (e.target === this.input) return;
            this.wrapper.classList.remove("focus");
            this.closeSearchModal();
            this.input.blur();
        } else {
            this.openSearchModal();
            this.wrapper.classList.add("focus");
        }
    }
    closeSearchModal() {
        if (!this.options.modal) return;
        window.removeEventListener("scroll", this.boundScrollSearch);
        this.scrollContainer.style.removeProperty("top");
        this.scrollContainer.style.removeProperty("width");
        this.awesomplete.evaluate();
    }
    openSearchModal() {
        if (!this.options.modal) return;
        const viewportOffset = this.wrapper.getBoundingClientRect();
        this.scrollContainer = this.input.parentNode;
        this.scrollContainer.style.top = `${viewportOffset.top}px`;
        this.scrollContainer.style.width = `${this.input.offsetWidth}px`;
        this.windowPosition = window.scrollY;
        this.ticking = false;
        this.boundScrollSearch = (() => this.onScroll());
        window.addEventListener("scroll", this.boundScrollSearch);
    }
    onScroll() {
        this.scrollChange = window.scrollY - this.windowPosition;
        this.windowPosition = window.scrollY;
        this.requestTick();
    }
    requestTick() {
        if (this.ticking !== true) {
            window.requestAnimationFrame(() => this.scrollSearch());
            this.ticking = true;
        }
    }
    scrollSearch() {
        const currentTop = parseInt(this.scrollContainer.style.top);
        if (this.sticky && currentTop <= this.maxTop) return;
        const newTop = currentTop - this.scrollChange;
        this.scrollContainer.style.top = `${newTop}px`;
        this.ticking = false;
    }
    initAwesomplete() {
        this.awesomplete = new Awesomplete(this.input, {
            filter: Awesomplete.FILTER_CONTAINS,
            replace: Awesomplete.REPLACE
        });
        this.input.addEventListener("awesomplete-select", event => {
            this.closeSearchModal();
        });
    }
    searchLayouts(query) {
        if (!query) query = this.input.value;
        this.layouts.forEach(layout => {
            const layoutController = layout.cmslayout;
            layoutController.search(query);
        });
    }
}

$(document).ready(function() {
    const bentoGrids = Array.from(document.getElementsByClassName("bento-grid"));
    bentoGrids.forEach(bento => new BentoLayout(bento).buildLayout());
    [ "infinity-grid", "movingbox", "horizon" ].forEach(layoutClass => {
        const layouts = Array.from(document.getElementsByClassName(layoutClass));
        layouts.forEach(layout => new TotalLayout(layout).buildLayout());
    });
    const searchInputs = Array.from(document.getElementsByClassName("totalcms-search"));
    searchInputs.forEach(search => new TotalSearch(search).listen());
    const macros = Array.from(document.getElementsByTagName("cms"));
    macros.forEach(macro => new TotalMacro(macro).populateMacro());
});