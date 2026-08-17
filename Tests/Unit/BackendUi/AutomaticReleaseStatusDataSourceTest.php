<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Tests\Unit\BackendUi;

use Flowpack\DecoupledContentStore\BackendUi\AutomaticReleaseStatusDataSource;
use Flowpack\DecoupledContentStore\BackendUi\BackendDateFormatter;
use Flowpack\DecoupledContentStore\Core\AutomaticReleaseSwitchService;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\AutomaticReleasePauseState;
use Neos\Flow\I18n\Translator;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Tests the payload the content module warning is painted from.
 *
 * Both the identifier and the field names are a contract with
 * Resources/Public/ContentModule/AutomaticReleaseWarning.js, which has no way of noticing that they changed.
 */
final class AutomaticReleaseStatusDataSourceTest extends TestCase
{
    public function testTheIdentifierIsTheOneTheContentModuleScriptRequests(): void
    {
        self::assertSame(
            'flowpack-decoupledcontentstore-automatic-release-status',
            AutomaticReleaseStatusDataSource::getIdentifier()
        );
    }

    public function testNothingButThePausedFlagIsPublishedWhileReleasesRunNormally(): void
    {
        self::assertSame(['paused' => false], $this->buildDataSource(null)->getData());
    }

    public function testTheWarningIsPublishedAsAReadyMadeMessage(): void
    {
        // the script has no translation API available, so it prints what it gets
        $pauseState = AutomaticReleasePauseState::fromRedisHash([
            'pausedAt' => '2026-08-13T09:15:00+02:00',
            'accountId' => 'admin',
            'suppressedReleaseCount' => '4',
        ]);

        self::assertSame([
            'paused' => true,
            'message' => 'translated: automaticReleases.paused.contentModuleWarning',
        ], $this->buildDataSource($pauseState)->getData());
    }

    public function testTheTimestampAndTheWaitingCountAreHandedToTheTranslation(): void
    {
        $pauseState = AutomaticReleasePauseState::fromRedisHash([
            'pausedAt' => '2026-08-13T09:15:00+02:00',
            'suppressedReleaseCount' => '4',
        ]);

        $translator = $this->createMock(Translator::class);
        $translator->expects(self::once())->method('translateById')->with(
            'automaticReleases.paused.contentModuleWarning',
            ['formatted date', 4],
            null,
            null,
            'Main',
            'Flowpack.DecoupledContentStore'
        );

        $this->buildDataSource($pauseState, $translator)->getData();
    }

    private function buildDataSource(
        ?AutomaticReleasePauseState $pauseState,
        ?Translator $translator = null
    ): AutomaticReleaseStatusDataSource {
        $automaticReleaseSwitchService = $this->createMock(AutomaticReleaseSwitchService::class);
        $automaticReleaseSwitchService->method('getPauseState')->willReturn($pauseState);

        if ($translator === null) {
            $translator = $this->createMock(Translator::class);
            $translator->method('translateById')->willReturnCallback(
                static fn(string $labelId): string => 'translated: ' . $labelId
            );
        }

        $backendDateFormatter = $this->createMock(BackendDateFormatter::class);
        $backendDateFormatter->method('format')->willReturn('formatted date');

        $dataSource = new AutomaticReleaseStatusDataSource();
        // the class uses Flow property injection, which is not available outside a Flow bootstrap
        self::injectDependency($dataSource, 'automaticReleaseSwitchService', $automaticReleaseSwitchService);
        self::injectDependency($dataSource, 'translator', $translator);
        self::injectDependency($dataSource, 'backendDateFormatter', $backendDateFormatter);

        return $dataSource;
    }

    private static function injectDependency(object $target, string $propertyName, object $dependency): void
    {
        (new ReflectionProperty($target, $propertyName))->setValue($target, $dependency);
    }
}
