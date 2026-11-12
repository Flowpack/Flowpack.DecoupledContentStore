<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\Render;

use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Fusion\Core\FusionConfiguration;
use Neos\Fusion\Core\FusionGlobals;
use Neos\Fusion\Core\FusionSourceCodeCollection;
use Neos\Fusion\Core\Parser;
use Neos\Fusion\Core\Runtime;
use Neos\Fusion\Core\RuntimeConfiguration;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Service\FusionSourceCodeFactory;
use Neos\Neos\View\FusionView;
use ReflectionProperty;

/**
 * This Fusion View improves performance by re-using, per site, the merged Fusion configuration and the
 * Fusion Runtime's path configuration cache across all documents of a content release.
 *
 * The Runtime itself is created per document: its "request" Fusion global is readonly, and the request
 * differs for every rendered document (URI, format, base URI).
 */
class CustomFusionView extends FusionView
{
    public static bool $useCustomSiteRootFusionPatternEntryPointForBehavioralTests = false;

    /**
     * @var array<string, FusionConfiguration>
     */
    private array $fusionConfigurationPerSiteNode = [];

    /**
     * @var array<string, RuntimeConfiguration>
     */
    private array $runtimeConfigurationPerSiteNode = [];

    private ReflectionProperty $runtimeConfigurationAccessor;

    private bool $contentCacheEnabled = true;

    #[Flow\Inject]
    protected Parser $fusionParser;

    #[Flow\Inject]
    protected FusionSourceCodeFactory $fusionSourceCodeFactory;

    public function __construct(array $options = [])
    {
        parent::__construct($options);

        $this->fusionRuntime = null;
        $this->runtimeConfigurationAccessor = new ReflectionProperty(Runtime::class, 'runtimeConfiguration');
    }

    /**
     * The view is reused for every document, so the request of the current document has to replace the
     * previous one (the parent only sets it once).
     */
    public function setControllerContext(ControllerContext $controllerContext): void
    {
        $this->assignedActionRequest = $controllerContext->getRequest();
    }

    /**
     * The parent resolves the site node lazily and stores it as the "site" variable; with a reused view
     * it has to be resolved again for every document, which may belong to another site or dimension.
     */
    public function assign(string $key, mixed $value): self
    {
        if ($key === 'value') {
            unset($this->variables['site']);
        }
        return parent::assign($key, $value);
    }

    /**
     * During normal rendering, we rely on the fact that the Content Cache is properly filled
     * during the render - because the content cache is later on the basis for content releases.
     *
     * However, there are sometimes cases where you want to render a page, and then post-
     * process the rendered page *directly*, without the page ending up in the content cache
     * (e.g. because you are only interested in certain snippets of the rendered page).
     * For this, you'll usually call DocumentRenderer::renderDocumentNodeVariant() in a custom
     * Slot.
     *
     * For this special case, you need to PREVENT the special page to end up in the content
     * cache, because otherwise, when the *next* release is rendered, release validation
     * will fail because of this extra page (which should not end up in a release).
     * You can do this as follows:
     *
     * $this->documentRenderer->disableCache();
     * $parentNodeHtmlArray = $this->documentRenderer->renderDocumentNodeVariant($parentNode);
     * $this->documentRenderer->enableCache();
     *
     * ## How does this work internally?
     *
     * We need to ensure that all FusionRuntime instances (which do the rendering) are running
     * with caching enabled / disabled (as we wish) - by calling FusionRuntime::setEnableContentCache() appropriately.
     *
     * There are two cases which we need to take care of:
     *
     * 1. NEW FusionRuntime instances (after calling disableCache()). This is done
     * by setting $this->contentCacheEnabled to FALSE, and then using this value to initialize
     * all FusionRuntime instances before they are used (see method getFusionRuntime() of this
     * class).
     *
     * 2. EXISTING FusionRuntime instances (if we call this method, for whatever reason, already
     * during a rendering). In this case, we can directly set $this->fusionRuntime->setEnableContentCache(false);
     */
    public function disableCache(): void
    {
        $this->contentCacheEnabled = false;

        if ($this->fusionRuntime) {
            $this->fusionRuntime->setEnableContentCache(false);
        }
    }

    /**
     * See disableCache() method for detailed documentation.
     */
    public function enableCache(): void
    {
        $this->contentCacheEnabled = true;

        if ($this->fusionRuntime) {
            $this->fusionRuntime->setEnableContentCache(true);
        }
    }

    protected function getFusionRuntime(Node $currentSiteNode): Runtime
    {
        // $this->fusionRuntime is reset by every assign(), so this block runs for every rendered document.
        if ($this->fusionRuntime === null) {
            $siteNodeKey = $currentSiteNode->contentRepositoryId->value . '|' . $currentSiteNode->aggregateId->value;

            if (!isset($this->fusionConfigurationPerSiteNode[$siteNodeKey])) {
                $site = $this->siteRepository->findSiteBySiteNode($currentSiteNode);
                $this->fusionConfigurationPerSiteNode[$siteNodeKey] =
                    self::$useCustomSiteRootFusionPatternEntryPointForBehavioralTests
                        ? $this->createEndToEndTestFusionConfiguration($site)
                        : $this->fusionService->createFusionConfigurationFromSite($site);
            }

            $renderingMode = $this->renderingModeService->findByName($this->getOption('renderingModeName'));
            $this->fusionRuntime = $this->runtimeFactory->createFromConfiguration(
                $this->fusionConfigurationPerSiteNode[$siteNodeKey],
                FusionGlobals::fromArray(array_filter([
                    'request' => $this->assignedActionRequest,
                    'renderingMode' => $renderingMode,
                ])),
            );

            // The path configuration cache only depends on the Fusion configuration, so it is shared by all
            // Runtimes of a site instead of being rebuilt for every document.
            if (isset($this->runtimeConfigurationPerSiteNode[$siteNodeKey])) {
                $this->runtimeConfigurationAccessor->setValue(
                    $this->fusionRuntime,
                    $this->runtimeConfigurationPerSiteNode[$siteNodeKey],
                );
            } else {
                $this->runtimeConfigurationPerSiteNode[$siteNodeKey] =
                    $this->runtimeConfigurationAccessor->getValue($this->fusionRuntime);
            }

            // We use the property here as the content cache might currently be disabled.
            $this->fusionRuntime->setEnableContentCache($this->contentCacheEnabled);
        }
        return $this->fusionRuntime;
    }

    /**
     * Self-contained behavioral tests read the site's Fusion from Private/EndToEndTestFusion instead of
     * Private/Fusion.
     */
    private function createEndToEndTestFusionConfiguration(Site $site): FusionConfiguration
    {
        return $this->fusionParser->parseFromSource(
            $this->fusionSourceCodeFactory
                ->createFromNodeTypeDefinitions($site->getConfiguration()->contentRepositoryId)
                ->union($this->fusionSourceCodeFactory->createFromAutoIncludes())
                ->union(
                    FusionSourceCodeCollection::fromFilePath(
                        sprintf(
                            'resource://%s/Private/EndToEndTestFusion/Root.fusion',
                            $site->getSiteResourcesPackageKey(),
                        ),
                    ),
                ),
        );
    }
}
