<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore;

use Flowpack\DecoupledContentStore\Fusion\ImageUriImplementation;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Fusion\Core\Cache\ContentCache;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\AssetVariantInterface;
use Neos\Media\Domain\Service\AssetService;
use Neos\Neos\Fusion\Cache\ContentCacheFlusher;

/**
 * Flushes the cache segments rendering a changed asset and starts an incremental content release when the asset is in use.
 *
 * Must be connected to AssetService::assetUpdated after Neos' own AssetChangeHandlerForCacheFlushing, which only
 * collects the cache tags of the nodes using the asset and flushes them on shutdown. The tags are flushed here
 * before the release starts, otherwise node enumeration could still see the stale cache entries.
 */
#[Flow\Scope('singleton')]
final readonly class AssetChangeContentReleaseHandler
{
    private const string ASSET_CACHE_TAG_PREFIX = 'AssetDynamicTag_';

    public function __construct(
        private AssetService $assetService,
        private ContentCacheFlusher $contentCacheFlusher,
        private ContentCache $contentCache,
        private PersistenceManagerInterface $persistenceManager,
        private ContentReleaseManager $contentReleaseManager,
        #[Flow\InjectConfiguration('startIncrementalReleaseOnAssetChange')]
        protected bool $startIncrementalReleaseOnAssetChange,
    ) {}

    /**
     * The tag {@see ImageUriImplementation} adds to every cache segment rendering the asset.
     */
    public static function cacheTagForAsset(string $assetIdentifier): string
    {
        return self::ASSET_CACHE_TAG_PREFIX . $assetIdentifier;
    }

    public function assetUpdated(AssetInterface $asset): void
    {
        // Nodes reference the original asset, never a variant.
        if ($asset instanceof AssetVariantInterface) {
            $asset = $asset->getOriginalAsset();
        }

        // Pages rendering an asset no node of theirs references (e.g. a teaser showing another page's image) are
        // only found by this tag; an asset rendered from Fusion alone is not even "in use".
        $flushedEntriesCount = $this->contentCache->flushByTag(
            self::cacheTagForAsset((string) $this->persistenceManager->getIdentifierByObject($asset)),
        );

        if (!$this->startIncrementalReleaseOnAssetChange) {
            return;
        }
        if ($flushedEntriesCount === 0 && !$this->assetService->isInUse($asset)) {
            return;
        }

        $this->contentCacheFlusher->flushCollectedTags();
        $this->contentReleaseManager->startIncrementalContentRelease();
    }
}
