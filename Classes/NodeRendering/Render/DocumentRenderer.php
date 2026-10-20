<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\Render;

use Exception as PhpException;
use Flowpack\DecoupledContentStore\Aspects\CacheUrlMappingAspect;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\Exception;
use Flowpack\DecoupledContentStore\NodeRendering\NodeRenderingUriService;
use Flowpack\DecoupledContentStore\Transfer\Resource\Target\MultisiteFileSystemSymlinkTarget;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Helper\ResponseInformationHelper;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\ActionResponse;
use Neos\Flow\ResourceManagement\ResourceManager;
use Psr\Http\Message\ResponseInterface;

#[Flow\Scope('singleton')]
final class DocumentRenderer
{
    private bool $isRendering = false;

    public function __construct(
        private readonly NodeRenderingUriService $nodeRenderingUriService,
        private readonly CustomFusionView $fusionView,
        private readonly ResourceManager $resourceManager,
        private readonly CacheUrlMappingAspect $cacheUrlMappingAspect,
        #[Flow\InjectConfiguration('nodeRendering.useRelativeResourceUris')]
        protected readonly bool $useRelativeResourceUris,
        /**
         * Add HTTP message if rendering full content
         */
        #[Flow\InjectConfiguration('nodeRendering.addHttpMessage')]
        protected readonly bool $addHttpMessage,
    ) {}

    /**
     * Render a specific node variant
     *
     * @param Node $node
     * @param array $arguments Request arguments when rendering the node
     * @return string the rendered document (not needed inside this package, but might be useful for others who want to trigger the rendering)
     * @throws Exception\RenderingException
     */
    public function renderDocumentNodeVariant(
        Node $node,
        array $arguments,
        ContentReleaseLogger $contentReleaseLogger,
    ): string {
        $this->cacheUrlMappingAspect->beforeDocumentRendering($contentReleaseLogger);
        $nodeUri = $this->nodeRenderingUriService->buildNodeUri($node, $arguments);

        try {
            $arguments['node'] = NodeAddress::fromNode($node)->toJson();
            return $this->renderDocumentView($node, $nodeUri, $arguments, $contentReleaseLogger);
        } catch (PhpException $exception) {
            throw new Exception\RenderingException('Error rendering document view', $nodeUri, 1491378709, $exception);
        } finally {
            $this->cacheUrlMappingAspect->afterDocumentRendering();
        }
    }

    /**
     * Render the view of a document node
     *
     * This will set up a simulated request for rendering the view. If the output contains a "next link"
     * it will render
     *
     * @param Node $node
     * @param string $uri The URI for the node
     * @param array $requestArguments Plain request arguments (e.g. node by context path), could come from routing match results
     * @return string the rendered output
     * @throws Exception\InvalidSiteConfigurationException
     */
    private function renderDocumentView(
        Node $node,
        string $uri,
        array $requestArguments,
        ContentReleaseLogger $contentReleaseLogger,
    ): string {
        $this->isRendering = true;

        try {
            $site = $this->nodeRenderingUriService->findSiteForNode(
                $node,
            ) ?? throw new Exception\InvalidSiteConfigurationException(
                'No site node found for node ' . $node->aggregateId->value,
                1762862401,
            );
            $domain = $site->getFirstActiveDomain();
            $baseUri = (string) $domain;
            if ($baseUri === '') {
                throw new Exception\InvalidSiteConfigurationException(
                    'Cannot render content without active domain for site "' . $site->getName() . '"',
                    1467289645,
                );
            }

            $contentReleaseLogger->info('Rendering document for URI ' . $uri, ['baseUri' => $baseUri]);

            $controllerContext = $this->nodeRenderingUriService->buildControllerContextAndSetBaseUri(
                $uri,
                $node,
                $requestArguments,
            );
            /** @var ActionRequest $request */
            $request = $controllerContext->getRequest();
            $request->setArguments($requestArguments);

            $resourceBaseUri = $this->useRelativeResourceUris ? '' : $baseUri;

            MultisiteFileSystemSymlinkTarget::injectBaseUriIntoRelevantResourcePublishingTargets(
                $resourceBaseUri,
                $this->resourceManager,
            );

            $this->fusionView->setFusionPath('documentRendering');
            $this->fusionView->setControllerContext($controllerContext);
            $this->fusionView->assign('value', $node);

            $output = $this->fusionView->render();
            if ($output instanceof ResponseInterface) {
                return $this->addHttpMessage
                    ? implode("\r\n", ResponseInformationHelper::prepareHeaders($output))
                    . "\r\n"
                    . $output->getBody()->getContents()
                    : $output->getBody()->getContents();
            }

            return $this->addHttpMessage
                ? self::wrapInHttpMessage($output->getContents(), $controllerContext->getResponse())
                : $output->getContents();
        } finally {
            $this->isRendering = false;
        }
    }

    /**
     * Because of PSR-7, it is more difficult to extract the HTTP Headers from the ActionResponse. See inline comments for detailed explanations.
     *
     * @param string $output
     * @param ActionResponse $response
     * @return string
     */
    private static function wrapInHttpMessage(string $output, ActionResponse $response): string
    {
        $headerLines = [];
        foreach ($response->buildHttpResponse()->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $headerLines[] = $name . ': ' . $value;
            }
        }

        // Finally, we build the HTTP response.
        return 'HTTP/1.1' . (empty($headerLines) ? "\r\n" : implode("\r\n", $headerLines)) . "\r\n" . $output;
    }

    /**
     * @return bool
     */
    public function isRendering(): bool
    {
        return $this->isRendering;
    }

    public function disableCache(): void
    {
        $this->fusionView->disableCache();
    }

    public function enableCache(): void
    {
        $this->fusionView->enableCache();
    }
}
