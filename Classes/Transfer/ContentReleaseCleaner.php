<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Transfer;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager;
use Flowpack\DecoupledContentStore\Core\RedisKeyService;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Infrastructure\RedisContentReleaseService;
use Flowpack\DecoupledContentStore\ReleaseSwitch\Infrastructure\RedisReleaseSwitchService;
use Flowpack\DecoupledContentStore\Transfer\Dto\RedisKeyPostfixesForEachRelease;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Scope("singleton")
 */
class ContentReleaseCleaner
{
    /**
     * @Flow\Inject
     * @var RedisClientManager
     */
    protected $redisClientManager;

    /**
     * @Flow\InjectConfiguration("redisKeyPostfixesForEachRelease")
     * @var array
     */
    protected $redisKeyPostfixesForEachReleaseConfiguration;

    /**
     * @Flow\Inject
     * @var RedisKeyService
     */
    protected $redisKeyService;

    /**
     * @Flow\Inject
     * @var RedisReleaseSwitchService
     */
    protected $redisReleaseSwitchService;


    /**
     * @Flow\Inject
     * @var RedisContentReleaseService
     */
    protected $redisContentReleaseService;

    public function removeOldReleases(RedisInstanceIdentifier $redisInstanceIdentifier, ContentReleaseIdentifier $contentReleaseIdentifierOfUpcomingRelease, ContentReleaseLogger $contentReleaseLogger): void
    {
        $contentReleaseLogger->info('Removing old releases in Redis ' . $redisInstanceIdentifier->getIdentifier() . '. First, checking which releases to keep:');

        $currentRelease = $this->redisReleaseSwitchService->getCurrentRelease($redisInstanceIdentifier);
        if (!$currentRelease) {
            $contentReleaseLogger->error('We did not find a current release in Content Store; so to be safe, we will NOT remove anything.');
            return;
        }

        $contentReleaseIds = $this->redisContentReleaseService->fetchAllReleaseIds($redisInstanceIdentifier);

        $contentReleasesToKeep = $this->redisClientManager->getRetentionCount($redisInstanceIdentifier);
        if ($contentReleasesToKeep < 2) {
            throw new \RuntimeException('contentReleaseRetentionCount must be at least 2, found: ' . $contentReleasesToKeep);
        }

        $i = 0;
        $releasesToRemove = [];
        foreach ($contentReleaseIds as $id) {
            if ($id->equals($currentRelease)) {
                $contentReleaseLogger->info('- ' . $id->getIdentifier() . ' (LIVE)');
            } elseif ($id->equals($contentReleaseIdentifierOfUpcomingRelease)) {
                $contentReleaseLogger->info('- ' . $id->getIdentifier() . ' (UPCOMING)');
            } else {
                $i++;

                // we always want to keep $currentRelease and $contentReleaseIdentifierOfUpcomingRelease; thus
                // we need to remove 2 from $contentReleasesToKeep
               $shouldRemoveRelease = ($i > $contentReleasesToKeep - 2);

               if ($shouldRemoveRelease) {
                   $releasesToRemove[] = $id;
                   $contentReleaseLogger->info('- ' . $id->getIdentifier() . ' <-- to remove');
               } else {
                   $contentReleaseLogger->info('- ' . $id->getIdentifier());
               }
            }
        }

        $contentReleaseLogger->info('Starting to remove releases.');

        foreach ($releasesToRemove as $id) {
            $contentReleaseLogger->info('- Removing ' . $id->getIdentifier());
            $this->removeRelease($id, $redisInstanceIdentifier, $contentReleaseLogger);
        }

        $contentReleaseLogger->info('Completed.');
    }


    private function removeRelease(ContentReleaseIdentifier $contentReleaseIdentifierToRemove, RedisInstanceIdentifier $redisIdentifier, ContentReleaseLogger $contentReleaseLogger)
    {
        $redis = $this->redisClientManager->getRedis($redisIdentifier);

        $currentRelease = $this->redisReleaseSwitchService->getCurrentRelease($redisIdentifier);
        if (!$currentRelease) {
            $contentReleaseLogger->error('We did not find a current release in Content Store; so to be safe, we will NOT remove anything.');
            return;
        }

        $redisKeyPostfixesForEachRelease = RedisKeyPostfixesForEachRelease::fromArray($this->redisKeyPostfixesForEachReleaseConfiguration);

        foreach ($redisKeyPostfixesForEachRelease->getRedisKeyPostfixes() as $redisKeyPostfix) {
            $redisKey = $this->redisKeyService->getRedisKeyForPostfix($contentReleaseIdentifierToRemove, $redisKeyPostfix->getRedisKeyPostfix());
            $contentReleaseLogger->debug('  - Removing ' . $redisKey);
            $redis->del($redisKey);
        }
    }
}
