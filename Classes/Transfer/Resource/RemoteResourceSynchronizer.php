<?php
namespace Flowpack\DecoupledContentStore\Transfer\Resource;

use AFM\Rsync\Rsync;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\Exception;
use Neos\Flow\Annotations as Flow;
use Neos\Utility\Files;

/**
 * @Flow\Scope("singleton")
 */
class RemoteResourceSynchronizer
{
    /**
     * @Flow\InjectConfiguration(path="resourceSync.targets")
     * @var array
     */
    protected $targets = array();

    /**
     *
     */
    public function synchronize(ContentReleaseLogger $logger)
    {
        if ($this->targets === array()) {
            $logger->debug('Skipping resource synchronization, no targets configured');
            return;
        }

        $origin = Files::concatenatePaths([FLOW_PATH_WEB, '_Resources']) . '/.';
        $rsync = new Rsync([
            'follow_symlinks' => true,
            'times' => true,
            'recursive' => true,
            'show_output' => false
        ]);

        $logger->debug('Syncing resources from ' . $origin);

        foreach ($this->targets as $targetConfiguration) {
            if (!isset($targetConfiguration['host'])) {
                throw new Exception('Missing "host" for resource sync target', 1472126081);
            }
            if (!isset($targetConfiguration['directory'])) {
                throw new Exception('Missing "directory" for resource sync target', 1472126082);
            }

            if ($targetConfiguration['host'] === 'localhost') {
                $target = Files::concatenatePaths([FLOW_PATH_ROOT, $targetConfiguration['directory']]);
            } else {
                if (!isset($targetConfiguration['user'])) {
                    throw new Exception('Missing "user" for resource sync target', 1472126083);
                }
                if (!isset($targetConfiguration['user'])) {
                    throw new Exception('Missing "user" for resource sync target', 1472126084);
                }

                $port = 22;
                if (isset($targetConfiguration['port']) && (string)$targetConfiguration['port'] !== '') {
                    $port = intval($targetConfiguration['port']);
                }

                if ($port !== 22) {
                    // NOTE: it seems that when using setSshOptions, we also need to specify host and username etc... This is not yet done, as we normally run on port 22.
                    $rsync->setSshOptions([
                        'port' => $port
                    ]);
                }

                $target = $targetConfiguration['user'] . '@' . $targetConfiguration['host'] . ':' . $targetConfiguration['directory'];
            }

            // TODO This does not return errors yet, which we most probably need / want for production
            $rsync->sync($origin, $target);

            $logger->debug('... synced resources to ' . $target);
        }
    }
}
