<?php

namespace TotalCMS\Domain\Admin\FormField;

/**
 * Total Form Field Builder.
 */
final class TextField extends FormField
{
    public function __construct()
    {
    }

    public function build(): string
    {
        $attributes = [
            // 'class'           => "totalform {$this->class}",
            // 'data-schema'     => $this->collectionData->schema,
            // 'data-collection' => $this->collection,
            // 'data-method'     => $this->method,
            // 'data-api'        => $this->api,
            // 'data-route'      => $this->route,
        ];

        return self::createHTMLElement('form', $this->fieldContent(), $attributes);
    }
}
