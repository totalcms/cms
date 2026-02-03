const esbuild            = require('esbuild');
const copy               = require('esbuild-plugin-copy');
const { clean }          = require('esbuild-plugin-clean');
const { globPlugin }     = require('esbuild-plugin-glob');
const { sassPlugin }     = require("esbuild-sass-plugin");
const { createImporter } = require("sass-extended-importer");

// Production mode optimizations
const isProduction = process.env.PRODUCTION === '1';
// Always generate sourcemaps - for production they get uploaded to Sentry then deleted
const sourcemap = true;
const drop = isProduction ? ['console', 'debugger'] : [];

if (isProduction) {
    console.log('Building in production mode (sourcemaps for Sentry, no console/debugger)');
}

esbuild.build({
    entryPoints : [
		"javascript/admin.js",
		"javascript/gallery.js",
		"javascript/content.js",
		"javascript/filelinks.js",
		"javascript/imageworks-builder.js",
		"javascript/image-batcher.js",
		"javascript/totalcms.js",
		"javascript/swagger.js",
		"javascript/mailto-decoder.js",
		"javascript/docs-highlight.js",
	],
	format        : "esm",
	platform      : "browser",
	bundle        : true,
	minify        : true,
	metafile      : true,
	splitting     : true,
	sourcemap     : sourcemap,
	drop          : drop,
	legalComments : 'external',
	keepNames     : true,
	target        : "esnext",
	outdir        : 'public/assets',
	external      : [],
	plugins   : [
		clean({
            patterns: ['public/assets'],
        }),
    ],
}).catch(() => process.exit(1));

esbuild.build({
    entryPoints : [
        "css/*.scss",
		"css/icons.css",
    ],
    bundle        : true,
    minify        : true,
    metafile      : true,
    sourcemap     : sourcemap,
    legalComments : 'external',
    outdir        : 'public/assets',
    external    : [
		"gallery/*",
		"fonts/*",
    ],
	loader: {
		".woff2" : "file",
		".woff"  : "file",
		".gif"   : "file",
		".ttf"   : "file",
		".svg"   : "file",
	},
    plugins     : [
        globPlugin(),
		copy.default({assets: {
            from : "node_modules/lightgallery/fonts/*",
            to   : "gallery"
        }}),
		copy.default({assets: {
            from : "node_modules/lightgallery/images/*",
            to   : "gallery"
        }}),
        // Sass includes
        sassPlugin({
            loadPaths: [
                "node_modules/froala-editor/css/",
                "node_modules/codemirror/lib/",
                "node_modules/codemirror/theme/",
                "node_modules/codemirror/addon/",
                "node_modules/dropzone/src/",
				// "node_modules/lightgallery/scss/",
				"css/lightgallery/",
                "node_modules/gridjs/dist/theme/",
                "node_modules/highlight.js/styles/"
            ],
            importer: createImporter(),
        }),
    ],
}).catch(() => process.exit(1));
