<?php

namespace TotalCMS\Domain\Twig;

use Odan\Session\PhpSession;
use TotalCMS\Factory\FakerFactory;
use TotalCMS\Utils\CSRFTokenManager;
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
		private PhpSession $session,
		private CSRFTokenManager $csrfManager,
	) {
	}

	/** @SuppressWarnings("PHPMD.Superglobals") */
	public function getGlobals(): array
	{
		return [
			'cms'         => $this->adapter,
			'qr'          => $this->generator,
			'getData'     => $_GET,
			'postData'    => array_filter($_POST),
			'sessionData' => $_SESSION,
			'patterns'    => $this->patterns,
			'factory'     => $this->faker->createFaker(),
			'flash'       => $this->session->getFlash(),
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
