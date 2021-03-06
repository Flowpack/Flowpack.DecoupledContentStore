<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Transfer;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager;
use Flowpack\DecoupledContentStore\Core\RedisKeyService;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\NodeRenderingCompletionStatus;
use Flowpack\DecoupledContentStore\NodeRendering\Infrastructure\RedisRenderingErrorManager;
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
     * @var RedisRenderingErrorManager
     */
    protected $redisRenderingErrorManager;

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

        $healthyReleaseCounter = 0;
        $releasesToRemove = [];
        foreach ($contentReleaseIds as $id) {
            if ($id->equals($currentRelease)) {
                $contentReleaseLogger->info('- ' . $id->getIdentifier() . ' (LIVE)');
            } elseif ($id->equals($contentReleaseIdentifierOfUpcomingRelease)) {
                $contentReleaseLogger->info('- ' . $id->getIdentifier() . ' (UPCOMING)');
            } else {
                if (!$redisInstanceIdentifier->isPrimary()) {
                    // We are on a PRODUCTION content store (in case of a replicated setup) - there are no errors transferred there
                    // by design and we want to NEVER run into a memory problem (that's why the cleanup should always run).
                    $healthyReleaseCounter++;
                } else {
                    // We are on the primary content store (the one which the pipeline uses as scratch space).
                    // In case of errors, we want to keep more "good" content releases ("success" state and no errors), so that we can
                    // more easily switch back to an older release.
                    // -> We accept potential Redis out of memory errors in this case.
                    if (($this->redisContentReleaseService->fetchMetadataForContentRelease($id)->getStatus()->getStatus() === NodeRenderingCompletionStatus::success()->getStatus() && count($this->redisRenderingErrorManager->getRenderingErrors($id)) === 0)) {
                        $healthyReleaseCounter++;
                    }
                }

                // we always want to keep $currentRelease and $contentReleaseIdentifierOfUpcomingRelease; thus
                // we need to remove 2 from $contentReleasesToKeep
                $shouldRemoveRelease = ($healthyReleaseCounter > $contentReleasesToKeep - 2);

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


    public function removeRelease(ContentReleaseIdentifier $contentReleaseIdentifierToRemove, RedisInstanceIdentifier $redisIdentifier, ContentReleaseLogger $contentReleaseLogger)
    {
        $redis = $this->redisClientManager->getRedis($redisIdentifier);

        $currentRelease = $this->redisReleaseSwitchService->getCurrentRelease($redisIdentifier);
        if (!$currentRelease) {
            $contentReleaseLogger->error('We did not find a current release in Content Store; so to be safe, we will NOT remove anything.');
            return;
        }

        if ($currentRelease->equals($contentReleaseIdentifierToRemove)) {
            $contentReleaseLogger->error('Release to be removed is currently active, thus we do not remove it.');
            return;
        }

        $redisKeyPostfixesForEachRelease = RedisKeyPostfixesForEachRelease::fromArray($this->redisKeyPostfixesForEachReleaseConfiguration);

        foreach ($redisKeyPostfixesForEachRelease->getRedisKeyPostfixes() as $redisKeyPostfix) {
            $redisKey = $this->redisKeyService->getRedisKeyForPostfix($contentReleaseIdentifierToRemove, $redisKeyPostfix->getRedisKeyPostfix());
            $contentReleaseLogger->debug('  - Removing ' . $redisKey);
            $redis->del($redisKey);
        }

        $redis->zRem('contentStore:registeredReleases', $contentReleaseIdentifierToRemove->getIdentifier());
    }
}
