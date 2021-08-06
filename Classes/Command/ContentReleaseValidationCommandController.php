<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Command;

use EinsUndEins\Neos\ContentStore\Exception;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Repository\RedisEnumerationRepository;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\RendererIdentifier;
use Flowpack\DecoupledContentStore\NodeRendering\Infrastructure\RedisRenderingErrorManager;
use Flowpack\DecoupledContentStore\NodeRendering\NodeRenderer;
use Flowpack\DecoupledContentStore\NodeRendering\NodeRenderOrchestrator;
use Flowpack\DecoupledContentStore\ReleaseSwitch\Infrastructure\RedisReleaseSwitchService;
use Neos\Flow\Annotations as Flow;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Neos\Flow\Cli\CommandController;

/**
 * Commands for the VALIDATION stage in the pipeline. Not meant to be called manually.
 */
class ContentReleaseValidationCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var RedisRenderingErrorManager
     */
    protected $redisRenderingErrorManager;

    /**
     * @Flow\Inject
     * @var RedisReleaseSwitchService
     */
    protected $redisReleaseSwitchService;

    /**
     * @Flow\Inject
     * @var RedisEnumerationRepository
     */
    protected $redisEnumerationRepository;

    /**
     * Factor between 0 and 1 for the amount of URLs a new release needs to include to be valid
     *
     * @var float
     */
    protected $validReleaseUrlCountThreshold = 0.7;

    public function validateCommand(string $contentReleaseIdentifier)
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $logger = ContentReleaseLogger::fromConsoleOutput($this->output, $contentReleaseIdentifier);

        $currentlyLiveReleaseIdentifier = $this->redisReleaseSwitchService->getCurrentRelease(RedisInstanceIdentifier::primary());
        if ($currentlyLiveReleaseIdentifier === null) {
            $logger->info('Did not find a previous Content Release; thus exiting early (OK).');
            return;
        }
        $logger->info('Previous Content Release: ' . $currentlyLiveReleaseIdentifier->getIdentifier());

        $currentUrlsCount = $this->redisEnumerationRepository->count($currentlyLiveReleaseIdentifier);
        $newUrlsCount = $this->redisEnumerationRepository->count($contentReleaseIdentifier);

        $logger->info('Previous URL Count: ' . $currentUrlsCount);
        $logger->info('New URL Count: ' . $newUrlsCount);

        if ($newUrlsCount < $this->validReleaseUrlCountThreshold * $currentUrlsCount) {
            $message = sprintf('Invalid release due to low URL count: (has %d of currently %d, need at least %d for automatic switch)', $newUrlsCount, $currentUrlsCount, $this->validReleaseUrlCountThreshold * $currentUrlsCount);
            $logger->error($message);
            $this->redisRenderingErrorManager->registerRenderingError($contentReleaseIdentifier, [], new Exception($message, 1493387482));
            exit(1);
        } else {
            $logger->info('All OK.');
        }
    }

    public function ensureNoValidationErrorsExistCommand(string $contentReleaseIdentifier)
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $logger = ContentReleaseLogger::fromConsoleOutput($this->output, $contentReleaseIdentifier);

        $errors = $this->redisRenderingErrorManager->getRenderingErrors($contentReleaseIdentifier);

        foreach ($errors as $error) {
            $logger->error('Rendering Error: ' . $error);
        }

        if (count($errors) > 0) {
            $logger->error('There were rendering errors, so we abort the pipeline.');
            // FAILING the job, to ensure the pipeline fails.
            exit(1);
        } else {
            $logger->info('No rendering errors, continuing with next task.');
        }
    }
}