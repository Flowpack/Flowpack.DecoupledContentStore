<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\BackendUi\FusionObjects;

use Neos\Error\Messages\Message;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\FlashMessage\FlashMessageService;
use Neos\Fusion\FusionObjects\AbstractFusionObject;

final class RetrieveFlashMessagesImplementation extends AbstractFusionObject
{
    #[Flow\Inject]
    protected FlashMessageService $flashMessageService;

    /**
     * @return Message[]
     */
    public function evaluate(): array
    {
        return $this->flashMessageService
            ->getFlashMessageContainerForRequest($this->runtime->getControllerContext()->getRequest())
            ->getMessagesAndFlush();
    }
}
