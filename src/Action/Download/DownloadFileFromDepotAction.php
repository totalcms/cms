<?php

declare(strict_types=1);

namespace TotalCMS\Action\Download;

use TotalCMS\Domain\Property\Service\PropertyFile;

/**
 * `/download/{collection}/{id}/{property}/{path}` — a depot entry, or a
 * file nested in a card or deck item; {@see PropertyFileResolver} decides.
 */
class DownloadFileFromDepotAction extends DownloadAction
{
	protected function resolve(array $args, array $query): PropertyFile
	{
		return $this->resolver->depotOrNested(
			$args['collection'],
			$args['id'],
			$args['property'],
			$args['path'] ?? $args['name'] ?? '',
			isset($query['path']) ? (string)$query['path'] : null,
		);
	}
}
