<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore;

use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Mvc\Controller\ControllerInterface;
use Neos\Flow\Package\Package as BasePackage;
use Neos\Media\Domain\Service\AssetService;

class Package extends BasePackage
{
    /**
     * @param Bootstrap $bootstrap The current bootstrap
     * @return void
     */
    public function boot(Bootstrap $bootstrap)
    {
        $dispatcher = $bootstrap->getSignalSlotDispatcher();

        // TODO 9.0 migration: The signal "afterNodePublishing" on "Workspace" has been removed. Please check https://docs.neos.io/api/upgrade-instructions/9/signals-and-slots for further information, how to replace a signal.
        $dispatcher->connect(
            \Neos\ContentRepository\Core\SharedModel\Workspace\Workspace::class,
            'afterNodePublishing',
            IncrementalContentReleaseHandler::class,
            'nodePublished',
        );

        // Neos.Neos connects its own AssetChangeHandlerForCacheFlushing first (it boots before this package), so the
        // cache tags are already collected when this slot runs.
        $dispatcher->connect(
            AssetService::class,
            'assetUpdated',
            AssetChangeContentReleaseHandler::class,
            'assetUpdated',
            false,
        );

        // NASTY WORKAROUND - explanation follows.
        //
        // Background:
        // - in older Flow versions, the Neos\Flow\Mvc\Dispatcher was used BOTH for Web and CLI requests. Thus, the "afterControllerInvocation" signal was emitted for both cases.
        // - With Flow 6.0, the Neos\Flow\Mvc\Dispatcher was split apart; so the CLI uses its separate Neos\Flow\Cli\Dispatcher.
        // - however, *FOR BACKWARDS COMPATIBILITY REASONS* (probably) the CLI Dispatcher emits the "afterControllerInvocation" in the name of the Mvc\Dispatcher; effectively
        //   keeping the old behavior as before.
        //
        // In our case, we want to ONLY listen to web requests, ignoring CLI requests. Thus, we check for the type of the Controller, which is CommandControllerInterface for CLI; and ControllerInterface for web.
        $dispatcher->connect('Neos\Flow\Mvc\Dispatcher', 'afterControllerInvocation', function (
            $request,
            $response,
            $controller,
        ) use ($bootstrap) {
            if ($controller instanceof ControllerInterface) {
                $bootstrap->getObjectManager()
                    ->get(IncrementalContentReleaseHandler::class)
                    ->startContentReleaseIfNodesWerePublishedBefore();
            }
        });
    }
}
