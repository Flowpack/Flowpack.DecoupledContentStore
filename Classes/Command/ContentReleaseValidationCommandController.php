<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Command;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\Exception;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Repository\RedisEnumerationRepository;
use Flowpack\DecoupledContentStore\NodeRendering\Infrastructure\RedisRenderingErrorManager;
use Flowpack\DecoupledContentStore\QuickPublish\ContentReleaseScope;
use Flowpack\DecoupledContentStore\ReleaseSwitch\Infrastructure\RedisReleaseSwitchService;
use Neos\Flow\Annotations as Flow;
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

    #[Flow\Inject]
    protected ContentReleaseScope $contentReleaseScope;

    /**
     * Factor between 0 and 1 for the amount of URLs a new release needs to include to be valid
     */
    protected float $validReleaseUrlCountThreshold = 0.7;

    public function validateCommand(string $contentReleaseIdentifier)
    {
        $startedAt = microtime(true);
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $logger = ContentReleaseLogger::fromConsoleOutput($this->output, $contentReleaseIdentifier);

        $logger->info(sprintf(
            'Validating URL count of content release %s (threshold: %d%% of the currently live release).',
            $contentReleaseIdentifier->getIdentifier(),
            $this->validReleaseUrlCountThreshold * 100
        ));

        $currentlyLiveReleaseIdentifier = $this->redisReleaseSwitchService->getCurrentRelease(
            RedisInstanceIdentifier::primary()
        );
        if ($currentlyLiveReleaseIdentifier === null) {
            $logger->info('Did not find a previous Content Release; thus exiting early (OK).');
            $this->logCompletion($logger, $startedAt);
            return;
        }
        $logger->info('Previous Content Release: ' . $currentlyLiveReleaseIdentifier->getIdentifier());

        $currentUrlsCount = $this->countUrls($currentlyLiveReleaseIdentifier, $logger, 'Currently live release');
        $newUrlsCount = $this->countUrls($contentReleaseIdentifier, $logger, 'Content release');
        $minimumUrlsCount = (int) ceil($this->validReleaseUrlCountThreshold * $currentUrlsCount);

        $logger->info('Previous URL Count: ' . $currentUrlsCount);
        $logger->info('New URL Count: ' . $newUrlsCount);
        $logger->info(sprintf(
            'Minimum URL Count for automatic switch: %d (new release has %d%% of the previous one).',
            $minimumUrlsCount,
            $currentUrlsCount > 0 ? round(( $newUrlsCount / $currentUrlsCount ) * 100) : 100
        ));

        $alreadyRegisteredErrorCount = count($this->redisRenderingErrorManager->getRenderingErrors(
            $contentReleaseIdentifier
        ));
        if ($alreadyRegisteredErrorCount > 0) {
            $logger->warn(sprintf(
                '%d rendering error(s) are already registered for this release; the pipeline will abort in validate_finished.',
                $alreadyRegisteredErrorCount
            ));
        }

        if ($newUrlsCount < ( $this->validReleaseUrlCountThreshold * $currentUrlsCount )) {
            $message = sprintf(
                'Invalid release due to low URL count: (has %d of currently %d, need at least %d for automatic switch)',
                $newUrlsCount,
                $currentUrlsCount,
                $this->validReleaseUrlCountThreshold * $currentUrlsCount
            );
            $logger->error($message);
            $this->redisRenderingErrorManager->registerRenderingError(
                $contentReleaseIdentifier,
                [],
                new Exception($message, 1493387482)
            );
            $this->logCompletion($logger, $startedAt);
            exit(1);
        } else {
            $logger->info('All OK.');
        }

        $this->logCompletion($logger, $startedAt);
    }

    /**
     * How many URLs a content release covers, measured so that two releases are comparable.
     *
     * A quick release enumerates only the handful of documents it re-renders and copies the rest, so its enumeration
     * describes neither what it publishes nor what a later release has to live up to - taken as the baseline it would
     * put the threshold at a handful of URLs and wave through any release which lost most of the site. Its published
     * URLs are the comparable number: after the copy they equal the release it was built on. Each release is
     * therefore measured on its own terms, whichever side of the comparison it is on.
     */
    private function countUrls(
        ContentReleaseIdentifier $contentReleaseIdentifier,
        ContentReleaseLogger $logger,
        string $label
    ): int {
        if ($this->contentReleaseScope->getChangedUrls($contentReleaseIdentifier) === null) {
            return $this->redisEnumerationRepository->count($contentReleaseIdentifier);
        }

        $logger->info($label . ' is a quick release, so its published URLs are counted instead of its enumeration.');

        return $this->contentReleaseScope->countPublishedUrls($contentReleaseIdentifier);
    }

    /**
     * Final log line of the command. If it is the last line you see while the task is still marked as
     * "running" in the UI, the remaining time is NOT spent in this command, but in another script line
     * of the validate_content task (or in the PHP/Flow shutdown).
     */
    private function logCompletion(ContentReleaseLogger $logger, float $startedAt): void
    {
        $logger->info(sprintf(
            'contentReleaseValidation:validate finished in %.2f seconds.',
            microtime(true) - $startedAt
        ));
    }

    public function ensureNoValidationErrorsExistCommand(string $contentReleaseIdentifier)
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $logger = ContentReleaseLogger::fromConsoleOutput($this->output, $contentReleaseIdentifier);

        $errors = $this->redisRenderingErrorManager->getRenderingErrors($contentReleaseIdentifier);
        $logger->info(sprintf(
            'Checking rendering errors of content release %s: %d found.',
            $contentReleaseIdentifier->getIdentifier(),
            count($errors)
        ));

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
