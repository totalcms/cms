<?php

namespace App\Test\TestCase\Action\Collection;

use App\Test\Traits\AppTestTrait;
use Fig\Http\Message\StatusCodeInterface;
use PHPUnit\Framework\TestCase;

/**
 * Test.
 *
 * @coversDefaultClass \App\Action\Collection\CollectionListActionTest
 */
final class CollectionListActionTest extends TestCase
{
    use AppTestTrait;

    /**
     * Test.
     *
     * @return void
     */
    public function testInvoke(): void
    {
        $collections = [
            [
                'name'   => 'test',
                'schema' => 'test',
                'url'    => 'test-url',
            ],
            [
                'name'   => 'test2',
                'schema' => 'test2',
                'url'    => '',
            ],
        ];

        foreach ($collections as $collection) {
            $url = $this->urlFor('collection-save');
            $request = $this->createJsonRequest('POST', $url, $collection);
            $response = $this->app->handle($request);

            $expected = [
                'data' => $collection,
            ];

            $this->assertJsonData($expected, $response);
            $this->assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
        }

        $url = $this->urlFor('collections-list');
        $request = $this->createRequest('GET', $url);
        $response = $this->app->handle($request);

        $expected = [
            'data' => $collections,
        ];

        $this->assertJsonData($expected, $response);
        $this->assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
    }
}
