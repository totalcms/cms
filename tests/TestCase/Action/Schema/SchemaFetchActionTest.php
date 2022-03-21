<?php

namespace App\Test\TestCase\Action\Schema;

use App\Test\Traits\AppTestTrait;
use Fig\Http\Message\StatusCodeInterface;
use PHPUnit\Framework\TestCase;

/**
 * Test.
 *
 * @coversDefaultClass \App\Action\Collection\Schema\SchemaFetchAction
 */
final class SchemaFetchActionTest extends TestCase
{
    use AppTestTrait;

    /**
     * Test.
     *
     * @return void
     */
    public function testInvoke(): void
    {
        $content = json_encode(
            [
                'schema' => 'url',
            ]
        );
        $this->filesystem->write('test/.meta.json', (string)$content);
        // $this->filesystem->write('url/.schema.json', '{}');

        $url = $this->urlFor('schema-fetch', ['collection' => 'test']);
        $request = $this->createRequest('GET', $url);
        $response = $this->app->handle($request);

        $expected = [
            'data' => [
                'title' => '',
                'description' => 'A schema for a Total CMS URL object',
                'type' => 'object',
                'index' => [
                    0 => 'id',
                ],
                'required' => [
                    0 => 'id',
                    1 => 'url',
                ],
                'properties' => [
                    'id' => [
                        '$ref' => 'https://www.totalcms.co/schemas/properties/id.json#',
                    ],
                    'url' => [
                        '$ref' => 'https://www.totalcms.co/schemas/properties/url.json#',
                    ],
                ],
            ],
        ];

        $this->assertJsonData($expected, $response);
        $this->assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
    }
}
