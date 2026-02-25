<?php

namespace TotalCMS\Domain\Twig\Extension;

use Faker\Generator;
use Odan\Session\FlashInterface;
use Odan\Session\PhpSession;
use TotalCMS\Domain\Collection\Utilities\ManualSorter;
use TotalCMS\Domain\Factory\Service\FakerFactory;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Domain\Twig\Adapter\BarcodeTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\QRCodeTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig Integration with Total CMS.
 */
class TotalCMSTwigExtension extends AbstractExtension implements GlobalsInterface
{
	public function __construct(
		private readonly TotalCMSTwigAdapter $adapter,
		private readonly TotalCMSTwigPatterns $patterns,
		private readonly FakerFactory $faker,
		private readonly QRCodeTwigAdapter $generator,
		private readonly BarcodeTwigAdapter $barcode,
		private readonly PhpSession $session,
		private readonly CSRFTokenManager $csrfManager,
		private readonly TranslationService $translator,
	) {
	}

	/** @SuppressWarnings("PHPMD.Superglobals") */
	public function getGlobals(): array
	{
		return [
			// Core adapter - keep as main global
			'cms'         => $this->adapter,

			// Lightweight globals - direct access
			'getData'     => $_GET,
			'postData'    => array_filter($_POST),
			'sessionData' => array_filter($_SESSION ?? []),
			'patterns'    => $this->patterns,

			// Heavy objects - use lazy loading proxies
			'qr'          => new LazyTwigGlobal(fn (): QRCodeTwigAdapter => $this->generator),
			'barcode'     => new LazyTwigGlobal(fn (): BarcodeTwigAdapter => $this->barcode),
			'factory'     => new LazyTwigGlobal(fn (): Generator => $this->faker->createFaker()),
			'flash'       => new LazyTwigGlobal(fn (): FlashInterface => $this->session->getFlash()),
		];
	}

	public function getFunctions(): array
	{
		$functions = TotalCMSTwigFunctions::getFunctions();

		// Add CSRF token functions
		$functions[] = new TwigFunction('csrf_token', fn (): string => $this->csrfManager->getToken());

		$functions[] = new TwigFunction('csrf_field', fn (): string => $this->csrfManager->getTokenField(), ['is_safe' => ['html']]);

		// Translation function: {{ t('key') }} or {{ t('key', {param: 'value'}) }}
		$functions[] = new TwigFunction(
			't',
			/** @param array<string,string> $params */
			fn (string $key, array $params = []): string => $this->translator->trans($key, $params, 'admin'),
			['is_safe' => ['html']],
		);

		return $functions;
	}

	public function getFilters(): array
	{
		$filters = TotalCMSTwigFilters::getFilters();

		// manualSort is defined here rather than in TotalCMSTwigFilters because it needs
		// access to the adapter for the 'collection' option (auto-lookup from metadata).
		// TotalCMSTwigFilters uses static methods for performance, which can't access
		// injected dependencies. This is the only filter that requires service access.
		$filters[] = new TwigFilter('manualSort', $this->manualSort(...), ['is_safe' => ['html']]);

		return $filters;
	}

	/**
	 * Sort collection by explicit value order with remainder handling.
	 *
	 * This filter is defined here (not in TotalCMSTwigFilters) because the 'collection'
	 * option requires access to the TotalCMSTwigAdapter to look up collection metadata.
	 * Static filter methods can't access injected dependencies.
	 *
	 * @param array<array<string,mixed>>|null $collection
	 * @param array<string,mixed> $options Options: property, order, collection, remainder, excludeRemainder
	 *
	 * @return array<array<string,mixed>>
	 */
	private function manualSort(?array $collection, array $options): array
	{
		if ($collection === null || $collection === []) {
			return $collection ?? [];
		}

		// If 'collection' option is provided, look up the order from collection metadata
		if (isset($options['collection']) && is_string($options['collection'])) {
			$collectionId = $options['collection'];
			$property     = $options['property'] ?? '';

			if ($property !== '') {
				$meta       = $this->adapter->collection->get($collectionId);
				$manualSort = $meta['manualSort'] ?? [];

				if (is_array($manualSort) && isset($manualSort[$property])) {
					$options['order'] = $manualSort[$property];
				}
			}
		}

		$sorter = new ManualSorter($collection);

		return $sorter->sort($options);
	}

	public function getTokenParsers()
	{
		return [
			new CmsGridTokenParser(),
		];
	}
}
