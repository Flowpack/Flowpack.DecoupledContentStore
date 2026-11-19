<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore;

use Neos\Flow\Cli\Dispatcher as CliDispatcher;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Mvc\Controller\ControllerInterface;
use Neos\Flow\Mvc\Dispatcher as MvcDispatcher;
use Neos\Flow\Package\Package as BasePackage;
use Neos\Media\Domain\Service\AssetService;

final class Package extends BasePackage
{
    /**
     * @param Bootstrap $bootstrap The current bootstrap
     * @return void
     */
    public function boot(Bootstrap $bootstrap): void
    {
        $dispatcher = $bootstrap->getSignalSlotDispatcher();

        // Neos.Neos connects its own AssetChangeHandlerForCacheFlushing first (it boots before this package), so the
        // cache tags are already collected when this slot runs.
        $dispatcher->connect(
            AssetService::class,
            'assetUpdated',
            AssetChangeContentReleaseHandler::class,
            'assetUpdated',
            false,
        );

        // A web request starts a release for publishes into live.
        $dispatcher->connect(MvcDispatcher::class, 'afterControllerInvocation', function (
            $request,
            $response,
            $controller,
        ) use ($bootstrap): void {
            if ($controller instanceof ControllerInterface) {
                $bootstrap
                    ->getObjectManager()
                    ->get(IncrementalContentReleaseHandler::class)
                    ->startContentReleaseIfNodesWerePublishedBefore();
            }
        });

        // A CLI command only starts a release for nodes enabled or disabled directly in live, e.g. by
        // timeableNodeVisibility:execute.
        $dispatcher->connect(CliDispatcher::class, 'afterControllerInvocation', function () use ($bootstrap): void {
            $bootstrap
                ->getObjectManager()
                ->get(IncrementalContentReleaseHandler::class)
                ->startContentReleaseIfLiveVisibilityChanged();
        });
    }
}
