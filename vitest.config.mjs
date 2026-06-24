import { defineConfig } from 'vitest/config';

export default defineConfig({
	test: {
		environment: 'jsdom',
		environmentOptions: { jsdom: { pretendToBeVisual: true } }, // enables requestAnimationFrame, etc.
		include: ['tests/js/**/*.{test,spec}.{js,mjs}'],
		globals: true, // describe / test / expect available without imports
		coverage: {
			provider: 'v8',
			// Report against the whole source tree (not just files a test imported),
			// so the real coverage gap is visible.
			include: ['javascript/**/*.{js,mjs}'],
			reporter: ['text-summary', 'text', 'html'],
		},
	},
});
