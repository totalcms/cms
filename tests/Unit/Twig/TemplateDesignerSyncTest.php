<?php

use TotalCMS\Domain\Builder\Service\BuilderTemplatePaths;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Template\Service\TemplateSaver;
use TotalCMS\Domain\Twig\Designer\TemplateDesignerRegistry;
use TotalCMS\Domain\Twig\Designer\TemplateDesignerSync;
use TotalCMS\Support\Config;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

function createDesignerSync(
	HttpClientInterface $httpClient,
	string $currentDomain = 'https://dev.example.com',
	bool $featureEnabled = true,
	?TemplateSaver $templateSaver = null,
	?TemplateDesignerRegistry $registry = null,
	string $api = '',
): TemplateDesignerSync {
	$config         = test()->createMock(Config::class);
	$config->domain = $currentDomain;
	$config->api    = $api;

	$editionFeatures = test()->createMock(EditionFeatureService::class);
	$editionFeatures->method('can')->with(EditionFeature::TEMPLATES)->willReturn($featureEnabled);

	$templateSaver ??= test()->createMock(TemplateSaver::class);
	$registry ??= new TemplateDesignerRegistry();

	// Not git-managed by default, so the designer sync path runs as before.
	$paths = test()->createMock(BuilderTemplatePaths::class);
	$paths->method('isProjectManaged')->willReturn(false);

	return new TemplateDesignerSync($config, $templateSaver, $registry, $editionFeatures, $httpClient, $paths);
}

function registerBlock(TemplateDesignerRegistry $registry, string $key = 'test-key', string $domain = 'https://production.example.com', string $token = 'test-token', string $content = '<p>Hello</p>', string $template = 'pages/home.twig'): void
{
	$registry->register($key, [
		'template' => $template,
		'domain'   => $domain,
		'token'    => $token,
		'content'  => $content,
	]);
}

describe('TemplateDesignerSync', function (): void {
	test('returns empty string when edition does not support templates', function (): void {
		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->expects(test()->never())->method('request');

		$sync   = createDesignerSync($httpClient, featureEnabled: false);
		$result = $sync->sync('any-key');

		expect($result)->toBe('');
	});

	test('returns empty string when registry has no block for key', function (): void {
		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->expects(test()->never())->method('request');

		$sync   = createDesignerSync($httpClient);
		$result = $sync->sync('nonexistent-key');

		expect($result)->toBe('');
	});

	test('returns empty string when current domain matches production (is production)', function (): void {
		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->expects(test()->never())->method('request');

		$registry = new TemplateDesignerRegistry();
		registerBlock($registry, domain: 'https://production.example.com');

		$sync = createDesignerSync(
			$httpClient,
			currentDomain: 'https://production.example.com',
			registry: $registry,
		);

		$result = $sync->sync('test-key');
		expect($result)->toBe('');
	});

	test('syncs remotely when domains differ and returns badge HTML', function (): void {
		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->expects(test()->once())
			->method('request')
			->with('PUT', test()->stringContains('/designer/templates/pages/home.twig'), test()->anything())
			->willReturn(new HttpResponse(200, ''));

		$registry = new TemplateDesignerRegistry();
		registerBlock($registry);

		$sync   = createDesignerSync($httpClient, registry: $registry);
		$result = $sync->sync('test-key');

		expect($result)->toContain('Template Designer');
		expect($result)->toContain('pages/home.twig');
		expect($result)->toContain('tcms-designer-badge');
	});

	test('PUTs to the /api-prefixed designer route on a root install', function (): void {
		$url        = '';
		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->expects(test()->once())
			->method('request')
			->with('PUT', test()->callback(function (string $requested) use (&$url): bool {
				$url = $requested;

				return true;
			}), test()->anything())
			->willReturn(new HttpResponse(200, ''));

		$registry = new TemplateDesignerRegistry();
		registerBlock($registry);

		createDesignerSync($httpClient, registry: $registry)->sync('test-key');

		expect($url)->toBe('https://production.example.com/api/designer/templates/pages/home.twig');
	});

	test('PUTs to the /api-prefixed designer route on a subpath install', function (): void {
		$url        = '';
		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->expects(test()->once())
			->method('request')
			->with('PUT', test()->callback(function (string $requested) use (&$url): bool {
				$url = $requested;

				return true;
			}), test()->anything())
			->willReturn(new HttpResponse(200, ''));

		$registry = new TemplateDesignerRegistry();
		registerBlock($registry);

		createDesignerSync($httpClient, registry: $registry, api: '/site-assets/stacks/tcms3/tcms')->sync('test-key');

		expect($url)->toBe('https://production.example.com/site-assets/stacks/tcms3/tcms/api/designer/templates/pages/home.twig');
	});

	test('normalizes a template id written with a leading slash', function (): void {
		$url        = '';
		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->expects(test()->once())
			->method('request')
			->with('PUT', test()->callback(function (string $requested) use (&$url): bool {
				$url = $requested;

				return true;
			}), test()->anything())
			->willReturn(new HttpResponse(200, ''));

		$registry = new TemplateDesignerRegistry();
		registerBlock($registry, template: '/templates/loadmore-copies');

		createDesignerSync($httpClient, registry: $registry)->sync('test-key');

		expect($url)->toBe('https://production.example.com/api/designer/templates/templates/loadmore-copies');
	});

	test('sends correct headers with designer token', function (): void {
		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->expects(test()->once())
			->method('request')
			->with(
				'PUT',
				test()->anything(),
				test()->callback(function (array $options): bool {
					$headers        = $options['headers'] ?? [];
					$hasToken       = false;
					$hasContentType = false;
					foreach ($headers as $header) {
						if (str_starts_with($header, 'X-Designer-Token: my-secret-token')) {
							$hasToken = true;
						}
						if ($header === 'Content-Type: text/plain') {
							$hasContentType = true;
						}
					}

					return $hasToken && $hasContentType;
				})
			)
			->willReturn(new HttpResponse(200, ''));

		$registry = new TemplateDesignerRegistry();
		registerBlock($registry, token: 'my-secret-token');

		$sync = createDesignerSync($httpClient, registry: $registry);
		$sync->sync('test-key');
	});

	test('sends template content as request body', function (): void {
		$content = '<div>Custom template content</div>';

		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->expects(test()->once())
			->method('request')
			->with(
				'PUT',
				test()->anything(),
				test()->callback(fn (array $options): bool => ($options['body'] ?? '') === $content)
			)
			->willReturn(new HttpResponse(200, ''));

		$registry = new TemplateDesignerRegistry();
		registerBlock($registry, content: $content);

		$sync = createDesignerSync($httpClient, registry: $registry);
		$sync->sync('test-key');
	});

	test('badge shows error status when remote sync fails with HTTP error', function (): void {
		$httpClient = createMockHttpClient(new HttpResponse(500, 'Internal Server Error'));

		$registry = new TemplateDesignerRegistry();
		registerBlock($registry);

		$sync   = createDesignerSync($httpClient, registry: $registry);
		$result = $sync->sync('test-key');

		expect($result)->toContain('tcms-designer-err');
		expect($result)->toContain('HTTP 500');
	});

	test('badge shows error status when remote sync throws exception', function (): void {
		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->method('request')->willThrowException(new RuntimeException('Connection refused'));

		$registry = new TemplateDesignerRegistry();
		registerBlock($registry);

		$sync   = createDesignerSync($httpClient, registry: $registry);
		$result = $sync->sync('test-key');

		expect($result)->toContain('tcms-designer-err');
		expect($result)->toContain('Connection refused');
	});

	test('skips remote sync when token is empty', function (): void {
		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->expects(test()->never())->method('request');

		$registry = new TemplateDesignerRegistry();
		registerBlock($registry, token: '');

		$sync   = createDesignerSync($httpClient, registry: $registry);
		$result = $sync->sync('test-key');

		expect($result)->toContain('Template Designer');
		expect($result)->toContain('tcms-designer-skip');
	});

	test('uses short timeouts for remote sync', function (): void {
		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->expects(test()->once())
			->method('request')
			->with(
				'PUT',
				test()->anything(),
				test()->callback(fn (array $options): bool => ($options['timeout'] ?? 0) === 3
						&& ($options['connect_timeout'] ?? 0) === 2)
			)
			->willReturn(new HttpResponse(200, ''));

		$registry = new TemplateDesignerRegistry();
		registerBlock($registry);

		$sync = createDesignerSync($httpClient, registry: $registry);
		$sync->sync('test-key');
	});
});

