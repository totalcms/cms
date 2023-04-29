const copy               = require('esbuild-plugin-copy');
const esbuild            = require('esbuild');
const { clean }          = require('esbuild-plugin-clean');
const { globPlugin }     = require('esbuild-plugin-glob');
const { sassPlugin }     = require("esbuild-sass-plugin");
const { createImporter } = require("sass-extended-importer");

esbuild.build({
    entryPoints : [
        "javascript/admin.ts",
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
    outdir      : 'dist',
    external    : [
        "choices.js",
    ],
    plugins     : [
        globPlugin(),
        clean({
            patterns: ['dist'],
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
    outdir      : 'dist',
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
            ],
            importer: createImporter(),
        }),
    ],
}).catch(() => process.exit(1));
