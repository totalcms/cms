<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Middleware\RequestLocaleMiddleware;
use TotalCMS\Support\Config;

const REQUEST_LOCALE_ES_REFUSED = 'Este sitio está en modo de demostración, por lo que no se puede guardar.';

/**
 * Runs the middleware with the given header and returns what a handler
 * behind it sees: the locale and the translated demo.refused string.
 *
 * @return array{locale:string,message:string,after?:string}
 */
function runRequestLocale(?string $header, string $siteLocale = 'en_US'): array
{
	$configRef = new ReflectionClass(Config::class);
	$config    = $configRef->newInstanceWithoutConstructor();
	$configRef->getProperty('locale')->setValue($config, $siteLocale);
	$translator = new TranslationService($config, dirname(__DIR__, 3) . '/resources/translations');

	$request = new ServerRequest('POST', '/api/anything');
	if ($header !== null) {
		$request = $request->withHeader('X-Locale', $header);
	}

	$seen    = ['locale' => '', 'message' => ''];
	$handler = new class($translator, $seen) implements RequestHandlerInterface {
		/** @param array{locale:string,message:string} $seen */
		public function __construct(private TranslationService $translator, private array &$seen)
		{
		}

		public function handle(ServerRequestInterface $request): ResponseInterface
		{
			$this->seen = [
				'locale'  => $this->translator->getLocale(),
				'message' => $this->translator->trans('demo.refused'),
			];

			return new Response(200);
		}
	};

	(new RequestLocaleMiddleware($translator))->process($request, $handler);
	$seen['after'] = $translator->getLocale();

	return $seen;
}

test('a bare language switches the request to that language', function (): void {
	$seen = runRequestLocale('es');

	expect($seen['locale'])->toBe('es');
	expect($seen['message'])->toBe(REQUEST_LOCALE_ES_REFUSED);
});

test('a region with no file of its own falls down to the language catalog', function (): void {
	$seen = runRequestLocale('es-US');

	expect($seen['locale'])->toBe('es_US');
	expect($seen['message'])->toBe(REQUEST_LOCALE_ES_REFUSED);
});

test('the site locale is put back once the request is done', function (): void {
	expect(runRequestLocale('es')['after'])->toBe('en_US');
});

test('no header leaves the site locale alone', function (): void {
	expect(runRequestLocale(null)['locale'])->toBe('en_US');
});

test('a locale we do not ship, or a hostile value, changes nothing', function (string $value): void {
	expect(runRequestLocale($value)['locale'])->toBe('en_US');
})->with([
	'unshipped language' => ['xx'],
	'path traversal'     => ['../../etc/passwd'],
	'too long'           => [str_repeat('a', 500)],
]);

test('a bare language keeps the site locale when it is the same language', function (): void {
	expect(runRequestLocale('en')['locale'])->toBe('en_US');
	expect(runRequestLocale('de', 'de_DE')['locale'])->toBe('de_DE');
});

test('an explicit region still switches away from the site locale', function (): void {
	expect(runRequestLocale('en-GB')['locale'])->toBe('en_GB');
});

test('a bare language of another language still switches', function (): void {
	expect(runRequestLocale('es', 'de_DE')['locale'])->toBe('es');
});
