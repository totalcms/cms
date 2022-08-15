<?php

namespace App\Test\TestCase\Action\Collection;

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
        $data = [
            'name'   => 'test',
            'schema' => 'test',
            'url'    => 'test-url',
        ];

        $url = $this->urlFor('collection-save');
        $request = $this->createJsonRequest('POST', $url, $data);
        $response = $this->app->handle($request);

        $expected = [
            'data' => $data,
        ];

        $this->assertJsonData($expected, $response);
        $this->assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
    }
}
