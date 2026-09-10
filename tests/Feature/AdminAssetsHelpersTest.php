<?php

declare(strict_types=1);

use TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter;

use function TotalCMS\Slim\Pest\get;

/**
 * `cms.adminAssetsBody()` owns the two globals the admin bundle reads —
 * `window.TCMS_TRANSLATIONS` and `window.TCMS_CONFIG` — and emits them ahead
 * of its script tags; `cms.adminAssetsHead()` owns the dashboard accent rule
 * and emits it after the stylesheets. Both used to be inline in
 * admin-dashboard.twig only, so any other page calling the helpers loaded the
 * admin bundle without its inputs.
 */
use TotalCMS\Support\Config;

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	$this->cms    = $this->app->getContainer()->get(TotalCMSTwigAdapter::class);
	$this->config = $this->app->getContainer()->get(Config::class);
});

it('emits the translation catalog and dashboard config before the admin scripts', function (): void {
	$html = $this->cms->adminAssetsBody();

	expect($html)->toContain('window.TCMS_TRANSLATIONS = {')
		->toContain('window.TCMS_CONFIG = {"confirmCountdown":')
		->and(strpos($html, 'TCMS_TRANSLATIONS'))->toBeLessThan((int)strpos($html, 'admin.js'));
});

it('keeps the globals out of the public assetsBody helper', function (): void {
	expect($this->cms->assetsBody())->not->toContain('TCMS_TRANSLATIONS')->not->toContain('TCMS_CONFIG');
});

it('defines each global exactly once on a dashboard page', function (): void {
	$body = (string)get('/admin/collections')->getBody();

	expect(substr_count($body, 'window.TCMS_TRANSLATIONS ='))->toBe(1)
		->and(substr_count($body, 'window.TCMS_CONFIG ='))->toBe(1);
});

it('emits the accent rule after admin.css when an accent is configured', function (): void {
	$this->config->dashboard['accent'] = '#4d91e2';

	$html = $this->cms->adminAssetsHead();

	expect($html)->toMatch('/<style>:root\{--totalform-accent:[\d.]+% [\d.]+ [\d.]+;\}<\/style>/')
		->and(strpos($html, '--totalform-accent'))->toBeGreaterThan((int)strpos($html, 'admin.css'))
		->and($this->cms->adminAccentStyle())->toBe(substr($html, (int)strpos($html, '<style>')));
});

it('emits no accent rule when none is configured', function (): void {
	unset($this->config->dashboard['accent']);

	expect($this->cms->adminAssetsHead())->not->toContain('--totalform-accent')
		->and($this->cms->adminAccentStyle())->toBe('');
});
