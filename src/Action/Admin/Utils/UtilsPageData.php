<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin\Utils;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Builds the template variables one utility page (or a small group of
 * related pages) needs. Each implementation owns a fixed set of keys in the
 * admin/utils.twig payload and returns only those keys; AdminUtilsAction
 * merges them over a default payload where every key is null.
 */
interface UtilsPageData
{
	/**
	 * @param  string              $page   Normalized page name, e.g. 'oauth-grants'
	 * @param  string              $action Route/query action, '' when absent
	 *
	 * @return array<string,mixed> Template variables for this page
	 */
	public function build(ServerRequestInterface $request, string $page, string $action): array;
}
