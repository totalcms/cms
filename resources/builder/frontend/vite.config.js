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
		manifest: true,
		rollupOptions: {
			input: {
				style: resolve(__dirname, 'css/style.css'),
				app: resolve(__dirname, 'js/app.js'),
			},
		},
	},
});
