const copy               = require('esbuild-plugin-copy');
const esbuild            = require('esbuild');
const { clean }          = require('esbuild-plugin-clean');
const { globPlugin }     = require('esbuild-plugin-glob');
const { sassPlugin }     = require("esbuild-sass-plugin");
const { createImporter } = require("sass-extended-importer");

esbuild.build({
    entryPoints : [
        "javascript/totalcms-admin.ts",
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
    external    : ["awesomplete"],
    plugins     : [
        clean({
            patterns: ['dist'],
        }),
        globPlugin(),
        // Copy in the static external libraries
        copy.default({assets: {
            from: [
                "node_modules/awesomplete/awesomplete.min.js*",
            ],
            to: [ "" ]
        }})
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
    external    : ["awesomplete"],
    plugins     : [
        globPlugin(),
        // Sass includes
        sassPlugin({
            loadPaths: [
                // "node_modules/foundation-sites/scss/",
                // "node_modules/motion-ui/src/",
            ],
            importer: createImporter(),
        }),
    ],
}).catch(() => process.exit(1));
