<?php

namespace App\Responder;

use League\Fractal\Manager as FractalManager;
use League\Fractal\Resource\Collection as FractalCollection;
use League\Fractal\Resource\Item as FractalItem;
use League\Fractal\TransformerAbstract;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Interfaces\RouteParserInterface;

/**
 * A generic responder.
 */
final class Responder
{
    private RouteParserInterface $routeParser;
    private ResponseFactoryInterface $responseFactory;
    private FractalManager $fractal;

    /**
     * The constructor.
     *
     * @param ResponseFactoryInterface $responseFactory The response factory
     * @param FractalManager $fractal fractal response manager
     * @param RouteParserInterface $routeParser
     */
    public function __construct(
        RouteParserInterface $routeParser,
        ResponseFactoryInterface $responseFactory,
        FractalManager $fractal
    ) {
        $this->routeParser = $routeParser;
        $this->responseFactory = $responseFactory;
        $this->fractal = $fractal;
    }

    /**
     * Create a new response.
     *
     * @return ResponseInterface The response
     */
    public function createResponse(): ResponseInterface
    {
        return $this->responseFactory->createResponse()->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Creates a redirect for the given url / route name.
     *
     * This method prepares the response object to return an HTTP Redirect
     * response to the client.
     *
     * @param ResponseInterface $response The response
     * @param string $destination The redirect destination (url or route name)
     * @param array $queryParams Optional query string parameters
     *
     * @return ResponseInterface The response
     */
    public function withRedirect(
        ResponseInterface $response,
        string $destination,
        array $queryParams = []
    ): ResponseInterface {
        if ($queryParams) {
            $destination = sprintf('%s?%s', $destination, http_build_query($queryParams));
        }

        return $response->withStatus(302)->withHeader('Location', $destination);
    }

    /**
     * Creates a redirect for the given url / route name.
     *
     * This method prepares the response object to return an HTTP Redirect
     * response to the client.
     *
     * @param ResponseInterface $response The response
     * @param string $routeName The redirect route name
     * @param array $data Named argument replacement data
     * @param array $queryParams Optional query string parameters
     *
     * @return ResponseInterface The response
     */
    public function withRedirectFor(
        ResponseInterface $response,
        string $routeName,
        array $data = [],
        array $queryParams = []
    ): ResponseInterface {
        return $this->withRedirect($response, $this->routeParser->urlFor($routeName, $data, $queryParams));
    }

    /**
     * Write JSON to the response body.
     *
     * This method prepares the response object to return an HTTP JSON
     * response to the client.
     *
     * @param ResponseInterface $response The response
     * @param array $collection The data
     * @param TransformerAbstract $transformer the data transformer
     *
     * @return ResponseInterface The response
     */
    public function jsonCollection(
        ResponseInterface $response,
        array $collection,
        TransformerAbstract $transformer
    ): ResponseInterface {
        $resource = new FractalCollection($collection, $transformer);

        return $this->withJson($response, $this->fractal->createData($resource)->toArray());
    }

    /**
     * Write JSON to the response body.
     *
     * This method prepares the response object to return an HTTP JSON
     * response to the client.
     *
     * @param ResponseInterface $response The response
     * @param object $item The data
     * @param TransformerAbstract $transformer the data transformer
     *
     * @return ResponseInterface The response
     */
    public function jsonItem(
        ResponseInterface $response,
        object $item,
        TransformerAbstract $transformer
    ): ResponseInterface {
        $resource = new FractalItem($item, $transformer);

        return $this->withJson($response, $this->fractal->createData($resource)->toArray());
    }

    /**
     * Write JSON to the response body.
     *
     * This method prepares the response object to return an HTTP JSON
     * response to the client.
     *
     * @param ResponseInterface $response The response
     * @param mixed $data The data
     * @param int $options Json encoding options
     *
     * @return ResponseInterface The response
     */
    public function withJson(
        ResponseInterface $response,
        mixed $data = null,
        int $options = 0
    ): ResponseInterface {
        $response = $response->withHeader('Content-Type', 'application/json');
        $response->getBody()->write((string)json_encode($data, $options));

        return $response;
    }
}
