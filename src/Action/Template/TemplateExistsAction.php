<?php

namespace TotalCMS\Action\Template;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\Template\Service\TemplateFetcher;

final class TemplateExistsAction
{
    private TemplateFetcher $templateFetcher;

    public function __construct(TemplateFetcher $service)
    {
        $this->templateFetcher = $service;
    }

    /**
     * Action.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param array $args The routing arguments
     *
     * @return ResponseInterface the response
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $exists = $this->templateFetcher->templateExists($args['template']);

        if ($exists === false) {
            throw new HttpNotFoundException($request);
        }

        return $response;
    }
}