/**
 * The local save and the remote lookup must resolve an id to the SAME file.
 *
 * Regression: `for 'myblog'` used to save `builder/myblog.twig` locally and
 * report a green "Local: ✓", while the remote looked for the real template at
 * `builder/templates/myblog.twig` and answered "Template not found". Both ends
 * now go through TemplatePath::parseDesigner().
 */
describe('TemplateDesignerSync local path resolution', function (): void {
	test('saves a bare id into the templates builder category', function (): void {
		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->method('request')->willReturn(new HttpResponse(200, ''));

		$saver = test()->createMock(TemplateSaver::class);
		$saver->expects(test()->once())
			->method('saveTemplate')
			->with('myblog', '<p>Hello</p>', 'templates');

		$registry = new TemplateDesignerRegistry();
		registerBlock($registry, template: 'myblog');

		createDesignerSync($httpClient, templateSaver: $saver, registry: $registry)->sync('test-key');
	});

	test('does not double the category when the id already carries it', function (): void {
		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->method('request')->willReturn(new HttpResponse(200, ''));

		$saver = test()->createMock(TemplateSaver::class);
		$saver->expects(test()->once())
			->method('saveTemplate')
			->with('myblog', '<p>Hello</p>', 'templates');

		$registry = new TemplateDesignerRegistry();
		registerBlock($registry, template: 'templates/myblog');

		createDesignerSync($httpClient, templateSaver: $saver, registry: $registry)->sync('test-key');
	});

	test('nests a sub folder under the templates category', function (): void {
		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->method('request')->willReturn(new HttpResponse(200, ''));

		$saver = test()->createMock(TemplateSaver::class);
		$saver->expects(test()->once())
			->method('saveTemplate')
			->with('card', '<p>Hello</p>', 'templates/grids');

		$registry = new TemplateDesignerRegistry();
		registerBlock($registry, template: 'grids/card');

		createDesignerSync($httpClient, templateSaver: $saver, registry: $registry)->sync('test-key');
	});
});
