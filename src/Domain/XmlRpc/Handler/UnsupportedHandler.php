<?php

declare(strict_types=1);

namespace TotalCMS\Domain\XmlRpc\Handler;

use TotalCMS\Domain\XmlRpc\Transport\XmlRpcFault;

/**
 * Methods T3 knowingly does not implement.
 *
 * These are registered rather than left to fall through to the unknown-method
 * fault so the user reads an explanation instead of "method does not exist" —
 * clients display fault strings verbatim in their error dialogs.
 */
readonly class UnsupportedHandler implements MethodHandler
{
	private const MEDIA_MESSAGE = 'Total CMS does not accept media uploads over XML-RPC yet. '
		. 'Publish the text from your writing app, then add images in the Total CMS admin, '
		. 'where you also get cropping and alt text.';

	private const PAGE_MESSAGE = 'Total CMS does not expose pages over XML-RPC. '
		. 'Site Builder pages are managed in the Total CMS admin.';

	/** @return array<string,callable(array<int,mixed>,?string):mixed> */
	public function methods(): array
	{
		return [
			'metaWeblog.newMediaObject' => $this->media(...),
			'wp.uploadFile'             => $this->media(...),
			'wp.getPages'               => $this->pages(...),
			'wp.getPageList'            => $this->pages(...),
			'wp.getPage'                => $this->pages(...),
			'wp.newPage'                => $this->pages(...),
			'wp.editPage'               => $this->pages(...),
			'wp.deletePage'             => $this->pages(...),
			'wp.getPageTemplates'       => $this->pages(...),
		];
	}

	/** @param array<int,mixed> $params */
	public function media(array $params, ?string $collection): never
	{
		throw XmlRpcFault::forbidden(self::MEDIA_MESSAGE);
	}

	/** @param array<int,mixed> $params */
	public function pages(array $params, ?string $collection): never
	{
		throw XmlRpcFault::forbidden(self::PAGE_MESSAGE);
	}
}
