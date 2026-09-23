<?php

declare(strict_types=1);

namespace TotalCMS\Action\Download;

use TotalCMS\Domain\Property\Service\PropertyFile;

/**
 * `/download/{collection}/{id}/{property}` — a plain file property.
 */
class DownloadFileAction extends DownloadAction
{
	protected function resolve(array $args, array $query): PropertyFile
	{
		return $this->resolver->file($args['collection'], $args['id'], $args['property']);
	}
}
