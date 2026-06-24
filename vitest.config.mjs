import { defineConfig } from 'vitest/config';

export default defineConfig({
	test: {
		environment: 'jsdom',
		environmentOptions: { jsdom: { pretendToBeVisual: true } }, // enables requestAnimationFrame, etc.
		include: ['tests/js/**/*.{test,spec}.{js,mjs}'],
		globals: true, // describe / test / expect available without imports
	},
});
