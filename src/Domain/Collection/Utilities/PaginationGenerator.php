<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Collection\Utilities;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

class PaginationGenerator
{
	/** @param array<string,string> $getData */
	public static function simplePagination(
		int $totalObjects,
		int $currentPage,
		int $pageLimit,
		string $pageKey     = 'p',
		string $prevContent = 'Previous',
		string $nextContent = 'Next',
		array $getData     = [],
	): string {
		$totalPages = ceil($totalObjects / $pageLimit);

		if ($totalPages <= 1) {
			return '';
		}

		[$prevLink, $nextLink] = self::prevNextLinks($currentPage, (int)$totalPages, $pageKey, $prevContent, $nextContent, $getData);

		$currentPage = HTMLUtils::element('span', strval($currentPage), ['class' => 'pagination-current', 'contenteditable' => 'plaintext-only']);
		$totalPages  = HTMLUtils::element('span', strval($totalPages), ['class' => 'pagination-total']);
		$counters    = HTMLUtils::element('span', "$currentPage / $totalPages", ['class' => 'pagination-counters']);

		return HTMLUtils::element('nav', $prevLink . $counters . $nextLink, [
			'class'         => 'cms-pagination simple',
			'data-page-key' => $pageKey,
		]);
	}

	/** @param array<string,string> $getData */
	public static function fullPagination(
		int $totalObjects,
		int $currentPage,
		int $pageLimit,
		string $pageKey     = 'p',
		string $prevContent = 'Previous',
		string $nextContent = 'Next',
		array $getData   = [],
	): string {
		$totalPages = ceil($totalObjects / $pageLimit);

		if ($totalPages <= 1) {
			return '';
		}

		[$prevLink, $nextLink] = self::prevNextLinks($currentPage, (int)$totalPages, $pageKey, $prevContent, $nextContent, $getData);

		$pages = '';

		for ($i = 1; $i <= $totalPages; $i++) {
			$link = HTMLUtils::element('a', strval($i), [
				'href' => self::buildPageUrl($pageKey, $i, $getData),
			]);
			$page = HTMLUtils::element('li', $link, [
				'class' => $i === $currentPage ? 'active' : '',
			]);
			$pages .= $page;
		}

		$pages = HTMLUtils::element('ul', $pages, ['class' => 'pagination-pages']);

		return HTMLUtils::element('nav', $prevLink . $pages . $nextLink, ['class' => 'cms-pagination full']);
	}

	/**
	 * The Previous / Next anchors; at either end they point at the current page.
	 *
	 * @param array<string,string> $getData
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function prevNextLinks(int $currentPage, int $totalPages, string $pageKey, string $prevContent, string $nextContent, array $getData): array
	{
		$prevPage = $currentPage === 1 ? $currentPage : $currentPage - 1;
		$prevLink = HTMLUtils::element('a', $prevContent, [
			'href'  => self::buildPageUrl($pageKey, $prevPage, $getData),
			'class' => 'pagination-prev',
		]);

		$nextPage = $currentPage === $totalPages ? $currentPage : $currentPage + 1;
		$nextLink = HTMLUtils::element('a', $nextContent, [
			'href'  => self::buildPageUrl($pageKey, $nextPage, $getData),
			'class' => 'pagination-next',
		]);

		return [$prevLink, $nextLink];
	}

	/** @param array<string,string> $getData */
	private static function buildPageUrl(string $pageKey, int $page, array $getData): string
	{
		return '?' . http_build_query(array_merge($getData, [$pageKey => $page]));
	}
}
