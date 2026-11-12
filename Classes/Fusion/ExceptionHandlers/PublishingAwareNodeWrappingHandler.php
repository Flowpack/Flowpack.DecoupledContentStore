<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Fusion\ExceptionHandlers;

use Exception;
use Flowpack\DecoupledContentStore\NodeRendering\Render\DocumentRenderer;
use Neos\Flow\Annotations as Flow;

class PublishingAwareNodeWrappingHandler extends \Neos\Neos\Fusion\ExceptionHandlers\NodeWrappingHandler
{
    #[Flow\Inject()]
    protected DocumentRenderer $documentRenderer;

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
