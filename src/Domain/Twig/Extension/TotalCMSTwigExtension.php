<?php

namespace TotalCMS\Domain\Twig\Extension;

use Faker\Generator;
use Odan\Session\FlashInterface;
use Odan\Session\PhpSession;
use TotalCMS\Domain\Factory\Service\FakerFactory;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Domain\Twig\Adapter\BarcodeTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\QRCodeTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

/**
 * Twig Integration with Total CMS.
 */
final class TotalCMSTwigExtension extends AbstractExtension implements GlobalsInterface
{
	public function __construct(
		private readonly TotalCMSTwigAdapter $adapter,
		private readonly TotalCMSTwigPatterns $patterns,
		private readonly FakerFactory $faker,
		private readonly QRCodeTwigAdapter $generator,
		private readonly BarcodeTwigAdapter $barcode,
		private readonly PhpSession $session,
		private readonly CSRFTokenManager $csrfManager,
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

		return $functions;
	}

	public function getFilters()
	{
		return TotalCMSTwigFilters::getFilters();
	}

	public function getTokenParsers()
	{
		return [
			new CmsGridTokenParser(),
		];
	}
}
