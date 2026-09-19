<?php

declare(strict_types=1);

use TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter;
use TotalCMS\Support\Config;

/**
 * `cms.adminAssetsBody()` owns the two globals the admin bundle reads —
 * `window.TCMS_TRANSLATIONS` and `window.TCMS_CONFIG` — and emits them ahead
 * of its script tags; `cms.adminAssetsHead()` owns the dashboard accent rule
 * and emits it after the stylesheets. Both used to be inline in
 * admin-dashboard.twig only, so any other page calling the helpers loaded the
 * admin bundle without its inputs.
 */
use function TotalCMS\Slim\Pest\get;

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

it('emits the globals on a public page only for the forms feature', function (): void {
	// forms.js reads the same catalog and config the admin bundle does, so
	// they ride ahead of it; a page that leaves forms out gets neither.
	$with = $this->cms->assetsBody();
	expect($with)->toContain('window.TCMS_TRANSLATIONS = {')
		->and(strpos($with, 'TCMS_TRANSLATIONS'))->toBeLessThan((int)strpos($with, 'forms.js'));

	$without = $this->cms->assetsBody(['except' => ['forms']]);
	expect($without)->not->toContain('TCMS_TRANSLATIONS')->not->toContain('TCMS_CONFIG')->not->toContain('forms.js');
});

describe('the forms feature', function (): void {
	test('a public page gets forms.css in the head and forms.js in the body, and no admin bundle', function (): void {
		$head = $this->cms->assetsHead();
		$body = $this->cms->assetsBody();

		expect($head)->toContain('/assets/forms.css')
			->and($head)->toContain('rel="modulepreload"')
			->and($head)->toContain('/assets/forms.js')
			->and($body)->toContain('<script type="module" src="/api/assets/forms.js')
			->and($head . $body)->not->toContain('admin.js')
			->and($head . $body)->not->toContain('admin.css');
	});

	test('one name leaves out the stylesheet, the script and its preload hint', function (): void {
		$head = $this->cms->assetsHead(['except' => ['forms']]);
		$body = $this->cms->assetsBody(['except' => ['forms']]);

		expect($head . $body)->not->toContain('forms.css')->not->toContain('forms.js');
	});

	test('a form on a public page renders with the helpers and nothing from the admin', function (): void {
		$c = $this->app->getContainer();
		try {
			$c->get(TotalCMS\Domain\Collection\Service\CollectionSaver::class)->saveCollection(['id' => 'blog', 'name' => 'Blog', 'schema' => 'blog']);
		} catch (DomainException) {
			// Another test in this worker already created it.
		}
		$html = $c->get(TotalCMS\Domain\Twig\Service\TwigEngine::class)->renderString(
			"{{ cms.assetsHead() }}{{ cms.form.builder('blog').autoBuild()|raw }}{{ cms.assetsBody() }}",
			[],
		);

		expect($html)->toContain('<form')
			->and($html)->toContain('forms.css')
			->and($html)->toContain('forms.js')
			->and($html)->toContain('window.TCMS_TRANSLATIONS')
			->and($html)->not->toContain('admin.js');
	});
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
