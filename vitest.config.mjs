import { defineConfig } from 'vitest/config';

export default defineConfig({
	test: {
		environment: 'jsdom',
		environmentOptions: { jsdom: { pretendToBeVisual: true } }, // enables requestAnimationFrame, etc.
		include: ['tests/js/**/*.{test,spec}.{js,mjs}'],
		globals: true, // describe / test / expect available without imports
		coverage: {
			provider: 'v8',
			// Scope to the unit-testable surface — the admin form system — so the
			// percentage reflects code we actually intend to cover, not page-bootstrap
			// entry points (admin.js, content.js, …) or vendor re-export bundles
			// (codemirror-bundle.js) that aren't economically unit-tested.
			include: ['javascript/totalform/**/*.{js,mjs}'],
			reporter: ['text-summary', 'text', 'html'],
			// Ratchet floor: set just below current so it passes now but blocks
			// regression. Bump these up as coverage grows (enforced by
			// `test:js:coverage`, which CI runs).
			thresholds: {
				statements: 11,
				branches: 8,
				functions: 11,
				lines: 11,
			},
		},
	},
});
