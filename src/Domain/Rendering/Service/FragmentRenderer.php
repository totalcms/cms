<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Rendering\Service;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Template\Data\TemplatePath;
use TotalCMS\Domain\Twig\Service\TwigEngine;

/**
 * Render one object through a builder template — the unit behind every
 * `format=html` response: a query page, a single object, a saved object.
 *
 * One home for the two rules every fragment shares: a template id is
 * required (a fragment with nothing to render it is a 400, not an empty
 * string), and HTML fragments are a templates-edition feature. Template ids
 * resolve relative to `builder/templates/` exactly as `cms.render.*` and the
 * Designer resolve them, so an id means the same file everywhere.
 */
final readonly class FragmentRenderer
{
	public function __construct(
		private TwigEngine $twig,
		private EditionFeatureService $editions,
	) {
	}

	/**
	 * @param array<string,mixed> $context Twig context — conventionally `object` plus `collection`
	 *
	 * @throws HttpBadRequestException when no template id is given
	 * @throws HttpForbiddenException  when the edition has no templates feature
	 */
	public function render(ServerRequestInterface $request, string $template, array $context): string
	{
		if ($template === '') {
			throw new HttpBadRequestException($request, 'The "template" parameter is required for HTML format.');
		}

		if (!$this->editions->can(EditionFeature::TEMPLATES)) {
			throw new HttpForbiddenException($request, 'Templates feature requires Standard edition or higher.');
		}

		return $this->twig->render(TemplatePath::loaderPath($template), $context);
	}

	/**
	 * True when the caller asked for HTML: `format=html` on a read, or an HTMX
	 * request that named a template on a write.
	 */
	public static function wantsHtml(ServerRequestInterface $request): bool
	{
		$params = $request->getQueryParams();

		if (($params['format'] ?? '') === 'html') {
			return true;
		}

		return $request->getHeaderLine('HX-Request') === 'true' && (string)($params['template'] ?? '') !== '';
	}

	/** The template id the request named, or ''. */
	public static function templateFrom(ServerRequestInterface $request): string
	{
		return (string)($request->getQueryParams()['template'] ?? '');
	}
}
