<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\QuickPublish;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager;
use Flowpack\DecoupledContentStore\Core\RedisKeyService;
use Neos\Flow\Annotations as Flow;

/**
 * What a content release covers: everything, or the handful of documents a quick release re-rendered.
 *
 * Validators ask this before they read a release. One which knows nothing about quick releases keeps working
 * unchanged, because a full release has no scope and therefore has to be checked as a whole; one which opts in
 * narrows its read to the URLs which actually changed, and skips the work of checking documents which were copied
 * over unchanged from a release that was validated already.
 */
#[Flow\Scope('singleton')]
final class ContentReleaseScope
{
    private const CHANGED_URLS_POSTFIX = 'quickPublish:changedUrls';
    private const URLS_POSTFIX = 'meta:urls';

    #[Flow\Inject]
    protected RedisClientManager $redisClientManager;

    #[Flow\Inject]
    protected RedisKeyService $redisKeyService;

    /**
     * The URLs a quick content release re-rendered, or NULL for a release which was rendered as a whole - meaning
     * "everything", not "nothing".
     *
     * @return array<int, string>|null
     */
    public function getChangedUrls(ContentReleaseIdentifier $contentReleaseIdentifier): ?array
    {
        $changedUrls = $this->redisClientManager
            ->getPrimaryRedis()
            ->sMembers($this->redisKeyService->getRedisKeyForPostfix(
                $contentReleaseIdentifier,
                self::CHANGED_URLS_POSTFIX,
            ));

        // a quick release which changed nothing is never published, so an empty set means there is no scope
        if (!is_array($changedUrls) || $changedUrls === []) {
            return null;
        }

        return $changedUrls;
    }

    /**
     * @param array<int, string> $changedUrls
     */
    public function setChangedUrls(ContentReleaseIdentifier $contentReleaseIdentifier, array $changedUrls): void
    {
        if ($changedUrls === []) {
            return;
        }

        $this->redisClientManager->getPrimaryRedis()->sAdd(
            $this->redisKeyService->getRedisKeyForPostfix($contentReleaseIdentifier, self::CHANGED_URLS_POSTFIX),
            ...$changedUrls,
        );
    }

    /**
     * How many URLs the release holds.
     *
     * This is what makes two releases comparable in size: the enumeration of a quick release only covers what it
     * re-rendered, while every release - copied or rendered - carries the full list of URLs it publishes.
     */
    public function countPublishedUrls(ContentReleaseIdentifier $contentReleaseIdentifier): int
    {
        return (int) $this->redisClientManager
            ->getPrimaryRedis()
            ->zCard($this->redisKeyService->getRedisKeyForPostfix($contentReleaseIdentifier, self::URLS_POSTFIX));
    }
}
