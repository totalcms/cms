<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin\Utils;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Schema\Service\SchemaLister;

/** The JumpStart export/import utility's pickers. */
final readonly class JumpStartPageData implements UtilsPageData
{
	public function __construct(
		private SchemaLister $schemaLister,
		private CollectionLister $collectionLister,
	) {
	}

	public function build(ServerRequestInterface $request, string $page, string $action): array
	{
		return [
			'jumpstartData' => [
				// Only custom schema *definitions* are exported (exportCustomSchemas),
				// so the picker lists custom schemas only — reserved/extension schemas
				// would be non-functional choices. Collections list all (reserved
				// collections' objects DO export).
				'schemas'     => $this->schemaLister->listCustomSchemas(),
				'collections' => $this->collectionLister->listAllCollections(),
			],
		];
	}
}
