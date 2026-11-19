<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Fusion\ExceptionHandlers;

use Exception;
use Flowpack\DecoupledContentStore\NodeRendering\Render\DocumentRenderer;
use Neos\Fusion\Core\ExceptionHandlers\ContextDependentHandler;

final class PublishingAwareContextDependentHandler extends ContextDependentHandler
{
    public function __construct(
        private readonly DocumentRenderer $documentRenderer,
    ) {}

    /**
     * {@inheritdoc}
     * @throws Exception
     */
    protected function handle($fusionPath, Exception $exception, $referenceCode): string
    {
        if ($this->documentRenderer->isRendering()) {
            throw $exception;
        } else {
            return parent::handle($fusionPath, $exception, $referenceCode);
        }
    }
}
