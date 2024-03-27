const copy               = require('esbuild-plugin-copy');
const esbuild            = require('esbuild');
const { clean }          = require('esbuild-plugin-clean');
const { globPlugin }     = require('esbuild-plugin-glob');
const { sassPlugin }     = require("esbuild-sass-plugin");
const { createImporter } = require("sass-extended-importer");

esbuild.build({
    entryPoints : [
        "javascript/admin.js",
    ],
    format    : "esm",
    platform  : "browser",
    bundle    : true,
    // minify    : true,
    metafile  : true,
    splitting : true,
    sourcemap : true,
    keepNames : true,
    target    : "esnext",
    // watch       : true,
    // incremental : true,
    outdir      : 'public/tcms-assets',
    external    : [],
    plugins     : [
        globPlugin(),
        clean({
            patterns: ['public/tcms-assets'],
        }),
        // Copy in the static external libraries
        copy.default({assets: {
            from : "node_modules/choices.js/public/assets/scripts/choices.js" ,
            to   : "choices"
        }}),
        copy.default({assets: {
            from : "node_modules/froala-editor/js/**" ,
            to   : "froala"
        }}),
        copy.default({assets: {
            from : "node_modules/froala-editor/css/**",
            to   : "froala"
        }}),
        copy.default({assets: {
            from : "node_modules/codemirror/lib/**",
            to   : "codemirror"
        }}),
        copy.default({assets: {
            from : "node_modules/codemirror/mode/xml/**",
            to   : "codemirror"
        }}),
        copy.default({assets: {
            from : "node_modules/dompurify/dist/purify.min.js",
            to   : "dompurify"
        }}),
        copy.default({assets: {
            from : "node_modules/dropzone/dist/dropzone-min.js",
            to   : "dropzone"
        }}),
        copy.default({assets: {
            from : [
                "node_modules/swagger-ui-dist/swagger-ui-bundle.js",
                "node_modules/swagger-ui-dist/swagger-ui-standalone-preset.js",
                "node_modules/swagger-ui-dist/swagger-ui.css",
                "node_modules/swagger-ui-dist/swagger-ui-bundle.js.map",
                "node_modules/swagger-ui-dist/swagger-ui-standalone-preset.js.map",
                "node_modules/swagger-ui-dist/swagger-ui.css.map",
            ],
            to   : "swagger-ui"
        }}),
    ],
}).catch(() => process.exit(1));

esbuild.build({
    entryPoints : [
        "styles/*.scss",
    ],
    bundle    : true,
    minify    : true,
    metafile  : true,
    sourcemap : true,
    outdir      : 'public/tcms-assets',
    external    : [
    ],
    plugins     : [
        globPlugin(),
        // Sass includes
        sassPlugin({
            loadPaths: [
                "node_modules/choices.js/src/styles/",
                // "node_modules/froala-editor/css/",
                "node_modules/codemirror/lib/",
                "node_modules/dropzone/src/"
            ],
            importer: createImporter(),
        }),
    ],
}).catch(() => process.exit(1));
