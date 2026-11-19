<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Tests\Unit;

use Flowpack\DecoupledContentStore\AssetChangeContentReleaseHandler;
use Flowpack\DecoupledContentStore\ContentReleaseManager;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Fusion\Core\Cache\ContentCache;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\AssetVariantInterface;
use Neos\Media\Domain\Service\AssetService;
use Neos\Neos\Fusion\Cache\ContentCacheFlusher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests which cache entries a changed asset flushes and when it starts an incremental content release.
 */
final class AssetChangeContentReleaseHandlerTest extends TestCase
{
    private AssetService&MockObject $assetService;

    private ContentCacheFlusher&MockObject $contentCacheFlusher;

    private ContentCache&MockObject $contentCache;

    private ContentReleaseManager&MockObject $contentReleaseManager;

    private AssetInterface&MockObject $originalAsset;

    protected function setUp(): void
    {
        $this->assetService = $this->createMock(AssetService::class);
        $this->contentCacheFlusher = $this->createMock(ContentCacheFlusher::class);
        $this->contentCache = $this->createMock(ContentCache::class);
        $this->contentReleaseManager = $this->createMock(ContentReleaseManager::class);
        $this->originalAsset = $this->createMock(AssetInterface::class);
    }

    public function testAVariantFlushesTheTagOfItsOriginalAsset(): void
    {
        $variant = $this->createMock(AssetVariantInterface::class);
        $variant->method('getOriginalAsset')->willReturn($this->originalAsset);
        $this->contentCache
            ->expects(self::once())
            ->method('flushByTag')
            ->with('AssetDynamicTag_original-id')
            ->willReturn(0);

        $this->buildHandler()->assetUpdated($variant);
    }

    public function testAnAssetInUseFlushesTheCollectedTagsBeforeTheReleaseStarts(): void
    {
        // otherwise node enumeration could still read the stale cache entries
        $this->assetService->method('isInUse')->willReturn(true);
        $calls = [];
        $this->contentCacheFlusher
            ->expects(self::once())
            ->method('flushCollectedTags')
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'flush';
            });
        $this->contentReleaseManager
            ->expects(self::once())
            ->method('startIncrementalContentRelease')
            ->willReturnCallback(static function () use (&$calls): ContentReleaseIdentifier {
                $calls[] = 'release';
                return ContentReleaseIdentifier::create();
            });

        $this->buildHandler()->assetUpdated($this->originalAsset);

        self::assertSame(['flush', 'release'], $calls);
    }

    public function testAnAssetOnlyRenderedFromFusionStartsARelease(): void
    {
        // no node references it, so only the tag added while rendering it finds the pages showing it
        $this->assetService->method('isInUse')->willReturn(false);
        $this->contentCache->method('flushByTag')->willReturn(2);
        $this->contentReleaseManager->expects(self::once())->method('startIncrementalContentRelease');

        $this->buildHandler()->assetUpdated($this->originalAsset);
    }

    public function testAnAssetNeitherInUseNorRenderedStartsNoRelease(): void
    {
        $this->assetService->method('isInUse')->willReturn(false);
        $this->contentCache->method('flushByTag')->willReturn(0);
        $this->contentCacheFlusher->expects(self::never())->method('flushCollectedTags');
        $this->contentReleaseManager->expects(self::never())->method('startIncrementalContentRelease');

        $this->buildHandler()->assetUpdated($this->originalAsset);
    }

    public function testTheAssetTagIsFlushedEvenIfNoReleaseIsStarted(): void
    {
        // the setting only decides about the release; a stale cache entry would still end up in the next one
        $this->assetService->method('isInUse')->willReturn(true);
        $this->contentCache->expects(self::once())->method('flushByTag')->with('AssetDynamicTag_original-id');
        $this->contentReleaseManager->expects(self::never())->method('startIncrementalContentRelease');

        $this->buildHandler(false)->assetUpdated($this->originalAsset);
    }

    private function buildHandler(bool $startIncrementalReleaseOnAssetChange = true): AssetChangeContentReleaseHandler
    {
        $persistenceManager = $this->createMock(PersistenceManagerInterface::class);
        $persistenceManager
            ->method('getIdentifierByObject')
            ->willReturnCallback(fn(object $object): ?string => (
                $object === $this->originalAsset ? 'original-id' : null
            ));

        return new AssetChangeContentReleaseHandler(
            $this->assetService,
            $this->contentCacheFlusher,
            $this->contentCache,
            $persistenceManager,
            $this->contentReleaseManager,
            $startIncrementalReleaseOnAssetChange,
        );
    }
}
