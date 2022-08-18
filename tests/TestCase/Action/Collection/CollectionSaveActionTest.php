<?php

namespace App\Test\TestCase\Action\Collection;

use App\Domain\Collection\Data\CollectionData;
use App\Test\Traits\AppTestTrait;
use Fig\Http\Message\StatusCodeInterface;
use PHPUnit\Framework\TestCase;

/**
 * Test.
 *
 * @coversDefaultClass \App\Action\Collection\CollectionListAction
 */
final class CollectionSaveActionTest extends TestCase
{
    use AppTestTrait;

    /**
     * Test.
     *
     * @return void
     */
    public function testInvoke(): void
    {
        $this->testSaveCustomCollection();
        $this->testSaveDefaultCollection();
        $this->testSaveCollectionWithMalformedSchema();
        $this->testSaveCollectionWithDefaultSchema();
    }

    private function testSaveDefaultCollection(): void
    {
        $url = $this->urlFor('collection-save');

        foreach (CollectionData::RESERVED_COLLECTIONS as $collection) {
            $data = [
                'name'   => $collection,
                'schema' => $collection,
                'url'    => '',
            ];

            $request  = $this->createJsonRequest('POST', $url, $data);
            $response = $this->app->handle($request);

            $expected = ['data' => $data];

            $this->assertJsonData($expected, $response);
            $this->assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
        }
    }

    private function testSaveCustomCollection(): void
    {
        $data = [
            'name'   => 'test',
            'schema' => 'test',
            'url'    => 'test-url',
        ];

        $url      = $this->urlFor('collection-save');
        $request  = $this->createJsonRequest('POST', $url, $data);
        $response = $this->app->handle($request);

        $expected = ['data' => $data];

        $this->assertJsonData($expected, $response);
        $this->assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
    }

    // You cannot save a reserved collection with a custom schema
    private function testSaveCollectionWithMalformedSchema(): void
    {
        $data = [
            'name'   => 'blog',
            'schema' => 'customSchema',
            'url'    => 'https://blog-url.com',
        ];

        $url      = $this->urlFor('collection-save');
        $request  = $this->createJsonRequest('POST', $url, $data);
        $response = $this->app->handle($request);

        $expected = ['error' => [
            'message' => '500 Internal Server Error - Cannot assign custom schema to a reserved collection',
        ]];

        $this->assertJsonData($expected, $response);
        $this->assertSame(StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    }

    // Make sure that users can save a collection with a default schema
    private function testSaveCollectionWithDefaultSchema(): void
    {
        $data = [
            'name'   => 'myblog',
            'schema' => 'blog',
            'url'    => 'https://blog-url.com',
        ];

        $url      = $this->urlFor('collection-save');
        $request  = $this->createJsonRequest('POST', $url, $data);
        $response = $this->app->handle($request);

        $expected = ['data' => $data];

        $this->assertJsonData($expected, $response);
        $this->assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
    }
}
