<?php

namespace Flowpack\DecoupledContentStore\NodeRendering\Render;

use Flowpack\DecoupledContentStore\Aspects\CacheUrlMappingAspect;
use Flowpack\DecoupledContentStore\Exception;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\NodeRendering\NodeRenderingUriService;
use Flowpack\DecoupledContentStore\Transfer\Resource\Target\MultisiteFileSystemSymlinkTarget;
use GuzzleHttp\Psr7\ServerRequest;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\Http\BaseUriProvider;
use Neos\Flow\Http\Helper\RequestInformationHelper;
use Neos\Flow\Http\Helper\ResponseInformationHelper;
use Neos\Flow\Http\ServerRequestAttributes;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\ActionResponse;
use Neos\Flow\Mvc\Controller\Arguments;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\Dto\RouteParameters;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Neos\Domain\Model\Site;
use Neos\Utility\ObjectAccess;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * @Flow\Scope("singleton")
 */
class DocumentRenderer
{
    /**
     * @Flow\InjectConfiguration("nodeRendering.useRelativeResourceUris")
     * @var bool
     */
    protected $useRelativeResourceUris;

    /**
     * @Flow\Inject
     * @var NodeRenderingUriService
     */
    protected $nodeRenderingUriService;

    /**
     * Add HTTP message if rendering full content
     *
     * @Flow\InjectConfiguration("nodeRendering.addHttpMessage")
     * @var bool
     */
    protected $addHttpMessage;

    /**
     * @Flow\Inject
     * @var CustomFusionView
     */
    protected $fusionView;

    /**
     * @Flow\Inject(lazy=false)
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * @Flow\Inject
     * @var CacheUrlMappingAspect
     */
    protected $cacheUrlMappingAspect;

    /**
     * @var bool
     */
    protected $isRendering = false;

    /**
     * Render a specific node variant
     *
     * @param NodeInterface $node
     * @param array $arguments Request arguments when rendering the node
     * @return string the rendered document (not needed inside this package, but might be useful for others who want to trigger the rendering)
     * @throws Exception\RenderingException
     */
    public function renderDocumentNodeVariant(NodeInterface $node, array $arguments, ContentReleaseLogger $contentReleaseLogger): string
    {
        $this->cacheUrlMappingAspect->beforeDocumentRendering($contentReleaseLogger);
        $nodeUri = $this->nodeRenderingUriService->buildNodeUri($node, $arguments);

        try {
            $arguments['node'] = $node->getContextPath();
            return $this->renderDocumentView($node, $nodeUri, $arguments, $contentReleaseLogger);
        } catch (\Exception $exception) {
            throw new Exception\RenderingException('Error rendering document view', $node, $nodeUri, 1491378709, $exception);
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
     * @param NodeInterface $node
     * @param string $uri The URI for the node
     * @param array $requestArguments Plain request arguments (e.g. node by context path), could come from routing match results
     * @return string the rendered output
     * @throws Exception\InvalidSiteConfigurationException
     */
    protected function renderDocumentView(NodeInterface $node, $uri, array $requestArguments, ContentReleaseLogger $contentReleaseLogger): string
    {
        $this->isRendering = true;

        try {
            /** @var ContentContext $contentContext */
            $contentContext = $node->getContext();
            $site = $contentContext->getCurrentSite();
            $domain = $site->getFirstActiveDomain();
            $baseUri = (string)$domain;
            if ($baseUri === '') {
                throw new Exception\InvalidSiteConfigurationException(
                    'Cannot render content without active domain for site "' . $site->getName() . '"', 1467289645
                );
            }

            $contentReleaseLogger->info('Rendering document for URI ' . $uri, ['baseUri' => $baseUri]);

            $controllerContext = $this->nodeRenderingUriService->buildControllerContextAndSetBaseUri($uri, $node, $requestArguments);
            /** @var ActionRequest $request */
            $request = $controllerContext->getRequest();
            $request->setArguments($requestArguments);

            $resourceBaseUri = $this->useRelativeResourceUris ? '' : $baseUri;

            MultisiteFileSystemSymlinkTarget::injectBaseUriIntoRelevantResourcePublishingTargets($resourceBaseUri, $this->resourceManager);

            $this->fusionView->setFusionPath('documentRendering');
            $this->fusionView->setControllerContext($controllerContext);
            $this->fusionView->assign('value', $node);

            $output = $this->fusionView->render();
            if ($this->addHttpMessage) {
                if ($output instanceof ResponseInterface) {
                    $output = implode("\r\n", ResponseInformationHelper::prepareHeaders($output)) . "\r\n" . $output->getBody()->getContents();
                } else {
                    $output = self::wrapInHttpMessage($output, $controllerContext->getResponse());
                }
            } else {
                if ($output instanceof ResponseInterface) {
                    $output = $output->getBody()->getContents();
                }
            }
            return $output;
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
                $headerLines[] = $name . ": " . $value;
            }
        }

        // Finally, we build the HTTP response.
        return "HTTP/1.1" . (empty($headerLines) ? "\r\n" : implode("\r\n", $headerLines)) . "\r\n" . $output;
    }

    /**
     * @return bool
     */
    public function isRendering()
    {
        return $this->isRendering;
    }

    public function disableCache()
    {
        $this->fusionView->disableCache();
    }

    public function enableCache()
    {
        $this->fusionView->enableCache();
    }
}
