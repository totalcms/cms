<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\License;

use TotalCMS\Domain\License\Data\EditionFeature;

/**
 * Gates the routes built on layered image marks (Share Cards) to Pro.
 * ImageWorks itself is not gated here: below Pro it draws without the
 * layers (BasicMarks) instead of refusing an image.
 */
readonly class ImageLayersEditionMiddleware extends BaseEditionMiddleware
{
	protected function getFeature(): EditionFeature
	{
		return EditionFeature::IMAGE_LAYERS;
	}
}
