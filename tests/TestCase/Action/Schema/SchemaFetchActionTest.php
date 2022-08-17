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
 * @coversDefaultClass \App\Action\Schema\SchemaFetcher
 */

final class SchemaFetchActionTest extends TestCase
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
        $this->testFetchNewCustomSchema();
        $this->testFetchReservedSchemas();
    }

    private function testFetchNewCustomSchema(): void
    {
        // Need to create a custom schema so that we can fetch it
        // This is double testing over the Save action. There may be a better way

        $path = self::SCHEMAS_DIR . 'blog.json';
        /* @phpstan-ignore-next-line */
        $blogSchema = json_decode(file_get_contents($path), true);

        $type = 'newblog';
        $blogSchema['$id'] = "https://www.totalcms.co/schemas/custom/$type.json";

        $url = $this->urlFor('schema-save');
        $request = $this->createJsonRequest('POST', $url, $blogSchema);
        $response = $this->app->handle($request);

        $expected = [
            'data' => $blogSchema
        ];

        $this->assertJsonData($expected, $response);
        $this->assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());

        // Fetch the custom schema

        $url = $this->urlFor('schema-fetch', ['type' => $type]);
        $request = $this->createRequest('GET', $url);
        $response = $this->app->handle($request);

        $this->assertJsonData($expected, $response);
        $this->assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
    }

    private function testFetchReservedSchemas(): void
    {
        // Let's traverse the schemas directory test all of the schemas
        $fileSystemIterator = new FilesystemIterator(self::SCHEMAS_DIR);

        foreach ($fileSystemIterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo || $fileInfo->getExtension() !== 'json') {
                continue;
            }
            /* @phpstan-ignore-next-line */
            $schema = json_decode(file_get_contents($fileInfo->getRealPath()), true);
            $type = $fileInfo->getBasename('.json');

            $url = $this->urlFor('schema-fetch', ['type' => $type]);
            $request = $this->createRequest('GET', $url);
            $response = $this->app->handle($request);

            $expected = [
                'data' => $schema
            ];

            $this->assertJsonData($expected, $response);
            $this->assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
        }
    }
}
