<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Admin;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\DataView\Service\DataViewLister;
use TotalCMS\Domain\Admin\TotalForm;

final class ViewIdListTest extends TestCase
{
	public function testViewIdListReturnsIdsFromLister(): void
	{
		$lister = $this->createMock(DataViewLister::class);
		$lister->method('listViews')->willReturn([
			['id' => 'sales-summary', 'name' => 'Sales Summary'],
			['id' => 'top-products', 'name' => 'Top Products'],
		]);

		$form = (new \ReflectionClass(TotalForm::class))->newInstanceWithoutConstructor();
		$form->setDataViewLister($lister);

		$this->assertSame(['sales-summary', 'top-products'], $form->viewIdList());
	}

	public function testViewIdListReturnsEmptyWhenListerMissing(): void
	{
		$form = (new \ReflectionClass(TotalForm::class))->newInstanceWithoutConstructor();
		$this->assertSame([], $form->viewIdList());
	}
}
