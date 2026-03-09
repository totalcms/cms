<?php

declare(strict_types=1);

namespace TotalCMS\Action\Mailer;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Returns JSON choices for objects matching bulk filters.
 * Used by the Choices.js-powered object picker in the bulk mailer form.
 */
readonly class BulkObjectOptionsAction
{
	public function __construct(
		private IndexFilter $indexFilter,
		private JsonRenderer $renderer,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$params = $request->getQueryParams();

		$collection = isset($params['bulkCollection']) && $params['bulkCollection'] !== ''
			? (string)$params['bulkCollection']
			: '';

		if ($collection === '') {
			return $this->renderer->json($response, []);
		}

		$filterOptions = [];
		if (isset($params['bulkInclude']) && $params['bulkInclude'] !== '') {
			$filterOptions['include'] = (string)$params['bulkInclude'];
		}
		if (isset($params['bulkExclude']) && $params['bulkExclude'] !== '') {
			$filterOptions['exclude'] = (string)$params['bulkExclude'];
		}

		try {
			$objects = $this->indexFilter->fetchFilteredIndex($collection, $filterOptions);
		} catch (\Exception) {
			return $this->renderer->json($response, []);
		}

		$labelField = $this->detectLabelField($objects[0] ?? []);
		$choices    = [];

		foreach ($objects as $object) {
			$id = (string)($object['id'] ?? '');
			if ($id === '') {
				continue;
			}

			$label = $id;
			if ($labelField !== null && isset($object[$labelField]) && (string)$object[$labelField] !== '') {
				$label = $id . ' - ' . $object[$labelField];
			}

			$choices[] = ['value' => $id, 'label' => $label];
		}

		return $this->renderer->json($response, $choices);
	}

	/**
	 * Auto-detect a label field from the first object.
	 *
	 * @param array<string,mixed> $object
	 */
	private function detectLabelField(array $object): ?string
	{
		$candidates = ['name', 'title', 'email', 'subject', 'label'];

		foreach ($candidates as $field) {
			if (isset($object[$field]) && (string)$object[$field] !== '') {
				return $field;
			}
		}

		return null;
	}
}
