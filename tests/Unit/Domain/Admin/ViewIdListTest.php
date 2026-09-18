<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Admin;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Domain\DataView\Service\DataViewLister;

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
		(new \ReflectionProperty(TotalForm::class, 'services'))->setValue($form, formServices(['dataViewLister' => $lister]));

		$this->assertSame(['sales-summary', 'top-products'], $form->viewIdList());
	}

	public function testViewIdListReturnsEmptyWhenListerMissing(): void
	{
		$form = (new \ReflectionClass(TotalForm::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(TotalForm::class, 'services'))->setValue($form, formServices());
		$this->assertSame([], $form->viewIdList());
	}
}
