import { defineConfig } from 'vite';
import { resolve } from 'path';

// Vite scaffold for Total CMS Site Builder projects.
//
// Source files live here under `frontend/css/` and `frontend/js/`. The
// build emits hashed assets + a manifest.json into `../public/assets/`
// where the web server picks them up. T3's `cms.builder.css()` and
// `cms.builder.js()` Twig helpers resolve manifest entries by their
// input path automatically.
//
// Adjust `outDir` if your docroot isn't `../public`.
export default defineConfig({
	build: {
		outDir: resolve(__dirname, '../public/assets'),
		emptyOutDir: true,

		// Flatten output: hashed files go directly into outDir, not into a
		// nested `assets/` subdirectory. Otherwise paths would double up as
		// `/assets/assets/style-<hash>.css` once T3 prepends its base path.
		assetsDir: '',

		// Vite 5+ writes the manifest to `<outDir>/.vite/manifest.json` by
		// default. Override to keep it at `<outDir>/manifest.json` so T3's
		// asset helpers find it without any extra config.
		manifest: 'manifest.json',

		rollupOptions: {
			input: {
				style: resolve(__dirname, 'css/style.css'),
				app: resolve(__dirname, 'js/app.js'),
			},
		},
	},
});
