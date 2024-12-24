import TotalField from "./totalfield.js";

import "codemirror/lib/codemirror.js";
import "codemirror/mode/xml/xml.js";
import "codemirror/mode/twig/twig.js";
import "dompurify/dist/purify.min.js";

import FroalaEditor from "froala-editor";
import "froala-editor/js/plugins/align.min.js";
import "froala-editor/js/plugins/char_counter.min.js";
import "froala-editor/js/plugins/code_beautifier.min.js";
import "froala-editor/js/plugins/code_view.min.js";
import "froala-editor/js/plugins/colors.min.js";
// import "froala-editor/js/plugins/cryptojs.min.js";
import "froala-editor/js/plugins/draggable.min.js";
// import "froala-editor/js/plugins/edit_in_popup.min.js";
// import "froala-editor/js/plugins/emoticons.min.js";
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
// import "froala-editor/js/plugins/special_characters.min.js";
import "froala-editor/js/plugins/table.min.js";
import "froala-editor/js/plugins/track_changes.min.js";
import "froala-editor/js/plugins/trim_video.min.js";
import "froala-editor/js/plugins/url.min.js";
import "froala-editor/js/plugins/video.min.js";
// import "froala-editor/js/plugins/word_counter.min.js";
// import "froala-editor/js/plugins/word_paste.min.js";
// import "froala-editor/js/third_party/embedly.min.js";
// import "froala-editor/js/third_party/font_awesome.min.js";
// import "froala-editor/js/third_party/image_tui.min.js";
// import "froala-editor/js/third_party/showdown.min.js";
// import "froala-editor/js/third_party/spell_checker.min.js";

// TODO: how to handle localization for Froala?
// import "froala-editor/js/languages/de.js";
// import "froala-editor/js/languages/es.js";

//-----------------------------------------------
// Total CMS Styled Text Field
//-----------------------------------------------
export default class StyledTextField extends TotalField {

    constructor(container, options) {
        super(container, options);

        // get final options... defaultConfig() -> global window.totalcms options -> options from arguments
        this.options = Object.assign({}, this.defaultConfig(), this.options);

        this.initFroala();
    }

    initFroala() {
		this.froala = new FroalaEditor(this.input, this.options);
        // .on("froalaEditor.charCounter.exceeded", (e, editor) => this.charCountExceeded())
        // .on("froalaEditor.image.beforeUpload", (e, editor, images) => this.updateUploadURLs(editor))
        // .on("froalaEditor.file.beforeUpload",  (e, editor, files)  => this.updateUploadURLs(editor))
        // .on("froalaEditor.video.beforeUpload", (e, editor, videos) => this.updateUploadURLs(editor));
    }

    setValue(value) {
        this.input.value = value;
		this.froala.html.set(value);
		this.changed();
    }

    getValue() {
        return this.froala.html.get();
    }

    uploadAPI(type) {
        // if (!this.form) return null;
        // const collection = this.form.collection;
        // const id         = this.form.id;
        // const field      = this.input.name;
        // return this.api.buildUrlQuery(`/upload/${collection}/${id}/${field}/${type}`);
    }

    updateUploadURLs(editor) {
        // Cannot upload unless the form has an ID set
        // if (!this.form.id) {
        //     console.warn("Unable to upload. Could not find object ID.");
        //     return false;
        // }
        // Update the Froala upload URL
        // editor.opts.imageUploadURL = this.uploadAPI("image");
        // editor.opts.fileUploadURL  = this.uploadAPI("file");
        // editor.opts.videoUploadURL = this.uploadAPI("video");
    }

    charCountExceeded() {
        // $(this.input).closest("fieldset").find(".fr-counter").addClass("exceeded");
    }

    defaultConfig() {
        const toolbar = [
            "bold", "italic", "insertLink",
            "|", "alignLeft", "alignCenter", "alignRight",
            "|", "formatOL", "formatUL",
            "|", "clearFormatting", "html",
        ];
        const colors = [
            "#61BD6D", "#1ABC9C", "#54ACD2",
            "#2C82C9", "#9365B8", "#475577",
            "#CCCCCC", "#41A85F", "#00A885",
            "#3D8EB9", "#2969B0", "#553982",
            "#28324E", "#000000", "#F7DA64",
            "#FBA026", "#EB6B56", "#E25041",
            "#A38F84", "#EFEFEF", "#FFFFFF",
            "#FAC51C", "#F37934", "#D14841",
            "#B8312F", "#7C706B", "#D1D5D8",
            "REMOVE"
        ];
        const fontSizes = [
            "8", "9", "10", "11", "12", "14",
            "18", "24", "30", "36", "48",
            "60", "72", "96"
        ];
        const videoEditButtons = [
            "videoReplace", "videoRemove", "|",
            "videoDisplay", "videoAlign"
        ];
        const quickInsertTags = [
            "image", "video", "table",
            "ul", "ol", "hr"
        ];
        const imageStyles = {
            "fr-rounded"    : "Rounded",
            "fr-bordered"   : "Bordered",
            "fr-shadow"     : "Shadow",
            "fr-full-width" : "Full Width"
        };
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
            H1  : "Heading 1",
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
            keepFormatOnDelete         : true,
            charCounterCount           : false,
            charCounterMax             : this.input.dataset.maxcount,
            colorsText                 : colors,
            colorsBackground           : colors,
            language                   : this.api.options.locale,
            linkAutoPrefix             : "https://",
            toolbarInline              : false,
            tooltips                   : true,
            shortcutsHint              : false,
            fontSize                   : fontSizes,
            videoEditButtons           : videoEditButtons,
            videoMaxSize               : megabyte * 1024,
            fileMaxSize                : megabyte * 1024,
            imageMaxSize               : megabyte * 5,
            imageUploadParam           : "image",
            fileUploadParam            : "file",
            videoUploadParam           : "video",
            // These URLs will need to be customized per instance since
            // The API URL will need the collection, id and property fields
            fileUploadURL         : this.uploadAPI("file"),
            videoUploadURL        : this.uploadAPI("video"),
            imageUploadURL        : this.uploadAPI("image"),
            imageManagerLoadURL   : this.uploadAPI("image"),
            imageManagerDeleteURL : this.uploadAPI("image"),
            // imageUploadParams     : { w:2500, h:1200, fit:"max" },
            // imageInsertButtons    : ['imageBack', '|', 'imageUpload', 'imageByURL'],
            // videoUploadParams        : {},
            // fileUploadParams         : {},
            // imageManagerDeleteParams : {},
            // imageManagerLoadMethod   : 'GET',
            // imageUploadMethod        : 'POST',
            // imageManagerDeleteMethod : 'DELETE',
            imageDefaultWidth      : 0,
            imageResizeWithPercent : true,
            imageRoundPercent      : true,
            imageStyles            : imageStyles,
            codeMirror             : window.CodeMirror,
            codeMirrorOptions      : codeMirrorOptions,
            alwaysVisible          : false,
            saveInterval           : 0,
            pastePlain             : true,
            placeholderText        : this.input.getAttribute("placeholder"),
            // requestHeaders         : {},
            toolbarButtons     : toolbar,
            toolbarButtonsMD   : toolbar,
            toolbarButtonsSM   : toolbar,
            toolbarButtonsXS   : toolbar,
            toolbarSticky      : false,
            quickInsertButtons : false,
            quickInsertTags    : quickInsertTags,
            paragraphFormat    : paragraphFormat,
            enter              : FroalaEditor.ENTER_P,
            htmlRemoveTags     : ["script"],
            events             : {
				'contentChanged': () => this.changed()
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

