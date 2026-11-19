<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\ReleaseSwitch\Infrastructure;

use DateTimeImmutable;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager;
use Flowpack\DecoupledContentStore\Core\RedisKeyService;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Infrastructure\RedisContentReleaseService;
use Flowpack\DecoupledContentStore\Transfer\Dto\RedisKeyPostfixesForEachRelease;
use Neos\Flow\Annotations as Flow;

#[Flow\Scope('singleton')]
final readonly class RedisReleaseSwitchService
{
    /**
     * @param array<string, mixed> $redisKeyPostfixesForEachReleaseConfiguration
     * @param array<string, mixed> $configEpochSettings
     */
    public function __construct(
        private RedisClientManager $redisClient,
        private RedisContentReleaseService $redisContentReleaseService,
        private RedisKeyService $redisKeyService,
        #[Flow\InjectConfiguration('redisKeyPostfixesForEachRelease')]
        protected array $redisKeyPostfixesForEachReleaseConfiguration,
        #[Flow\InjectConfiguration('configEpoch')]
        protected array $configEpochSettings,
    ) {}

    /**
     * @return bool whether the release was switched; the reason it was not is logged
     */
    public function switchContentRelease(
        RedisInstanceIdentifier $redisInstanceIdentifier,
        ContentReleaseIdentifier $contentReleaseIdentifier,
        ContentReleaseLogger $contentReleaseLogger,
    ): bool {
        $redis = $this->redisClient->getRedis($redisInstanceIdentifier);
        $current = $redis->get('contentStore:current');

        // validation checks
        // we don't check for errors here (again) as we do not reach this stage if there were errors before
        if (!in_array($contentReleaseIdentifier->getIdentifier(), $redis->zRevRangeByLex(
            'contentStore:registeredReleases',
            '+',
            '-',
        ))) {
            $contentReleaseLogger->error(
                'Content release identifier '
                . $contentReleaseIdentifier->getIdentifier()
                . ' is not listed in current releases thus we do not switch.',
            );
            return false;
        }

        $redisKeyPostfixesForEachRelease = RedisKeyPostfixesForEachRelease::fromArray($this->redisKeyPostfixesForEachReleaseConfiguration);
        $hasError = false;
        foreach ($redisKeyPostfixesForEachRelease->getRequiredKeys() as $requiredPostfix) {
            if ($requiredPostfix->shouldTransfer($redisInstanceIdentifier)) {
                $key = $this->redisKeyService->getRedisKeyForPostfix(
                    $contentReleaseIdentifier,
                    $requiredPostfix->getRedisKeyPostfix(),
                );
                if (!$redis->exists($key)) {
                    $contentReleaseLogger->error(
                        'Required redis key ' . $key . ' does not exist for release thus we do not switch.',
                    );
                    $hasError = true;
                }
            }
        }
        if ($hasError === true) {
            $contentReleaseLogger->error('ABORTING directly before switch because final key validation failed.');
            return false;
        }

        $redis->set('contentStore:current', $contentReleaseIdentifier->getIdentifier());
        $redis->set('contentStore:configEpoch', $this->configEpochSettings['current']);
        $releaseMetadata = $this->redisContentReleaseService->fetchMetadataForContentRelease($contentReleaseIdentifier);
        $this->redisContentReleaseService->setContentReleaseMetadata(
            $contentReleaseIdentifier,
            $releaseMetadata->withSwitchTime(new DateTimeImmutable()),
            $redisInstanceIdentifier,
        );

        $contentReleaseLogger->info(sprintf(
            'Switched redis %s from content release %s to %s',
            $redisInstanceIdentifier->getIdentifier(),
            $current,
            $contentReleaseIdentifier->getIdentifier(),
        ));

        return true;
    }

    public function getCurrentRelease(RedisInstanceIdentifier $redisInstanceIdentifier): ?ContentReleaseIdentifier
    {
        $redis = $this->redisClient->getRedis($redisInstanceIdentifier);
        $current = $redis->get('contentStore:current');
        if ($current) {
            return ContentReleaseIdentifier::fromString($current);
        }
        return null;
    }
}
