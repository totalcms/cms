/*
<script type="text/javascript" src="{{ totalcms.api }}/assets/codemirror/codemirror.js"></script>
<script type="text/javascript" src="{{ totalcms.api }}/assets/codemirror/xml.js"></script>
<script type="text/javascript" src="{{ totalcms.api }}/assets/dompurify/purify.min.js"></script>
<script type="text/javascript" src="{{ totalcms.api }}/assets/froala/froala_editor.min.js"></script>
<script type="text/javascript" src="{{ totalcms.api }}/assets/froala/plugins/align.min.js"></script>
<script type="text/javascript" src="{{ totalcms.api }}/assets/froala/plugins/code_beautifier.min.js"></script>
<script type="text/javascript" src="{{ totalcms.api }}/assets/froala/plugins/code_view.min.js"></script>
<script type="text/javascript" src="{{ totalcms.api }}/assets/froala/plugins/draggable.min.js"></script>
<script type="text/javascript" src="{{ totalcms.api }}/assets/froala/plugins/image.min.js"></script>
<script type="text/javascript" src="{{ totalcms.api }}/assets/froala/plugins/image_manager.min.js"></script>
<script type="text/javascript" src="{{ totalcms.api }}/assets/froala/plugins/link.min.js"></script>
<script type="text/javascript" src="{{ totalcms.api }}/assets/froala/plugins/lists.min.js"></script>
<script type="text/javascript" src="{{ totalcms.api }}/assets/froala/plugins/paragraph_format.min.js"></script>
<script type="text/javascript" src="{{ totalcms.api }}/assets/froala/plugins/paragraph_style.min.js"></script>
<script type="text/javascript" src="{{ totalcms.api }}/assets/froala/plugins/table.min.js"></script>
<script type="text/javascript" src="{{ totalcms.api }}/assets/froala/plugins/video.min.js"></script>
<script type="text/javascript" src="{{ totalcms.api }}/assets/froala/plugins/url.min.js"></script>
<script type="text/javascript" src="{{ totalcms.api }}/assets/froala/plugins/entities.min.js"></script>
*/

import TotalField from "./totalfield.js";

// import "codemirror/lib/codemirror.js";
// import "codemirror/mode/xml/xml.js";
// import "dompurify/dist/purify.min.js";

// import FroalaEditor from "froala-editor";
// import "froala-editor/js/plugins/align.min.js";
// import "froala-editor/js/plugins/code_beautifier.min.js";
// import "froala-editor/js/plugins/code_view.min.js";

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
		this.froala = new FroalaEditor(this.input, {
			key         : "zEG4iH4B11D9B5B4F4g1JWSDBCQG1ZGDf1C1d2JXDAAOZWJhE5B4E4C3F2H3C11A4C4E5==",
			attribution : false,
			heightMin   : 200,
			heightMax   : 800,
			toolbarButtons: ['bold', 'italic', 'html'],
			events: {
				'contentChanged': () => this.changed()
			}
		});
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
        //! convert to native JS... lazy asshole
        // $(this.input).closest("fieldset").find(".fr-counter").addClass("exceeded");
    }

    defaultConfig() {
        const toolbar = [
            "bold", "italic", "|",
            "insertLink", "insertImage"
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
            keepFormatOnDelete : true,
            charCounterCount   : false,
            charCounterMax     : this.input.dataset.maxcount,
            colorsText         : colors,
            colorsBackground   : colors,
            language           : this.api.options.locale,
            linkAutoPrefix     : "https://",
            toolbarInline      : false,
            tooltips           : true,
            shortcutsHint      : false,
            fontSize           : fontSizes,
            videoEditButtons   : videoEditButtons,
            videoMaxSize       : megabyte * 1024,
            fileMaxSize        : megabyte * 1024,
            imageMaxSize       : megabyte * 5,
            imageUploadParam   : "image",
            fileUploadParam    : "file",
            videoUploadParam   : "video",
            // These URLs will need to be customized per instance since
            // The API URL will need the collection, id and property fields
            fileUploadURL         : this.uploadAPI("file"),
            videoUploadURL        : this.uploadAPI("video"),
            imageUploadURL        : this.uploadAPI("image"),
            imageManagerLoadURL   : this.uploadAPI("image"),
            imageManagerDeleteURL : this.uploadAPI("image"),
            imageUploadParams     : { w:2500, h:1000, fit:"max" },
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
            codeMirror             : true,
            codeMirrorOptions      : codeMirrorOptions,
            alwaysVisible          : false,
            saveInterval           : 0,
            pastePlain             : true,
            placeholderText        : this.input.getAttribute("placeholder"),
            // requestHeaders         : {},
            toolbarButtons         : toolbar,
            toolbarButtonsMD       : toolbar,
            toolbarButtonsSM       : toolbar,
            toolbarButtonsXS       : toolbar,
            toolbarSticky          : false,
            quickInsertButtons     : false,
            quickInsertTags        : quickInsertTags,
            paragraphFormat        : paragraphFormat,
            enter                  : FroalaEditor.ENTER_P,
            htmlRemoveTags         : ["script"],
            heightMax              : 1000,
            height                 : height
        };
    }

    schema() {
        return {
            "type"  : "string",
            "field" : "styledtext"
        };
    }
}

