import TotalField from "./totalfield.js";

import CodeMirror from "codemirror";
import "codemirror/mode/xml/xml";
import "codemirror/mode/twig/twig";
import DOMPurify from 'dompurify';

window.CodeMirror = CodeMirror
window.DOMPurify = DOMPurify

import FroalaEditor from "froala-editor";
import "froala-editor/js/plugins/align.min.js";
import "froala-editor/js/plugins/char_counter.min.js";
import "froala-editor/js/plugins/code_beautifier.min.js";
import "froala-editor/js/plugins/code_view.min.js";
import "froala-editor/js/plugins/colors.min.js";
// import "froala-editor/js/plugins/cryptojs.min.js";
import "froala-editor/js/plugins/draggable.min.js";
// import "froala-editor/js/plugins/edit_in_popup.min.js";
import "froala-editor/js/plugins/emoticons.min.js";
import "froala-editor/js/plugins/entities.min.js";
import "froala-editor/js/plugins/file.min.js";
import "froala-editor/js/plugins/files_manager.min.js";
import "froala-editor/js/plugins/font_family.min.js";
import "froala-editor/js/plugins/font_size.min.js";
// import "froala-editor/js/plugins/forms.min.js";
import "froala-editor/js/plugins/fullscreen.min.js";
// import "froala-editor/js/plugins/help.min.js";
import "froala-editor/js/plugins/image.min.js";
// import "froala-editor/js/plugins/image_manager.min.js";
import "froala-editor/js/plugins/inline_class.min.js";
import "froala-editor/js/plugins/inline_style.min.js";
import "froala-editor/js/plugins/line_breaker.min.js";
import "froala-editor/js/plugins/line_height.min.js";
import "froala-editor/js/plugins/link.min.js";
import "froala-editor/js/plugins/lists.min.js";
// import "froala-editor/js/plugins/markdown.min.js";
import "froala-editor/js/plugins/paragraph_format.min.js";
import "froala-editor/js/plugins/paragraph_style.min.js";
// import "froala-editor/js/plugins/print.min.js";
import "froala-editor/js/plugins/quick_insert.min.js";
import "froala-editor/js/plugins/quote.min.js";
// import "froala-editor/js/plugins/save.min.js";
import "froala-editor/js/plugins/special_characters.min.js";
import "froala-editor/js/plugins/table.min.js";
// import "froala-editor/js/plugins/track_changes.min.js";
import "froala-editor/js/plugins/trim_video.min.js";
import "froala-editor/js/plugins/url.min.js";
import "froala-editor/js/plugins/video.min.js";
import "froala-editor/js/plugins/word_counter.min.js";
import "froala-editor/js/plugins/word_paste.min.js";
import "froala-editor/js/third_party/embedly.min.js";
// import "froala-editor/js/third_party/font_awesome.min.js";
import "froala-editor/js/third_party/image_tui.min.js";
// import "froala-editor/js/third_party/showdown.min.js";
// import "froala-editor/js/third_party/spell_checker.min.js";

// TODO: how to handle localization for Froala?
// import "froala-editor/js/languages/de.js";
// import "froala-editor/js/languages/es.js";

//-----------------------------------------------
// Total CMS Styled Text Field
//-----------------------------------------------
export default class StyledTextField extends TotalField {

    // TODO: if form ID changes, need to update upload URLs

    constructor(container, options) {
        super(container, options);

        // Skip if Froala is already initialized on this input
        if (this.input.dataset.froalaInitialized) {
            return;
        }
        this.input.dataset.froalaInitialized = 'true';

        // get final options... defaultConfig() -> global window.totalcms options -> options from arguments
        this.options = Object.assign({}, this.defaultConfig(), this.options);

        this.froala = new FroalaEditor(this.input, this.options);
    }


    setValue(value) {
        this.input.value = value;
		this.froala.html.set(value);
		this.changed();
    }

    getValue() {
        // Check if Froala is initialized before trying to get the value
        if (this.froala && this.froala.html) {
            let content = this.froala.html.get();
            content = content.replace(/\%25/g, "%"); // fix for double encoding of many characters such as colon
            return content;
        }
        // Fall back to input value if Froala is not ready
        return this.input.value;
    }

    uploadAPI() {
        if (!this.form) return null;
        const collection = this.form.collection;
        const id         = this.form.getId() ?? '';
        const property   = this.property;
        return this.api.buildApiQuery(`/upload/${collection}/${id}/${property}`);
    }

