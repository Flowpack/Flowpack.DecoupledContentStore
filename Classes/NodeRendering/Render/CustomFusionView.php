<?php

namespace Flowpack\DecoupledContentStore\NodeRendering\Render;

use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Domain\Projection\Content\TraversableNodeInterface;
use Neos\Fusion\Core\Cache\RuntimeContentCache;
use Neos\Fusion\Core\Runtime;
use Neos\Neos\View\FusionView;

/**
 * This Fusion View improves performance by:
 * - re-using merged Fusion Object Tree throughout multiple renderings
 * - also re-using the Fusion Runtime with all caches.
 *
 * This leads to a tremendous speed up; in my local tests the full publish
 * before was about 3h; now it is about 1h10.
 */
class CustomFusionView extends FusionView
{
    private $fusionRuntimePerSiteNode = [];

    /**
     * @var \ReflectionProperty
     */
    private $controllerContextAccessor;
    /**
     * @var \ReflectionProperty
     */
    private $runtimeContentCacheAccessor;
    /**
     * @var \ReflectionProperty
     */
    private $defaultContextVariablesAccessor;

    /**
     * @var boolean
     */
    private $contentCacheEnabled = true;

    static public $useCustomSiteRootFusionPatternEntryPointForBehavioralTests = false;

    public function __construct(array $options = [])
    {
        parent::__construct($options);

        $this->controllerContextAccessor = new \ReflectionProperty(Runtime::class, 'controllerContext');
        $this->controllerContextAccessor->setAccessible(true);

        $this->runtimeContentCacheAccessor = new \ReflectionProperty(Runtime::class, 'runtimeContentCache');
        $this->runtimeContentCacheAccessor->setAccessible(true);

        $this->defaultContextVariablesAccessor = new \ReflectionProperty(Runtime::class, 'defaultContextVariables');
        $this->defaultContextVariablesAccessor->setAccessible(true);
    }

    public function initializeObject()
    {
        if (self::$useCustomSiteRootFusionPatternEntryPointForBehavioralTests) {
            // for self-contained tests, we read fusion from another folder.
            $this->fusionService->setSiteRootFusionPattern('resource://%s/Private/EndToEndTestFusion/Root.fusion');
        }
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
    public function disableCache()
    {
        $this->contentCacheEnabled = false;

        if ($this->fusionRuntime) {
            $this->fusionRuntime->setEnableContentCache(false);
        }
    }

    /**
     * See disableCache() method for detailed documentation.
     */
    public function enableCache()
    {
        $this->contentCacheEnabled = true;

        if ($this->fusionRuntime) {
            $this->fusionRuntime->setEnableContentCache(true);
        }
    }

    /**
     * @param Node $currentSiteNode
     * @return \Neos\Fusion\Core\Runtime
     */
    protected function getFusionRuntime(Node $currentSiteNode)
    {
        // $this->fusionRuntime is RESET during a call to self::assign()
        // so this means for every rendered document, we enter this block again
        if ($this->fusionRuntime === null) {
            $currentSiteNodeContextPath = $currentSiteNode->nodeAggregateId;

            if (!isset($this->fusionRuntimePerSiteNode[(string)$currentSiteNodeContextPath])) {
                // OPTIMIZATION 1: we only want to parse the fusion code ONCE and then reuse it again and again.
                // This is done by reusing FusionRuntime again and again.
                $fusionObjectTree = $this->fusionService->getMergedFusionObjectTree($currentSiteNode);

                // ... but we need to watch out, as the FusionRuntime also gets $this->controllerContext passed in,
                // !!*WHICH CHANGES FOR EVERY DOCUMENT*!!
                $this->fusionRuntimePerSiteNode[(string)$currentSiteNodeContextPath] = new Runtime($fusionObjectTree, $this->controllerContext);
            }
            $this->fusionRuntime = $this->fusionRuntimePerSiteNode[(string)$currentSiteNodeContextPath];

            // Luckily for us, the ControllerContext is only stored at $fusionRuntime->controllerContext;
            // so we can simply set it for reflection.
            // HOWEVER, controllerContext->getRequest() is additionally cached in Runtime::defaultContextVariables,
            // so we need to reset this properly as well (see next block).
            $this->controllerContextAccessor->setValue($this->fusionRuntime, $this->controllerContext);

            // getDefaultContextVariables() caches the "request" from the controller context lazily.
            // As we need the updated request (e.g. with a different format or base URI), we need to reset
            // the defaultContextVariables between renderings.
            $this->defaultContextVariablesAccessor->setValue($this->fusionRuntime, null);

            // technically, we do NOT need to replace the RuntimeContentCache (it works as well without the next line)
            // but I felt this would be an additional safeguard against problems with the cache (e.g. content leaking through dimensions or pages)
            $this->runtimeContentCacheAccessor->setValue($this->fusionRuntime, new RuntimeContentCache($this->fusionRuntime));

            // after replacing the RuntimeContentCache, we again need to enable the content cache explicitly.
            // Otherwise, we do not get any contents into the content store.
            // We use the property here as the content cache might currently be disabled.
            $this->fusionRuntime->setEnableContentCache($this->contentCacheEnabled);
        }
        return $this->fusionRuntime;
    }
}
