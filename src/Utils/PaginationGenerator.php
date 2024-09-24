<?php

namespace TotalCMS\Utils;

class PaginationGenerator
{
	/** @param array<string,string> $getParams */
	public static function simplePagination(
		int $totalObjects,
		int $currentPage,
		int $pageLimit,
		string $pageKey     = 'p',
		string $prevContent = 'Previous',
		string $nextContent = 'Next',
		array  $getParams   = [],
	): string {
		$totalPages = ceil($totalObjects / $pageLimit);

		if ($totalPages <= 1) {
			return '';
		}

		$prevPage = $currentPage == 1 ? $currentPage : $currentPage - 1;
		$prevLink = HTMLUtils::element('a', $prevContent, [
			'href'  => self::buildPageUrl($pageKey, $prevPage, $getParams),
			'class' => 'pagination-prev',
		]);

		$nextPage = $currentPage == $totalPages ? $currentPage : $currentPage + 1;
		$nextLink = HTMLUtils::element('a', $nextContent, [
			'href'  => self::buildPageUrl($pageKey, $nextPage, $getParams),
			'class' => 'pagination-next',
		]);

		$currentPage = HTMLUtils::element('span', strval($currentPage), ['class' => 'pagination-current', 'contenteditable' => 'plaintext-only']);
		$totalPages = HTMLUtils::element('span', strval($totalPages), ['class' => 'pagination-total']);
		$counters = HTMLUtils::element('span', "$currentPage / $totalPages", ['class' => 'pagination-counters']);

		$pagination = HTMLUtils::element('nav', $prevLink . $counters . $nextLink, [
			'class'         => 'cms-pagination simple',
			'data-page-key' => $pageKey,
		]);

		return $pagination;
	}

	/** @param array<string,string> $getParams */
	public static function fullPagination(
		int $totalObjects,
		int $currentPage,
		int $pageLimit,
		string $pageKey     = 'p',
		string $prevContent = 'Previous',
		string $nextContent = 'Next',
		array  $getParams   = [],
	): string {
		$totalPages = ceil($totalObjects / $pageLimit);

		if ($totalPages <= 1) {
			return '';
		}

		$prevPage = $currentPage == 1 ? $currentPage : $currentPage - 1;
		$prevLink = HTMLUtils::element('a', $prevContent, [
			'href'  => self::buildPageUrl($pageKey, $prevPage, $getParams),
			'class' => 'pagination-prev',
		]);

		$nextPage = $currentPage == $totalPages ? $currentPage : $currentPage + 1;
		$nextLink = HTMLUtils::element('a', $nextContent, [
			'href'  => self::buildPageUrl($pageKey, $nextPage, $getParams),
			'class' => 'pagination-next',
		]);

		$pages = '';

		for ($i = 1; $i <= $totalPages; $i++) {
			$link = HTMLUtils::element('a', strval($i), [
				'href' =>  self::buildPageUrl($pageKey, $i, $getParams),
			]);
			$page = HTMLUtils::element('li', $link, [
				'class' => $i == $currentPage ? 'active' : '',
			]);
			$pages .= $page;
		}

		$pages = HTMLUtils::element('ul', $pages, ['class' => 'pagination-pages']);

		$pagination = HTMLUtils::element('nav', $prevLink . $pages . $nextLink, ['class' => 'cms-pagination full']);

		return $pagination;
	}

	/** @param array<string,string> $getParams */
	private static function buildPageUrl(string $pageKey, int $page, array $getParams): string
	{
		return '?' . http_build_query(array_merge($getParams, [$pageKey => $page]));
	}
}
