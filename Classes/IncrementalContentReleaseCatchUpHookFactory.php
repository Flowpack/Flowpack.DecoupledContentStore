<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore;

use Neos\ContentRepository\Core\Projection\CatchUpHook\CatchUpHookFactoryDependencies;
use Neos\ContentRepository\Core\Projection\CatchUpHook\CatchUpHookFactoryInterface;
use Neos\ContentRepository\Core\Projection\CatchUpHook\CatchUpHookInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphReadModelInterface;

/**
 * @implements CatchUpHookFactoryInterface<ContentGraphReadModelInterface>
 */
final readonly class IncrementalContentReleaseCatchUpHookFactory implements CatchUpHookFactoryInterface
{
    public function __construct(
        private IncrementalContentReleaseHandler $incrementalContentReleaseHandler,
    ) {}

    public function build(CatchUpHookFactoryDependencies $dependencies): CatchUpHookInterface
    {
        return new IncrementalContentReleaseCatchUpHook($this->incrementalContentReleaseHandler);
    }
}
