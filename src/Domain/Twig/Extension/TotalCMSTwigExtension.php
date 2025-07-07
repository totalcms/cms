<?php

namespace TotalCMS\Domain\Twig\Extension;

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
		private TotalCMSTwigAdapter $adapter,
		private TotalCMSTwigPatterns $patterns,
		private FakerFactory $faker,
		private QRCodeTwigAdapter $generator,
		private BarcodeTwigAdapter $barcode,
		private PhpSession $session,
		private CSRFTokenManager $csrfManager,
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
			'sessionData' => $_SESSION,
			'patterns'    => $this->patterns,

			// Heavy objects - use lazy loading proxies
			'qr'          => new LazyTwigGlobal(fn () => $this->generator),
			'barcode'     => new LazyTwigGlobal(fn () => $this->barcode),
			'factory'     => new LazyTwigGlobal(fn () => $this->faker->createFaker()),
			'flash'       => new LazyTwigGlobal(fn () => $this->session->getFlash()),
		];
	}

	public function getFunctions(): array
	{
		$functions = TotalCMSTwigFunctions::getFunctions();

		// Add CSRF token functions
		$functions[] = new TwigFunction('csrf_token', function () {
			return $this->csrfManager->getToken();
		});

		$functions[] = new TwigFunction('csrf_field', function () {
			return $this->csrfManager->getTokenField();
		}, ['is_safe' => ['html']]);

		return $functions;
	}

	public function getFilters()
	{
		return TotalCMSTwigFilters::getFilters();
	}
}
