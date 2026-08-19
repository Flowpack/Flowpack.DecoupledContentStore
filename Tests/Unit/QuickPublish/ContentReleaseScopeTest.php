<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Tests\Unit\QuickPublish;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager;
use Flowpack\DecoupledContentStore\Core\RedisKeyService;
use Flowpack\DecoupledContentStore\QuickPublish\ContentReleaseScope;
use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Tests what a validator is told about the release it is checking.
 *
 * The distinction that matters is NULL against a list: NULL means a release which was rendered as a whole and has
 * to be validated as a whole, so a validator which reads it as "no URLs to check" would wave through everything.
 */
final class ContentReleaseScopeTest extends UnitTestCase
{
    private const CHANGED_URLS_KEY = 'contentStore:5:quickPublish:changedUrls';

    public function testAReleaseWhichWasRenderedAsAWholeHasNoScope(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('sMembers')->with(self::CHANGED_URLS_KEY)->willReturn([]);

        self::assertNull($this->buildContentReleaseScope($redis)->getChangedUrls($this->contentReleaseIdentifier()));
    }

    public function testAQuickReleaseIsScopedToTheUrlsItRendered(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('sMembers')
            ->with(self::CHANGED_URLS_KEY)
            ->willReturn([
                'http://test.de/de',
                'http://test.de/de/nested',
            ]);

        self::assertSame(
            ['http://test.de/de', 'http://test.de/de/nested'],
            $this->buildContentReleaseScope($redis)->getChangedUrls($this->contentReleaseIdentifier()),
        );
    }

    public function testTheScopeIsStoredWithTheReleaseItBelongsTo(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())
            ->method('sAdd')
            ->with(self::CHANGED_URLS_KEY, 'http://test.de/de', 'http://test.de/de/nested');

        $this->buildContentReleaseScope($redis)->setChangedUrls($this->contentReleaseIdentifier(), [
            'http://test.de/de',
            'http://test.de/de/nested',
        ]);
    }

    public function testAnEmptyScopeIsNotStored(): void
    {
        // it would be indistinguishable from a full release, which is validated as a whole
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::never())->method('sAdd');

        $this->buildContentReleaseScope($redis)->setChangedUrls($this->contentReleaseIdentifier(), []);
    }

    public function testPublishedUrlsAreCountedFromTheUrlIndexRatherThanTheEnumeration(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('zCard')->with('contentStore:5:meta:urls')->willReturn(18015);

        self::assertSame(
            18015,
            $this->buildContentReleaseScope($redis)->countPublishedUrls($this->contentReleaseIdentifier()),
        );
    }

    private function contentReleaseIdentifier(): ContentReleaseIdentifier
    {
        return ContentReleaseIdentifier::fromString('5');
    }

    /**
     * @param \Redis&MockObject $redis
     */
    private function buildContentReleaseScope(\Redis $redis): ContentReleaseScope
    {
        $redisClientManager = $this->createMock(RedisClientManager::class);
        $redisClientManager->method('getPrimaryRedis')->willReturn($redis);

        $redisKeyService = new RedisKeyService();
        $this->inject($redisKeyService, 'redisKeyPostfixesForEachReleaseConfiguration', [
            'metaUrls' => [
                'redisKeyPostfix' => 'meta:urls',
                'transfer' => true,
                'transferMode' => 'dump',
                'isRequired' => true,
                'copyOnQuickRelease' => true,
            ],
            'quickPublishChangedUrls' => [
                'redisKeyPostfix' => 'quickPublish:changedUrls',
                'transfer' => false,
                'transferMode' => 'dump',
                'isRequired' => false,
                'copyOnQuickRelease' => false,
            ],
        ]);

        $contentReleaseScope = new ContentReleaseScope();
        $this->inject($contentReleaseScope, 'redisClientManager', $redisClientManager);
        $this->inject($contentReleaseScope, 'redisKeyService', $redisKeyService);

        return $contentReleaseScope;
    }
}
