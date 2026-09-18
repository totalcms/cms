<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\Form;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
use TotalCMS\Domain\Admin\Nav\AdminNavRegistry;
use TotalCMS\Domain\Builder\Service\PageMiddlewareRegistry;
use TotalCMS\Domain\Collection\Service\CollectionEditionService;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\DataView\Service\DataViewFilter;
use TotalCMS\Domain\DataView\Service\DataViewLister;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Property\Service\PropertyMetaResolver;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Domain\Template\Service\TemplateLister;
use TotalCMS\Support\Config;

/**
 * The collaborators every form shares.
 *
 * Nothing here varies per form: the factory builds one FormServices and hands
 * it to every form it makes, and a test builds one from stubs instead of
 * fourteen mocks. What does vary per form — identity, presentation, behaviour
 * flags — stays on the form's own constructor.
 *
 * The four nullable listers were once attached after construction through
 * setters, because the constructor was already full. A form built without
 * them (tests, extensions) answers their option lists with an empty array.
 */
final readonly class FormServices
{
	public LoggerInterface $logger;

	public function __construct(
		public ObjectFetcher $objectFetcher,
		public CollectionFetcher $collectionFetcher,
		public CollectionLister $collectionLister,
		public IndexReader $collectionReader,
		public IndexFilter $indexFilter,
		public SchemaFetcher $schemaFetcher,
		public SchemaLister $schemaLister,
		public AccessGroupLister $accessGroupLister,
		public CollectionEditionService $collectionEditionService,
		public EditionFeatureService $editionFeatures,
		public DataViewFilter $dataViewFilter,
		public CSRFTokenManager $csrfManager,
		public Config $config,
		public PropertyMetaResolver $metaResolver,
		?LoggerInterface $logger = null,
		public ?TemplateLister $templateLister = null,
		public ?PageMiddlewareRegistry $pageMiddlewareRegistry = null,
		public ?AdminNavRegistry $navRegistry = null,
		public ?DataViewLister $dataViewLister = null,
	) {
		$this->logger = $logger ?? new NullLogger();
	}
}
