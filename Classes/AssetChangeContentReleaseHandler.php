<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore;

use Neos\Flow\Annotations as Flow;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\AssetVariantInterface;
use Neos\Media\Domain\Service\AssetService;
use Neos\Neos\Fusion\Cache\ContentCacheFlusher;

/**
 * Starts an incremental content release when an asset in use changes.
 *
 * Must be connected to AssetService::assetUpdated after Neos' own AssetChangeHandlerForCacheFlushing, which only
 * collects the cache tags of the nodes using the asset and flushes them on shutdown. The tags are flushed here
 * before the release starts, otherwise node enumeration could still see the stale cache entries.
 */
#[Flow\Scope('singleton')]
class AssetChangeContentReleaseHandler
{
    #[Flow\Inject]
    protected AssetService $assetService;

    #[Flow\Inject]
    protected ContentCacheFlusher $contentCacheFlusher;

    #[Flow\Inject]
    protected ContentReleaseManager $contentReleaseManager;

    #[Flow\InjectConfiguration('startIncrementalReleaseOnAssetChange')]
    protected bool $startIncrementalReleaseOnAssetChange;

    public function assetUpdated(AssetInterface $asset): void
    {
        if (!$this->startIncrementalReleaseOnAssetChange) {
            return;
        }

        // Nodes reference the original asset, never a variant.
        if ($asset instanceof AssetVariantInterface) {
            $asset = $asset->getOriginalAsset();
        }

        if (!$this->assetService->isInUse($asset)) {
            return;
        }

        $this->contentCacheFlusher->flushCollectedTags();
        $this->contentReleaseManager->startIncrementalContentRelease();
    }
}
