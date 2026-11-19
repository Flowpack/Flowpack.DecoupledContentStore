<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Fusion\ExceptionHandlers;

use Exception;
use Flowpack\DecoupledContentStore\NodeRendering\Render\DocumentRenderer;
use Neos\Neos\Fusion\ExceptionHandlers\PageHandler;

final class PublishingAwarePageHandler extends PageHandler
{
    public function __construct(
        private readonly DocumentRenderer $documentRenderer,
    ) {}

    /**
     * {@inheritdoc}
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
