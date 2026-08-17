<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Tests\Unit\Transfer\Dto;

use Flowpack\DecoupledContentStore\Transfer\Dto\RedisKeyPostfixesForEachRelease;
use Neos\Flow\Tests\UnitTestCase;

/**
 * Tests which registered redis keys a quick content release takes over from the release it is built on.
 */
final class RedisKeyPostfixesForEachReleaseTest extends UnitTestCase
{
    public function testOnlyTheFlaggedKeysAreCopied(): void
    {
        $redisKeyPostfixes = RedisKeyPostfixesForEachRelease::fromArray([
            'renderedDocuments' => self::keyConfiguration('renderedDocuments', true),
            'renderingJobQueue' => self::keyConfiguration('renderingJobQueue', false),
            'metaUrls' => self::keyConfiguration('meta:urls', true),
        ]);

        self::assertSame(['renderedDocuments', 'meta:urls'], self::copiedPostfixes($redisKeyPostfixes));
    }

    public function testAKeyWhichDoesNotKnowAboutQuickReleasesIsNotCopied(): void
    {
        // site packages register their own keys, and those configurations predate quick releases
        $configurationWithoutTheFlag = self::keyConfiguration('renderedDocuments', true);
        unset($configurationWithoutTheFlag['copyOnQuickRelease']);

        $redisKeyPostfixes = RedisKeyPostfixesForEachRelease::fromArray([
            'renderedDocuments' => $configurationWithoutTheFlag,
        ]);

        self::assertSame([], self::copiedPostfixes($redisKeyPostfixes));
    }

    /**
     * @return array<string>
     */
    private static function copiedPostfixes(RedisKeyPostfixesForEachRelease $redisKeyPostfixes): array
    {
        $result = [];
        foreach ($redisKeyPostfixes->getKeysToCopyOnQuickRelease() as $redisKeyPostfix) {
            $result[] = $redisKeyPostfix->getRedisKeyPostfix();
        }
        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private static function keyConfiguration(string $redisKeyPostfix, bool $copyOnQuickRelease): array
    {
        return [
            'redisKeyPostfix' => $redisKeyPostfix,
            'transfer' => true,
            'transferMode' => 'dump',
            'isRequired' => true,
            'copyOnQuickRelease' => $copyOnQuickRelease,
        ];
    }
}
