import { defineConfig } from 'vite';

// Builds css/ and js/ into public/assets/, with hashed filenames and a manifest
// that {{ cms.builder.css() }} and {{ cms.builder.js() }} read. See FRONTEND.md.
// Folders in the project root that the dev server shouldn't watch. Keep these
// tied to the root: a pattern like '**/builder/**' also matches the path above
// the project, so a site inside a folder named builder would watch nothing.
const NOT_SOURCE = ['vendor', 'cache', 'tmp', 'logs', 'tcms-data', 'builder', 'extensions', 'public']

export default defineConfig({
	// Relative URLs, so built CSS finds its fonts and images in public/assets/.
	base: './',

	// Keep this off. Vite's default static folder is public/, which is this
	// project's web root, so it would copy index.php and .htaccess into the build.
	publicDir: false,

	// Fonts and images: keep them in their own folder, such as fonts/, and use them
	// from your CSS with url('../fonts/name.woff2'). Vite finds those references and
	// copies the files into the build by itself, so there's nothing to configure here.
	// Files that CSS doesn't reference are not copied. For those, such as images used
	// in templates, set publicDir to a folder such as 'static'.

	server: {
		// The project root holds far more than source files. Don't watch it all.
		watch: {
			ignored: NOT_SOURCE.map((dir) => `${import.meta.dirname}/${dir}/**`),
		},
	},

	build: {
		// Leave modern CSS as written. Otherwise Vite rewrites features such as
		// light-dark() for older browsers, which can change how a site behaves.
		cssTarget: 'esnext',
		outDir: 'public/assets',
		emptyOutDir: true,
		assetsDir: '',
		manifest: 'manifest.json',
		rollupOptions: {
			input: {
				style: 'css/style.css',
				app: 'js/app.js',
			},
		},
	},
});
