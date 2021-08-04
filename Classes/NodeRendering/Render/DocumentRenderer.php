<?php
namespace Flowpack\DecoupledContentStore\NodeRendering\Render;
use Flowpack\DecoupledContentStore\Aspects\CacheUrlMappingAspect;
use Flowpack\DecoupledContentStore\Exception;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\Resource\Target\MultisiteFileSystemSymlinkTarget;
use GuzzleHttp\Psr7\ServerRequest;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\Http\BaseUriProvider;
use Neos\Flow\Http\Helper\RequestInformationHelper;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\ActionResponse;
use Neos\Flow\Mvc\Controller\Arguments;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Neos\Domain\Model\Site;
use Neos\Utility\ObjectAccess;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\ContentRepository\Domain\Model\NodeInterface;

/**
 * @Flow\Scope("singleton")
 */
class DocumentRenderer
{
    /**
     * @Flow\InjectConfiguration("publishing.useRelativeResourceUris")
     * @var bool
     */
    protected $useRelativeResourceUris;

    /**
     * @Flow\Inject
     * @var CustomFusionView
     */
    protected $fusionView;

    /**
     * @Flow\Inject
     * @var SecurityContext
     */
    protected $securityContext;

    /**
     * @Flow\Inject
     * @var ConfigurationManager
     */
    protected $configurationManager;

    /**
     * @Flow\Inject
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * @Flow\Inject
     * @var \Neos\Neos\Service\LinkingService
     */
    protected $linkingService;

    /**
     * NOTE: we need to use EAGER injection here, because in buildControllerContextAndSetBaseUri(), we
     * directly set a property of the BaseUriProvider using ObjectAccess::setProperty() with forceDirectAccess.
     *
     * @Flow\Inject(lazy=false)
     * @var BaseUriProvider
     */
    protected $baseUriProvider;

    /**
     * @var \Neos\Flow\Mvc\Routing\Router
     * @Flow\Inject
     */
    protected $router;

    /**
     * @Flow\Inject
     * @var \Neos\Flow\Property\PropertyMapper
     */
    protected $propertyMapper;

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
     * @throws Exception\RenderingException
     */
    public function renderDocumentNodeVariant(NodeInterface $node, array $arguments, ContentReleaseLogger $contentReleaseLogger): void
    {
        $this->cacheUrlMappingAspect->setContentReleaseLoggerForThisRendering($contentReleaseLogger);
        $nodeUri = $this->buildNodeUri($node, $arguments);
        $this->cacheUrlMappingAspect->resetContentReleaseLogger();

        try {
            $arguments['node'] = $node->getContextPath();
            $this->renderDocumentView($node, $nodeUri, $arguments, $contentReleaseLogger);
        } catch (\Exception $exception) {
            throw new Exception\RenderingException('Error rendering document view', $node, $nodeUri, 1491378709, $exception);
        }
    }

    /**
     * @param string $uri
     * @param NodeInterface $node
     * @param array $arguments
     * @return ControllerContext
     */
    protected function buildControllerContextAndSetBaseUri(string $uri, NodeInterface $node, array $arguments = [])
    {
        $request = $this->getRequest($uri, $node);
        if (isset($arguments['@format'])) {
            $request->setFormat($arguments['@format']);
        }

        // NASTY SIDE-EFFECT: we not only build the controller context, but we also need to inject the "current" base URL to BaseUriProvider,
        // as this is now (Flow 6.x) used in the UriBuilder to determine the domain.
        $baseUri = rtrim(RequestInformationHelper::generateBaseUri($request->getHttpRequest()), '/');
        ObjectAccess::setProperty($this->baseUriProvider, 'configuredBaseUri', $baseUri, true);

        ObjectAccess::setProperty($this->securityContext, 'initialized', true, true);
        $this->securityContext->setRequest($request);
        $uriBuilder = $this->getUriBuilder($request);

        return new ControllerContext(
            $request,
            new ActionResponse(),
            new Arguments(array()),
            $uriBuilder
        );
    }

    /**
     * @param ActionRequest $request
     * @return UriBuilder
     * @throws \Neos\Utility\Exception\PropertyNotAccessibleException
     */
    protected function getUriBuilder($request)
    {
        $uriBuilder = new UriBuilder();
        $uriBuilder->setRequest($request);

        $routesConfiguration = $this->configurationManager->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_ROUTES);
        $router = ObjectAccess::getProperty($uriBuilder, 'router', true);