    updateUploadURLs() {
        this.froala.opts.imageUploadURL = this.uploadAPI();
        this.froala.opts.fileUploadURL  = this.uploadAPI();
        this.froala.opts.videoUploadURL = this.uploadAPI();
    }

    charCountExceeded() {
        // $(this.input).closest("fieldset").find(".fr-counter").addClass("exceeded");
    }

    deleteFileFromServer(url) {
        if (this.options.confirmDelete !== true || !url) return;

        if (confirm("Are you sure you want to delete this image?")) {
            const collection = this.form.collection;
            const id         = this.form.getId();
            const property   = this.property;
            const name       = url.split("?")[0].split("/").pop();
            const api        = `/upload/${collection}/${id}/${property}/${name}`;
            console.log("Deleting file from server", api);
            this.api.postAPI(api, {}, "DELETE");
        }
    }

    defaultConfig() {
        const toolbar = {
			moreText: { buttons: [
				'bold', 'italic', 'underline',
			] },
			moreParagraph: { buttons: [
				'formatUL', 'formatOL', 'paragraphFormat', 'alignLeft', 'alignCenter', 'alignRight', 'alignJustify'
			]},
			moreRich: { buttons: [
				'insertLink', 'insertImage'
			] },
			moreMisc: { buttons: [
				'undo', 'redo', 'clearFormatting', 'html',
			], align: 'right', buttonsVisible: 4 }
		};
        const quickInsertTags = ["image", "video", "table", "ul", "ol", "hr"];
        const codeMirrorOptions = {
            indentWithTabs : true,
            lineNumbers    : true,
            lineWrapping   : true,
            readOnly       : false,
            mode           : "text/html",
            tabMode        : "indent",
            tabSize        : 2
        };
        const paragraphFormat = {
            N   : "Normal",
            H2  : "Heading 2",
            H3  : "Heading 3",
            H4  : "Heading 4",
            PRE : "Code"
        };
        const megabyte = 1024 * 1024;
        const height   = this.input.dataset.height > 0 ?  this.input.dataset.height : null;
        return {
            key                        : "zEG4iH4B11D9B5B4F4g1JWSDBCQG1ZGDf1C1d2JXDAAOZWJhE5B4E4C3F2H3C11A4C4E5==",
            attribution                : false,
            height                     : height,
            heightMin                  : 200,
            heightMax                  : 800,
            // fontSizeDefaultSelection   : '1',
            // fontSizeUnit               : 'rem',
            // fontFamilyDefaultSelection : 'inherit',
            keepFormatOnDelete : true,
            charCounterCount   : false,
            charCounterMax     : this.input.dataset.maxcount,
            // colorsText         : colors,
            // colorsBackground   : colors,
            language           : this.api.options.locale,
            linkAutoPrefix     : "https://",
            toolbarInline      : false,
            tooltips           : true,
            shortcutsHint      : false,
            // fontSize           : fontSizes,
            // videoEditButtons   : videoEditButtons,
            videoMaxSize       : megabyte * 1024,
            fileMaxSize        : megabyte * 1024,
            imageMaxSize       : megabyte * 5,
            fileUpload         : true,
            imageUpload        : true,
            videoUpload        : true,
            imageUploadParam   : "image",
            fileUploadParam    : "file",
            videoUploadParam   : "video",
            fileUploadURL      : this.uploadAPI(),
            videoUploadURL     : this.uploadAPI(),
            imageUploadURL     : this.uploadAPI(),
            imageUploadParams  : { w:1500 },
            imageInsertButtons : ['imageBack', '|', 'imageUpload', 'imageByURL'],
            // videoUploadParams        : {},
            // fileUploadParams         : {},
            // imageUploadMethod        : 'POST',
            // imageManagerDeleteParams : {},
            // imageManagerLoadMethod   : 'GET',
            // imageManagerLoadURL   : this.uploadAPI("image"),
            // imageManagerDeleteURL : this.uploadAPI("image"),
            // imageManagerDeleteMethod : 'DELETE',
            // imageDefaultWidth      : 0,
            // imageResizeWithPercent : true, // BUG https://github.com/froala/wysiwyg-editor/issues/4205
            imageRoundPercent      : true,
            // imageStyles            : imageStyles,
            DOMPurify              : window.DOMPurify,
            codeMirror             : window.CodeMirror,
            codeMirrorOptions      : codeMirrorOptions,
            alwaysVisible          : false,
            saveInterval           : 0,
            pastePlain             : true,
            placeholderText        : this.input.getAttribute("placeholder"),
            // requestHeaders         : {},
            toolbarButtons     : toolbar,
            toolbarButtonsMD   : null,
            toolbarButtonsSM   : null,
            toolbarButtonsXS   : null,
            toolbarSticky      : false,
            quickInsertButtons : false,
            quickInsertTags    : quickInsertTags,
            paragraphFormat    : paragraphFormat,
            enter              : FroalaEditor.ENTER_P,
            wordCounterCount   : false,
            htmlRemoveTags     : ["script"],
            htmlAllowedTags    : ['a', 'abbr', 'address', 'area', 'article', 'aside', 'audio', 'b', 'base', 'bdi', 'bdo', 'blockquote', 'body', 'br', 'button', 'canvas', 'caption', 'cite', 'code', 'col', 'colgroup', 'datalist', 'dd', 'del', 'details', 'dfn', 'dialog', 'div', 'dl', 'dt', 'em', 'embed', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'head', 'header', 'hgroup', 'hr', 'html', 'i', 'iframe', 'img', 'input', 'ins', 'kbd', 'keygen', 'label', 'legend', 'li', 'link', 'main', 'map', 'mark', 'menu', 'menuitem', 'meta', 'meter', 'nav', 'noscript', 'object', 'ol', 'optgroup', 'option', 'output', 'p', 'param', 'pre', 'progress', 'queue', 'rp', 'rt', 'ruby', 's', 'samp', 'section', 'select', 'small', 'source', 'span', 'strike', 'strong', 'sub', 'summary', 'sup', 'table', 'tbody', 'td', 'textarea', 'tfoot', 'th', 'thead', 'time', 'title', 'tr', 'track', 'u', 'ul', 'var', 'video', 'wbr'],
            htmlAllowedAttrs   : ['accept', 'accept-charset', 'accesskey', 'action', 'align', 'alt', 'async', 'autocomplete', 'autofocus', 'autoplay', 'autosave', 'background', 'bgcolor', 'border', 'charset', 'cellpadding', 'cellspacing', 'checked', 'cite', 'class', 'color', 'cols', 'colspan', 'content', 'contenteditable', 'contextmenu', 'controls', 'coords', 'data', 'data-.*', 'datetime', 'default', 'defer', 'dir', 'dirname', 'disabled', 'download', 'draggable', 'dropzone', 'enctype', 'for', 'form', 'formaction', 'headers', 'height', 'hidden', 'high', 'href', 'hreflang', 'http-equiv', 'icon', 'id', 'ismap', 'itemprop', 'keytype', 'kind', 'label', 'lang', 'language', 'list', 'loop', 'low', 'manifest', 'max', 'maxlength', 'media', 'method', 'min', 'multiple', 'name', 'novalidate', 'open', 'optimum', 'pattern', 'ping', 'placeholder', 'poster', 'preload', 'radiogroup', 'readonly', 'rel', 'required', 'reversed', 'rows', 'rowspan', 'sandbox', 'scope', 'scoped', 'scrolling', 'seamless', 'selected', 'shape', 'size', 'sizes', 'span', 'src', 'srcdoc', 'srclang', 'srcset', 'start', 'step', 'summary', 'spellcheck', 'style', 'tabindex', 'target', 'title', 'type', 'translate', 'usemap', 'value', 'valign', 'wrap'],
            htmlAllowedEmptyTags: ['i'],
            htmlExecuteScripts  : false,
            htmlSimpleAmpersand : false,
            htmlUntouched       : false,
            confirmDelete      : true,
            events             : {
                'contentChanged'     : ()    => this.changed(),
                'image.removed'      : img   => this.deleteFileFromServer(img[0].src),
                'image.beforeUpload' : ()    => this.updateUploadURLs(),
                'file.unlink'        : file  => this.deleteFileFromServer(file.href),
                'video.removed'      : video => this.deleteFileFromServer(video[0].src),
                'codeView.update'    : function() {
                    // Ensure CodeMirror is properly refreshed when entering code view
                    setTimeout(() => {
                        if (this.codeView.isActive()) {
                            const codeMirror = this.codeView.get();
                            if (codeMirror && codeMirror.refresh) {
                                codeMirror.refresh();
                                // Make sure CodeMirror is not read-only
                                codeMirror.setOption('readOnly', false);
                            }
                        }
                    }, 100);
                },
			}
        };
    }

    schema() {
        return {
            "type"  : "string",
            "field" : "styledtext"
        };
    }
}

