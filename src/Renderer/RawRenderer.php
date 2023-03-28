<?php

namespace App\Renderer;

use Psr\Http\Message\ResponseInterface;

/**
 * A HTML template renderer.
 */
final class RawRenderer
{
    /**
     * Output raw content.
     *
     * @param ResponseInterface $response The response
     * @param string $content
     *
     * @return ResponseInterface The response
     */
    public function render(ResponseInterface $response, string $content): ResponseInterface
    {
        $response->getBody()->write($content);

        return $response;
    }
}
