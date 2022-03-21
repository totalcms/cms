<?php

namespace App\Test\TestCase\Action\Schema;

use App\Test\Traits\AppTestTrait;
use Fig\Http\Message\StatusCodeInterface;
use PHPUnit\Framework\TestCase;

/**
 * Test.
 *
 * @coversDefaultClass \App\Action\Collection\Schema\SchemaSaveAction
 */
final class SchemaSaveActionTest extends TestCase
{
    use AppTestTrait;

    /**
     * Test.
     *
     * @return void
     */
    public function testInvoke(): void
    {
        // file_put_contents($this->path . '')
        $data = [
            'title' => 'test',
            'description' => 'test description',
            'type' => 'object',
            'index' => [],
            'required' => [],
            'properties' => [
                'key' => 'value',
            ],
        ];

        $url = $this->urlFor('schema-save', ['collection' => 'test']);
        $request = $this->createJsonRequest('POST', $url, $data);
        $response = $this->app->handle($request);

        $expected = [
            'data' => [
                'title' => 'test',
                'description' => 'test description',
                'type' => 'object',
                'index' => [],
                'required' => [],
                'properties' => [
                    'key' => 'value',
                ],
            ],
        ];

        $this->assertJsonData($expected, $response);
        $this->assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
    }
}
