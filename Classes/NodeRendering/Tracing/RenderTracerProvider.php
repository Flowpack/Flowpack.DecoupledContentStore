<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\Tracing;

use Neos\Flow\Annotations as Flow;

/**
 * Builds the configured tracer once per process.
 *
 * Absence of the setting is the "off" state - there is no boolean to flip, the configuration simply names a
 * factory or it does not. This follows the performanceTracer slot of the Neos 9 ContentRepositoryRegistry.
 */
#[Flow\Scope('singleton')]
class RenderTracerProvider
{
    #[Flow\InjectConfiguration('nodeRendering.performanceTracer')]
    protected ?array $configuration = null;

    protected ?RenderTracerInterface $tracer = null;

    public function getTracer(): RenderTracerInterface
    {
        if ($this->tracer === null) {
            $this->tracer = $this->buildTracer();
        }

        return $this->tracer;
    }

    protected function buildTracer(): RenderTracerInterface
    {
        $factoryObjectName = $this->configuration['factoryObjectName'] ?? null;
        if (!is_string($factoryObjectName) || $factoryObjectName === '') {
            return new NullTracer();
        }

        $factory = new $factoryObjectName();
        if (!$factory instanceof RenderTracerFactoryInterface) {
            throw new \RuntimeException(
                sprintf(
                    'The configured performanceTracer factory %s does not implement %s',
                    $factoryObjectName,
                    RenderTracerFactoryInterface::class,
                ),
                1756200001,
            );
        }

        return $factory->build($this->configuration['options'] ?? []);
    }
}
