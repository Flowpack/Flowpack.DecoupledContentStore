<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Tests\Behavior\Fixtures;

use Neos\Fusion\FusionObjects\AbstractFusionObject;
use RuntimeException;

final class FusionWithRenderingExceptionImplementation extends AbstractFusionObject
{
    public function evaluate(): never
    {
        throw new RuntimeException('We always fail');
    }
}
