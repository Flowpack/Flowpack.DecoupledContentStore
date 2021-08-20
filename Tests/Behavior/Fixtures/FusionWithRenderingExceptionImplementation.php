<?php
declare(strict_types=1);
namespace Flowpack\DecoupledContentStore\Tests\Behavior\Fixtures;

use Neos\Fusion\FusionObjects\AbstractFusionObject;

class FusionWithRenderingExceptionImplementation extends AbstractFusionObject
{

    public function evaluate()
    {
        throw new \RuntimeException('We always fail');
    }
}