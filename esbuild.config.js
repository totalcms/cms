const esbuild            = require('esbuild');
const { clean }          = require('esbuild-plugin-clean');
const { globPlugin }     = require('esbuild-plugin-glob');
const { sassPlugin }     = require("esbuild-sass-plugin");
const { createImporter } = require("sass-extended-importer");

esbuild.build({
    entryPoints : [
		"javascript/admin.js",
		"javascript/gallery.js",
		"javascript/imageworks-builder.js",
		"javascript/totalcms.js",
		"javascript/swagger.js",
	],
	format    : "esm",
	platform  : "browser",
	bundle    : true,
	minify    : true,
	metafile  : true,
	splitting : true,
	sourcemap : true,
	keepNames : true,
	target    : "esnext",
	outdir    : 'public/assets',
	external  : [],
	plugins   : [
		clean({
            patterns: ['public/assets'],
        }),
    ],
}).catch(() => process.exit(1));

esbuild.build({
    entryPoints : [
        "css/*.scss",
    ],
    bundle    : true,
    minify    : true,
    metafile  : true,
    sourcemap : true,
    outdir      : 'public/assets',
    external    : [
    ],
    plugins     : [
        globPlugin(),
        // Sass includes
        sassPlugin({
            loadPaths: [
                "node_modules/choices.js/src/styles/",
                "node_modules/froala-editor/css/",
                "node_modules/codemirror/lib/",
                "node_modules/dropzone/src/"
            ],
            importer: createImporter(),
        }),
    ],
}).catch(() => process.exit(1));
