<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Admin\FormField\DeleteButton;
use TotalCMS\Domain\Admin\FormField\FormField;
use TotalCMS\Domain\Admin\FormField\SaveButton;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Utils\HTMLUtils;

/**
 * Total Form Builder.
 */
final class ObjectForm extends TotalForm
{
	protected function init(): void
	{
		parent::init();

		$this->route = "/collections/{$this->collection}";

		if (!empty($this->id) && $this->objectFetcher->existsObject($this->collection, $this->id)) {
			// If the form is for editing an existing item, change the method to PUT
			$this->objectData = $this->objectFetcher->fetchObject($this->collection, $this->id);
			$this->method     = 'PUT';
			$this->route      = "/collections/{$this->collection}/{$this->id}";
		}

		$this->initCollectionData();
	}
}
