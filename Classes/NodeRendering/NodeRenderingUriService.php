<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering;

use Neos\Flow\Annotations as Flow;
use Flowpack\DecoupledContentStore\Exception;
use Flowpack\DecoupledContentStore\NodeRendering\Extensibility\DocumentRendererInterface;
use GuzzleHttp\Psr7\ServerRequest;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\Http\BaseUriProvider;
use Neos\Flow\Http\Helper\RequestInformationHelper;
use Neos\Flow\Http\ServerRequestAttributes;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\ActionResponse;
use Neos\Flow\Mvc\Controller\Arguments;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\Dto\RouteParameters;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Service\LinkingService;
use Neos\Utility\ObjectAccess;

/**
 * Helper which is able to render a Node URI in headless / CLI mode. Required usually inside
 * {@see DocumentRendererInterface::renderDocumentNodeVariant()}
 */
final class NodeRenderingUriService
{
    #[Flow\Inject]
    protected ConfigurationManager $configurationManager;

    /**
     * NOTE: we need to use EAGER injection here, because in buildControllerContextAndSetBaseUri(), we
     * directly set a property of the BaseUriProvider using ObjectAccess::setProperty() with forceDirectAccess.
     */
    #[Flow\Inject(lazy: false)]
    protected BaseUriProvider $baseUriProvider;

    #[Flow\Inject]
    protected LinkingService $linkingService;

    #[Flow\Inject]
    protected SecurityContext $securityContext;

    /**
     * @param NodeInterface $node
     * @param array $arguments
     * @return string The resolved URI for the given node
     * @throws \Exception
     */
    public function buildNodeUri(NodeInterface $node, array $arguments): string
    {
        /** @var Site $currentSite */
        $currentSite = $node->getContext()->getCurrentSite();
        if (!$currentSite->hasActiveDomains()) {
            throw new Exception(sprintf("Site %s has no active domain", $currentSite->getNodeName()), 1666684522);
        }
        $primaryDomain = $currentSite->getPrimaryDomain();
        if ((string)$primaryDomain->getScheme() === '') {
            throw new Exception(sprintf("Domain %s for site %s has no scheme defined", $primaryDomain->getHostname(), $currentSite->getNodeName()), 1666684523);
        }

        // HINT: We cannot use a static URL here, but instead need to use an URL of the current site.
        // This is changed from the the old behavior, where we have changed the LinkingService in LinkingServiceAspect,
        // to properly generate the domain part of the routes - and this relies on the proper ControllerContext URI path.
        $baseControllerContext = $this->buildControllerContextAndSetBaseUri($primaryDomain->__toString(), $node, $arguments);
        $format = $arguments['@format'] ?? 'html';
        $uri = $this->linkingService->createNodeUri($baseControllerContext, $node, null, $format, true, $arguments, '', false, [], false);
        return self::removeQueryPartFromUri($uri);
    }

    /**
     * @param string $uri
     * @param NodeInterface $node
     * @param array $arguments
     * @return ControllerContext
     */
    public function buildControllerContextAndSetBaseUri(string $uri, NodeInterface $node, array $arguments = [])
    {
        $request = $this->buildFakeRequest($uri, $node);
        if (isset($arguments['@format'])) {
            $request->setFormat($arguments['@format']);
        }

        // NASTY SIDE-EFFECT: we not only build the controller context, but we also need to inject the "current" base URL to BaseUriProvider,
        // as this is now (Flow 6.x) used in the UriBuilder to determine the domain.
        $baseUri = rtrim(RequestInformationHelper::generateBaseUri($request->getHttpRequest())->__toString(), '/');
        ObjectAccess::setProperty($this->baseUriProvider, 'configuredBaseUri', $baseUri, true);

        ObjectAccess::setProperty($this->securityContext, 'initialized', true, true);
        $this->securityContext->setRequest($request);
        $uriBuilder = $this->uriBuilderForRequest($request);

        return new ControllerContext(
            $request,
            new ActionResponse(),
            new Arguments([]),
            $uriBuilder
        );
    }

    /**
     * @param string $uri
     * @param NodeInterface $node
     * @return ActionRequest
     */
    protected function buildFakeRequest($uri, NodeInterface $node): ActionRequest
    {
        $_SERVER['FLOW_REWRITEURLS'] = '1';

        $httpRequest = new ServerRequest('GET', $uri);
        $routingParameters = RouteParameters::createEmpty()->withParameter('requestUriHost', $httpRequest->getUri()->getHost());
        $httpRequest = $httpRequest->withAttribute(ServerRequestAttributes::ROUTING_PARAMETERS, $routingParameters);

        $request = ActionRequest::fromHttpRequest($httpRequest);
        $request->setControllerObjectName('Neos\Neos\Controller\Frontend\NodeController');
        $request->setControllerActionName('show');
        $request->setFormat('html');
        $request->setArgument('node', $node->getContextPath());

        return $request;
    }


    /**
     * @param ActionRequest $request
     * @return UriBuilder
     * @throws \Neos\Utility\Exception\PropertyNotAccessibleException
     */
    protected function uriBuilderForRequest(ActionRequest $request): UriBuilder
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
     * @return string
     */
    protected static function removeQueryPartFromUri($uri)
    {
        $uriData = explode('?', $uri);

        return $uriData[0];
    }
}
