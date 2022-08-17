<?php

namespace App\Test\TestCase\Action\Schema;

use App\Test\Traits\AppTestTrait;
use Fig\Http\Message\StatusCodeInterface;
use PHPUnit\Framework\TestCase;
use FilesystemIterator;
use SplFileInfo;

/**
 * Test.
 *
 * @coversDefaultClass \App\Action\Schema\SchemaSaver
 */
final class SchemaSaveActionTest extends TestCase
{
    use AppTestTrait;

    private const SCHEMAS_DIR = __DIR__ . '/../../../../schemas/';

    /**
     * Test.
     *
     * @return void
     */
    public function testInvoke(): void
    {
        $this->testReservedSchemas();
        $this->testInvalidSchema();
        $this->testMalformedSchema();
        $this->testSaveNewSchema();
    }

    private function testSaveNewSchema(): void
    {
        $path = self::SCHEMAS_DIR . 'blog.json';
        /* @phpstan-ignore-next-line */
        $blogSchema = json_decode(file_get_contents($path), true);

        $blogSchema['$id'] = 'https://www.totalcms.co/schemas/custom/newblog.json';

        $url = $this->urlFor('schema-save');
        $request = $this->createJsonRequest('POST', $url, $blogSchema);
        $response = $this->app->handle($request);

        $expected = [
            'data' => $blogSchema
        ];

        $this->assertJsonData($expected, $response);
        $this->assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
    }

    private function testInvalidSchema(): void
    {
        $path = self::SCHEMAS_DIR . 'blog.json';
        /* @phpstan-ignore-next-line */
        $blogSchema = json_decode(file_get_contents($path), true);

        // Invalidate the Schema
        unset($blogSchema['$id']);

        $url = $this->urlFor('schema-save');
        $request = $this->createJsonRequest('POST', $url, $blogSchema);
        $response = $this->app->handle($request);

        $expected = [
            'error' => [
                'message' => '500 Internal Server Error - Invalid schema data provided'
            ]
        ];

        $this->assertJsonData($expected, $response);
        $this->assertSame(StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    }

    private function testMalformedSchema(): void
    {
        $path = self::SCHEMAS_DIR . 'blog.json';
        /* @phpstan-ignore-next-line */
        $blogSchema = json_decode(file_get_contents($path), true);

        // Invalidate the Schema (removed # in URI)
        $blogSchema['$id'] = "https://www.totalcms.co/schemas/custom/newblog.json";

        $url = $this->urlFor('schema-save');
        $request = $this->createJsonRequest('POST', $url, $blogSchema);
        $response = $this->app->handle($request);

        $expected = [
            'error' => [
                'message' => '500 Internal Server Error - Malformed schema data provided'
            ]
        ];

        $this->assertJsonData($expected, $response);
        $this->assertSame(StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    }

    private function testReservedSchemas(): void
    {
        $url = $this->urlFor('schema-save');

        // Let's traverse the schemas directory
        $fileSystemIterator = new FilesystemIterator(self::SCHEMAS_DIR);

        foreach ($fileSystemIterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo || $fileInfo->getExtension() !== 'json') {
                continue;
            }
            /* @phpstan-ignore-next-line */
            $schema = json_decode(file_get_contents($fileInfo->getRealPath()), true);
            $type = $fileInfo->getBasename('.json');

            $request = $this->createJsonRequest('POST', $url, $schema);
            $response = $this->app->handle($request);

            $expected = [
                'error' => [
                    'message' => "500 Internal Server Error - Schema type ($type) is reserved"
                ]
            ];

            $this->assertJsonData($expected, $response);
            $this->assertSame(StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        }
    }
}
