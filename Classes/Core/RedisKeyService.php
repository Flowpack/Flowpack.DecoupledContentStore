<?php

namespace Flowpack\DecoupledContentStore\Core;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Exception;
use Flowpack\DecoupledContentStore\Transfer\Dto\RedisKeyPostfixesForEachRelease;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Scope("singleton")
 */
class RedisKeyService
{
    /**
     * @Flow\InjectConfiguration("redisKeyPostfixesForEachRelease")
     * @var array
     */
    protected $redisKeyPostfixesForEachReleaseConfiguration;

    public function getRedisKeyForPostfix(ContentReleaseIdentifier $contentReleaseIdentifier, string $postfix): string
    {
        if (!$this->validateAgainstSettings($postfix)) {
            throw new Exception('Postfix does not match configuration. Given: ' . $postfix);
        }
        return 'contentStore:' . $contentReleaseIdentifier->getIdentifier() . ':' . $postfix;
    }

    private function validateAgainstSettings(string $identifier): bool
    {
        $keyMatchesSettings = false;

        $redisKeyPostfixesForEachRelease = RedisKeyPostfixesForEachRelease::fromArray($this->redisKeyPostfixesForEachReleaseConfiguration);

        foreach ($redisKeyPostfixesForEachRelease->getRedisKeyPostfixes() as $redisKeyPostfix) {
            if ($redisKeyPostfix->getRedisKeyPostfix() === $identifier) {
                $keyMatchesSettings = true;
            }
        }

        return $keyMatchesSettings;
    }
}
