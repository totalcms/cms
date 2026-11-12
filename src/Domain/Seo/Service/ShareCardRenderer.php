<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\Service;

use TotalCMS\Domain\Builder\Data\RouteMatch;
use TotalCMS\Domain\Builder\Service\PagePreview;
use TotalCMS\Domain\Builder\Exception\PageNotFoundException;
use TotalCMS\Domain\Builder\Exception\PageRedirectException;
use TotalCMS\Domain\Builder\Exception\PageResponseException;
use TotalCMS\Domain\Seo\Data\RenderedShareHead;
use TotalCMS\Domain\Seo\Data\SocialCardOutcome;
use TotalCMS\Domain\Seo\Data\SocialCardReason;
use TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Support\Config;

/**
 * Renders one page through its real template, the way PageRouterMiddleware
 * does, and reads back the share card its head printed and why (Share Cards).
 *
 * The page renders as the admin viewing the utility, at its own URL: the
 * request URI and the Twig adapter's current URL point at the page for the
 * render and are put back afterwards, whatever happens. The per-page
 * middleware chain (passcodes, access gates, A/B splits) does not run: the
 * admin is previewing, not visiting.
 *
 * A template that does not render (refuses with cms.notFound(), redirects,
 * answers with its own response, or fails) gets the card its record works
 * out to instead, marked as such.
 */
