<?php

declare(strict_types=1);

namespace TotalCMS\Action\Stream;

use TotalCMS\Domain\Property\Service\PropertyFile;

/**
 * `/stream/{collection}/{id}/{property}` — a plain file property.
 */
class StreamFileAction extends StreamAction
{
	protected function resolve(array $args, array $query): PropertyFile
	{
		return $this->resolver->file($args['collection'], $args['id'], $args['property']);
	}
}
