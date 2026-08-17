<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Tests\Unit\BackendUi;

use Flowpack\DecoupledContentStore\BackendUi\AutomaticReleaseStatusDataSource;
use Flowpack\DecoupledContentStore\BackendUi\BackendDateFormatter;
use Flowpack\DecoupledContentStore\Core\AutomaticReleaseSwitchService;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\AutomaticReleasePauseState;
use Neos\Flow\I18n\Translator;
use Neos\Flow\Tests\UnitTestCase;

/**
 * Tests the payload the content module warning is painted from.
 *
 * Both the identifier and the field names are a contract with
 * Resources/Public/ContentModule/AutomaticReleaseWarning.js, which has no way of noticing that they changed.
 */
final class AutomaticReleaseStatusDataSourceTest extends UnitTestCase
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
            'suppressedReleaseCount' => '4'
        ]);

        self::assertSame(
            [
                'paused' => true,
                'message' => 'translated: automaticReleases.paused.contentModuleWarning'
            ],
            $this->buildDataSource($pauseState)->getData()
        );
    }

    public function testTheTimestampAndTheWaitingCountAreHandedToTheTranslation(): void
    {
        $pauseState = AutomaticReleasePauseState::fromRedisHash([
            'pausedAt' => '2026-08-13T09:15:00+02:00',
            'suppressedReleaseCount' => '4'
        ]);

        $translator = $this->createMock(Translator::class);
        $translator
            ->expects(self::once())
            ->method('translateById')
            ->with(
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
            $translator
                ->method('translateById')
                ->willReturnCallback(static fn(string $labelId): string => 'translated: ' . $labelId);
        }

        $backendDateFormatter = $this->createMock(BackendDateFormatter::class);
        $backendDateFormatter->method('format')->willReturn('formatted date');

        $dataSource = new AutomaticReleaseStatusDataSource();
        $this->inject($dataSource, 'automaticReleaseSwitchService', $automaticReleaseSwitchService);
        $this->inject($dataSource, 'translator', $translator);
        $this->inject($dataSource, 'backendDateFormatter', $backendDateFormatter);

        return $dataSource;
    }
}