readonly class ShareCardRenderer
{
	private const NOTE_MAX = 200;
	private const ERROR_MARKUP = 'class="cms-twig-error';

	public function __construct(
		private TwigEngine $twig,
		private SocialCardRecorder $recorder,
		private SocialImageBuilder $socialImages,
		private SeoContextFactory $contexts,
		private MetaBuilder $metaBuilder,
		private TotalCMSTwigAdapter $adapter,
		private PagePreview $preview,
		private Config $config,
	) {
	}

	/**
	 * @param string $path The page's path, as the router matches it
	 * @param string $collection The object's collection; '' for a Site Builder page
	 *
	 * @SuppressWarnings("PHPMD.Superglobals") The page must see its own URL in REQUEST_URI.
	 */
	public function render(RouteMatch $match, string $path, string $collection): RenderedShareHead
	{
		$uri          = rtrim($this->config->api, '/') . $path;
		$savedUri     = $_SERVER['REQUEST_URI'] ?? null;
		$savedCurrent = $this->adapter->currentUrl;

		$_SERVER['REQUEST_URI']    = $uri;
		$this->adapter->currentUrl = $uri;
		$this->preview->show($match);
		$this->recorder->start();

		try {
			[$html, $error, $failed] = $this->renderHtml($match);
		} finally {
			$outcomes = $this->recorder->stop();
			$this->preview->clear();
			if ($savedUri === null) {
				unset($_SERVER['REQUEST_URI']);
			} else {
				$_SERVER['REQUEST_URI'] = $savedUri;
			}
			$this->adapter->currentUrl = $savedCurrent;
		}

		if ($error !== null) {
			return $this->fromRecord($match->pageData, $collection, $error, $failed);
		}

		return $this->fromHtml($html, $outcomes);
	}

	/**
	 * @return array{0:string,1:?string,2:bool} The HTML; why it is not the
	 *                                          page, when it is not; and whether that is a failure rather than a decline
	 */
	private function renderHtml(RouteMatch $match): array
	{
		$data = ['params' => $match->params];
		$data[$match->collection !== null ? 'object' : 'page'] = $match->pageData;

		try {
			$html = $this->twig->render($match->template, $data);
		} catch (\Throwable $e) {
			$decline = self::decline($e);

			return ['', $decline ?? self::firstLine($e->getMessage()), $decline === null];
		}

		// TwigEngine answers a failed render with an error block, not an exception.
		if (str_contains($html, self::ERROR_MARKUP)) {
			return ['', self::errorMessage($html), true];
		}

		return [$html, null, false];
	}

	/** Why a template chose not to render the page, or null when it failed. */
	private static function decline(\Throwable $e): ?string
	{
		return match (true) {
			PageNotFoundException::find($e) instanceof PageNotFoundException => 'refused to render',
			PageRedirectException::find($e) instanceof PageRedirectException => 'redirects',
			PageResponseException::find($e) instanceof PageResponseException => 'answers with its own response',
			default => null,
		};
	}

	/** The message in TwigEngine's error block, without the traceback that follows it. */
	private static function errorMessage(string $html): string
	{
		preg_match('#<p class="cms-twig-error[^"]*">(.*?)</p>#s', $html, $m);

		return self::firstLine(html_entity_decode(strip_tags($m[1] ?? 'the template failed')));
	}

	private static function firstLine(string $message): string
	{
		$line = trim(strtok(trim($message), "\n") ?: '');

		return mb_substr($line !== '' ? $line : 'the template failed', 0, self::NOTE_MAX);
	}

	/** @param list<SocialCardOutcome> $outcomes */
	private function fromHtml(string $html, array $outcomes): RenderedShareHead
	{
		$og      = self::openGraph($html);
		$outcome = self::outcomeFor($og['og:image'], $outcomes);
		$reason  = $outcome->reason ?? ($og['og:image'] === '' ? SocialCardReason::NoImage : SocialCardReason::NoRecord);

		if ($reason === SocialCardReason::Drawn && $this->mismatched($og['og:image'])) {
			$reason = SocialCardReason::HashMismatch;
		}

		// A feed, robots.txt or JSON page has no head to put a card in.
		$document = preg_match('#<(head|html)[\s>]#i', $html) === 1;

		return new RenderedShareHead($og['og:image'], $og['og:title'], $og['og:description'], $reason, $outcome->detail ?? '', $outcome?->layer, document: $document);
	}

	/**
	 * The og:image, og:title and og:description a page printed.
	 *
	 * @return array{'og:image':string,'og:title':string,'og:description':string}
	 */
	private static function openGraph(string $html): array
	{
		$og = ['og:image' => '', 'og:title' => '', 'og:description' => ''];
		if (trim($html) === '') {
			return $og;
		}

		$doc = new \DOMDocument();
		@$doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
		foreach ($doc->getElementsByTagName('meta') as $meta) {
			$property = $meta->getAttribute('property');
			if (array_key_exists($property, $og) && $og[$property] === '') {
				$og[$property] = $meta->getAttribute('content');
			}
		}

		return $og;
	}

	/**
	 * The head whose image the page shares: a template can call
	 * cms.seo.head() more than once. Else the last one.
	 *
	 * @param list<SocialCardOutcome> $outcomes
	 */
	private static function outcomeFor(string $ogImage, array $outcomes): ?SocialCardOutcome
	{
		foreach ($outcomes as $outcome) {
			if ($outcome->ogImage === $ogImage && $ogImage !== '') {
				return $outcome;
			}
		}

		return $outcomes === [] ? null : $outcomes[array_key_last($outcomes)];
	}

	/**
	 * A share URL the image route will not draw: the record as stored works
	 * out to another card, because the page changed a value the card draws.
	 */
	private function mismatched(string $ogImage): bool
	{
		$path   = (string)parse_url($ogImage, PHP_URL_PATH);
		$prefix = rtrim($this->config->api, '/') . SocialImageBuilder::ROUTE . '/';
		parse_str((string)parse_url($ogImage, PHP_URL_QUERY), $query);
		$hash = $query['v'] ?? '';

		if (!str_starts_with($path, $prefix) || !is_string($hash) || preg_match('#^([^/]+)/([^/]+)\.\w+$#', substr($path, strlen($prefix)), $m) !== 1) {
			return false;
		}

		$card = $this->socialImages->forRecord(rawurldecode($m[1]), rawurldecode($m[2]), $hash);

		return $card === null || !$card->hasOverlay();
	}

	/**
	 * The card a record works out to when its page did not render.
	 *
	 * @param array<string,mixed> $record
	 */
	private function fromRecord(array $record, string $collection, string $note, bool $failed): RenderedShareHead
	{
		$ctx     = $this->contexts->make($record, $collection === '' ? [] : ['collection' => $collection]);
		$meta    = $this->metaBuilder->build($ctx);
		$outcome = $this->socialImages->explain($ctx, $meta);
		$card    = $this->socialImages->apply($ctx, $meta);

		return new RenderedShareHead($card->ogImage, $meta->socialTitle, $meta->socialDescription, $outcome->reason, $outcome->detail, $outcome->layer, true, $note, $failed);
	}
}