        $router->setRoutesConfiguration($routesConfiguration);

        return $uriBuilder;
    }

    /**
     * @param string $uri
     * @param NodeInterface $node
     * @return ActionRequest
     */
    protected function getRequest($uri, NodeInterface $node)
    {
        $_SERVER['FLOW_REWRITEURLS'] = '1';

        $httpRequest = new ServerRequest('GET', $uri);

        $request = ActionRequest::fromHttpRequest($httpRequest);
        $request->setControllerObjectName('Neos\Neos\Controller\Frontend\NodeController');
        $request->setControllerActionName('show');
        $request->setFormat('html');
        $request->setArgument('node', $node->getContextPath());

        return $request;
    }

    /**
     * Override the baseUri of resource publishing targets
     *
     * This is needed because the rendering might be executed in a CLI request that doesn't have
     * a "current" base URI. It's also needed to render nodes for multiple sites.
     *
     * @param string $baseUri
     * @throws \Neos\Utility\Exception\PropertyNotAccessibleException
     */
    protected function injectBaseUriIntoResourcePublishingTargets($baseUri)
    {
        // Make sure the base URI ends with a slash
        $baseUri = rtrim($baseUri, '/') . '/';

        $collections = $this->resourceManager->getCollections();
        /** @var \Neos\Flow\ResourceManagement\Collection $collection */
        foreach ($collections as $collection) {
            $target = $collection->getTarget();
            if ($target instanceof MultisiteFileSystemSymlinkTarget) {
                $target->setOverrideHttpBaseUri($baseUri);
            }
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
     * @param array $renderedUris Collect rendered URIs
     * @return void
     * @throws Exception\InvalidSiteConfigurationException
     */
    protected function renderDocumentView(NodeInterface $node, $uri, array $requestArguments, ContentReleaseLogger $contentReleaseLogger): void
    {
        $this->isRendering = true;

        $output = '';

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

            $contentReleaseLogger->debug('Rendering document for URI ' . $uri, ['baseUri' => $baseUri]);

            $controllerContext = $this->buildControllerContextAndSetBaseUri($uri, $node, $requestArguments);
            /** @var ActionRequest $request */
            $request = $controllerContext->getRequest();
            $request->setArguments($requestArguments);

            $resourceBaseUri = $this->useRelativeResourceUris ? '' : $baseUri;

            $this->injectBaseUriIntoResourcePublishingTargets($resourceBaseUri);

            $this->fusionView->setFusionPath('documentRendering');
            $this->fusionView->setControllerContext($controllerContext);
            $this->fusionView->assign('value', $node);

            $this->fusionView->render();
        } finally {
            $this->isRendering = false;
        }
    }

    /**
     * @param NodeInterface $node
     * @param array $arguments
     * @return string The resolved URI for the given node
     */
    protected function buildNodeUri(NodeInterface $node, array $arguments)
    {
        /** @var Site $currentSite */
        $currentSite = $node->getContext()->getCurrentSite();
        if (!$currentSite->hasActiveDomains()) {
            throw new \Exception("No configured domain!");
        }
        // HINT: We cannot use a static URL here, but instead need to use an URL of the current site.
        // This is changed from the the old behavior, where we have changed the LinkingService in LinkingServiceAspect,
        // to properly generate the domain part of the routes - and this relies on the proper ControllerContext URI path.
        $baseControllerContext = $this->buildControllerContextAndSetBaseUri($currentSite->getPrimaryDomain()->__toString(), $node, $arguments);
        $format = $arguments['@format'] ?? 'html';
        $uri = $this->linkingService->createNodeUri($baseControllerContext, $node, null, $format, true, $arguments, '', false, [], false);
        return $this->removeQueryPartFromUri($uri);
    }

    /**
     * @return bool
     */
    public function isRendering()
    {
        return $this->isRendering;
    }

    /**
     * @param string $uri
     * @return string
     */
    protected function removeQueryPartFromUri($uri)
    {
        $uriData = explode('?', $uri);

        return $uriData[0];
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
