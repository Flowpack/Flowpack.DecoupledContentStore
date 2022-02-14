<?php

namespace Flowpack\DecoupledContentStore\Core;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager;
use Neos\Flow\Annotations as Flow;

/**
 * We usually rely on prunner to ensure that only one build is running at any given time.
 *
 * However, when running in a cloud environment with no shared storage, the prunner data folder is not shared between
 * instances. In this case, during a deployment, two containers run concurrently, with two separate prunner instances
 * (the old and the new one), which do not see each other.
 *
 * We could fix this in prunner itself, but this would be a bigger undertaking (different storage backends for prunner),
 * or we can work around this in DecoupledContentStore. This is what this class does.
 *
 * ## Main Idea
 *
 * - We use a special redis key "contentStore:concurrentBuildLock" which is set to the current being-built release ID in
 *   `./flow contentReleasePrepare:ensureAllOtherInProgressContentReleasesWillBeTerminated`
 * - In the "Enumerate" and "Render" phases, we periodically check whether the concurrentBuildLock is set to the currently
 *   in-progress content release. If NO, we abort.
 *
 * @Flow\Scope("singleton")
 */
class ConcurrentBuildLockService
{

    /**
     * @Flow\Inject
     * @var RedisClientManager
     */
    protected $redisClientManager;

    public function ensureAllOtherInProgressContentReleasesWillBeTerminated(ContentReleaseIdentifier $contentReleaseIdentifier)
    {
        $this->redisClientManager->getPrimaryRedis()->set('contentStore:concurrentBuildLock', $contentReleaseIdentifier->getIdentifier());
    }

    public function assertNoOtherContentReleaseWasStarted(ContentReleaseIdentifier $contentReleaseIdentifier)
    {
        $concurrentBuildLockString = $this->redisClientManager->getPrimaryRedis()->get('contentStore:concurrentBuildLock');

        if (empty($concurrentBuildLockString)) {
            echo '!!!!! Hard-aborting the current job ' . $contentReleaseIdentifier->getIdentifier() . ' because the concurrentBuildLock does not exist.' . "\n\n";
            echo "This should never happen for correctly configured jobs (that run after prepare_finished).\n\n";
            exit(1);
        }
        
        $concurrentBuildLock = ContentReleaseIdentifier::fromString($concurrentBuildLockString);

        if (!$contentReleaseIdentifier->equals($concurrentBuildLock)) {
            // the concurrent build lock is different (i.e. newer) than our currently-running content release.
            // Thus, we abort the in-progress content release as quickly as we can - by DYING.

            echo '!!!!! Hard-aborting the current job ' . $contentReleaseIdentifier->getIdentifier() . ' because the concurrentBuildLock contains ' . $concurrentBuildLock->getIdentifier() . "\n\n";
            echo "This is no error during deployment, but should never happen outside a deployment.\n\n It can only happen if two prunner instances run concurrently.\n\n";
            exit(1);
        }
    }

}
