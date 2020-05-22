<?php

namespace App\Responder;

use App\Routing\UrlGenerator;
use League\Fractal\Manager as FractalManager;
use League\Fractal\Resource\Collection as FractalCollection;
use League\Fractal\Resource\Item as FractalItem;
use League\Fractal\TransformerAbstract;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A generic responder.
 */
final class Responder
{
    private UrlGenerator $urlGenerator;
    private ResponseFactoryInterface $responseFactory;
    private FractalManager $fractal;

    /**
     * The constructor.
     *
     * @param UrlGenerator             $urlGenerator    The url generator
     * @param ResponseFactoryInterface $responseFactory The response factory
     * @param FractalManager           $fractal         fractal response manager
     */
    public function __construct(UrlGenerator $urlGenerator, ResponseFactoryInterface $responseFactory, FractalManager $fractal)
    {
        $this->urlGenerator    = $urlGenerator;
        $this->responseFactory = $responseFactory;
        $this->fractal         = $fractal;
    }

    /**
     * Creates a redirect for the given url / route name.
     *
     * This method prepares the response object to return an HTTP Redirect
     * response to the client.
     *
     * @param ResponseInterface $response    The response
     * @param string            $destination The redirect destination (url or route name)
     * @param array<mixed>      $data        Named argument replacement data
     * @param array<mixed>      $queryParams Optional query string parameters
     *
     * @return ResponseInterface The response
     */
    public function redirect(ResponseInterface $response, string $destination, array $data = [], array $queryParams = []) : ResponseInterface
    {
        if (!filter_var($destination, FILTER_VALIDATE_URL)) {
            $destination = $this->urlGenerator->fullUrlFor($destination, $data, $queryParams);
        }

        return $response->withStatus(302)->withHeader('Location', $destination);
    }

    /**
     * Write JSON to the response body.
     *
     * This method prepares the response object to return an HTTP JSON
     * response to the client.
     *
     * @param ResponseInterface   $response    The response
     * @param array<mixed>        $collection  The data
     * @param TransformerAbstract $transformer the data transformer
     *
     * @return ResponseInterface The response
     */
    public function jsonCollection(ResponseInterface $response, array $collection, TransformerAbstract $transformer) : ResponseInterface
    {
        $resource = new FractalCollection($collection, $transformer);
        $response = $response->withHeader('Content-Type', 'application/json');
        $response->getBody()->write($this->fractal->createData($resource)->toJson());

        return $response;
    }

    /**
     * Write JSON to the response body.
     *
     * This method prepares the response object to return an HTTP JSON
     * response to the client.
     *
     * @param ResponseInterface   $response    The response
     * @param object              $item        The data
     * @param TransformerAbstract $transformer the data transformer
     *
     * @return ResponseInterface The response
     */
    public function jsonItem(ResponseInterface $response, object $item, TransformerAbstract $transformer) : ResponseInterface
    {
        $resource = new FractalItem($item, $transformer);
        $response = $response->withHeader('Content-Type', 'application/json');
        $response->getBody()->write($this->fractal->createData($resource)->toJson());

        return $response;
    }
}
