<?php

declare(strict_types=1);

/**
 * The frontend scaffold `tcms builder:frontend` copies is a customer's own
 * npm project: it installs only what its package.json lists. The repo's
 * move to Vite+ rewrote the scaffold's config to import `vite-plus` too,
 * which the scaffold never installs, so `npm run build` failed with
 * "Cannot find package 'vite-plus'" from 3.6.4 (found 2026-11-03). Every
 * package the shipped JS imports must be one its package.json declares.
 */
function scaffoldDir(): string
{
	return dirname(__DIR__, 4) . '/resources/builder/frontend';
}

it('declares every package the scaffold imports', function (): void {
	$package  = json_decode((string)file_get_contents(scaffoldDir() . '/package.json'), true, 512, JSON_THROW_ON_ERROR);
	$declared = array_keys(($package['dependencies'] ?? []) + ($package['devDependencies'] ?? []));

	$files = array_merge(glob(scaffoldDir() . '/*.js') ?: [], glob(scaffoldDir() . '/js/*.js') ?: []);
	expect($files)->not->toBeEmpty();

	foreach ($files as $file) {
		preg_match_all('/\bfrom\s+[\'"]([^\'"]+)[\'"]|\bimport\s+[\'"]([^\'"]+)[\'"]/', (string)file_get_contents($file), $m);
		foreach (array_filter(array_merge($m[1], $m[2])) as $specifier) {
			if (str_starts_with($specifier, '.') || str_starts_with($specifier, '/') || str_starts_with($specifier, 'node:')
				|| in_array($specifier, ['path', 'fs', 'url', 'os'], true)) {
				continue; // relative paths and Node built-ins need no install
			}
			$name = str_starts_with($specifier, '@')
				? implode('/', array_slice(explode('/', $specifier), 0, 2))
				: explode('/', $specifier)[0];

			expect(in_array($name, $declared, true))->toBeTrue(basename($file) . " imports '{$specifier}', which package.json does not install");
		}
	}
});

it('configures Vite through the vite package', function (): void {
	expect((string)file_get_contents(scaffoldDir() . '/vite.config.js'))->toContain("from 'vite'");
});
