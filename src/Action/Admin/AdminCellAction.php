<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Action\Object\Support\PrivilegedFieldGuard;
use TotalCMS\Domain\Admin\InlineEditable;
use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Property\Service\PropertyMetaResolver;
use TotalCMS\Domain\Schema\Data\PropertyDefinition;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Renderer\RawRenderer;

/**
 * One cell of the collection table, as an HTML fragment.
 *
 *   GET   …/cell/{property}       the display cell (what table-row.twig renders)
 *   GET   …/cell/{property}/edit  a one-field TotalForm for that property
 *   PATCH …/cell/{property}       save, then the display cell again
 *
 * This is the inline-edit round trip: the cell's pencil swaps in the edit
 * form, the form's save swaps the cell back. Mounted in the admin group, so
 * the session, the collection access middleware and same-origin CSRF all
 * apply as they do to the object page. The PATCH body carries `data` — the
 * form's generateData() as JSON — so every field type saves exactly what the
 * object form would; the object-level patch merges it (the property-level
 * patch is for array-valued properties and cannot take a scalar).
 *
 * The same edit fragment is the unit live-site inline editing will reuse;
 * proving each field type inside a swap here is the point of shipping the
 * admin side first.
 */
readonly class AdminCellAction
{
	public function __construct(
		private TwigEngine $twig,
		private RawRenderer $rawRenderer,
		private ObjectFetcher $objectFetcher,
		private ObjectPatcher $patcher,
		private PrivilegedFieldGuard $guard,
		private PropertyMetaResolver $propertyMeta,
		private TotalFormFactory $forms,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$collection = $args['collection'];
		$id         = $args['id'];
		$property   = $args['property'];

		try {
			$meta = $this->propertyMeta->resolve($collection, $property);
		} catch (\Throwable) {
			$meta = [];
		}
		// The resolver always returns a `settings` key, so a property nobody
		// defined looks like ['settings' => []] rather than [].
		if (!isset($meta['type']) && !isset($meta['field'])) {
			throw new HttpNotFoundException($request, "Property '{$property}' not found in '{$collection}'.");
		}

		$fieldType = (string)($meta['field'] ?? '');
		$isEdit    = str_ends_with($request->getUri()->getPath(), '/edit');
		$isPatch   = $request->getMethod() === 'PATCH';

		$editable = InlineEditable::allows($meta);
		if (($isEdit || $isPatch) && !$editable) {
			throw new HttpBadRequestException($request, sprintf("'%s' (%s) cannot be edited inline.", $property, $fieldType !== '' ? $fieldType : 'unknown field type'));
		}

		if ($isPatch) {
			$this->patch($request, $collection, $id, $property);
		}

		$object = $this->fetch($request, $collection, $id);
		$html   = $isEdit
			? $this->editFragment($collection, $id, $property)
			: $this->cellFragment($collection, $object, $property, $meta, $editable);

		return $this->rawRenderer->render($response->withHeader('Content-Type', 'text/html'), $html);
	}

	private function patch(ServerRequestInterface $request, string $collection, string $id, string $property): void
	{
		$body = (array)$request->getParsedBody();
		$data = json_decode((string)($body['data'] ?? '{}'), true);
		if (!is_array($data) || !array_key_exists($property, $data)) {
			throw new HttpBadRequestException($request, "The request must carry `data` JSON containing '{$property}'.");
		}

		$this->fetch($request, $collection, $id); // 404 before we try to write
		$patch = $this->guard->guard($request, $collection, $id, [$property => $data[$property]]);
		$this->patcher->patchObject($collection, $id, $patch);
	}

	private function fetch(ServerRequestInterface $request, string $collection, string $id): ObjectData
	{
		try {
			return $this->objectFetcher->fetchObject($collection, $id);
		} catch (\UnexpectedValueException $e) {
			throw new HttpNotFoundException($request, $e->getMessage());
		}
	}

	/** @param array<string,mixed> $meta */
	private function cellFragment(string $collection, ObjectData $object, string $property, array $meta, bool $editable): string
	{
		return $this->twig->render('admin/collection/table-cell.twig', [
			'object'      => $object->toArray(),
			'col'         => [
				'name'     => $property,
				'type'     => PropertyDefinition::fromArray($meta)->resolveType(),
				'editable' => $editable,
			],
			'_collection' => $collection,
		]);
	}

	private function editFragment(string $collection, string $id, string $property): string
	{
		// A builder form with an id loads the object in init(), so field()
		// renders the current value with the property's real settings,
		// options and presets — the same field the object page shows.
		$form = $this->forms->builder($collection, [
			'id'         => $id,
			'hideID'     => true,
			'save'       => false,
			'delete'     => false,
			'fieldIcons' => false,
			'class'      => 'inline-edit no-save no-status-banner',
		]);

		// No help text in a cell: it belongs on the object form, and here it
		// only pushes the save/cancel pair down. An empty help renders nothing.
		return $this->twig->render('admin/collection/inline-edit.twig', [
			'field'  => $form->field($property, ['help' => '']),
			'action' => "collections/{$collection}/{$id}/cell/{$property}",
		]);
	}
}
