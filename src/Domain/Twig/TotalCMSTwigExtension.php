<?php

namespace TotalCMS\Domain\Twig;

use TotalCMS\Factory\FakerFactory;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

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
	) {
	}

	/**
	 * @SuppressWarnings(PHPMD.Superglobals)
	 */
	public function getGlobals(): array
	{
		return [
			'cms'        => $this->adapter,
			'qr'         => $this->generator,
			'getParams'  => $_GET,
			'postParams' => array_filter($_POST),
			'patterns'   => $this->patterns,
			'factory'    => $this->faker->createFaker(),
		];
	}

	public function getFunctions(): array
	{
		return TotalCMSTwigFunctions::getFunctions();
	}

	public function getFilters()
	{
		return TotalCMSTwigFilters::getFilters();
	}
}
