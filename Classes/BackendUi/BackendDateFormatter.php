<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\BackendUi;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Cldr\Reader\DatesReader;
use Neos\Flow\I18n\Formatter\DatetimeFormatter;
use Neos\Flow\I18n\Service as LocalizationService;

/**
 * One date format for every place the backend shows a timestamp, so that the same moment does not read differently
 * depending on which screen it is displayed on.
 *
 * The locale is the backend user's interface language, which the Neos controllers put into the localization
 * configuration for us.
 */
#[Flow\Scope('singleton')]
class BackendDateFormatter
{
    #[Flow\Inject]
    protected LocalizationService $localizationService;

    #[Flow\Inject]
    protected DatetimeFormatter $datetimeFormatter;

    public function format(\DateTimeInterface $dateTime): string
    {
        return $this->datetimeFormatter->formatDateTime(
            $dateTime,
            $this->localizationService->getConfiguration()->getCurrentLocale(),
            DatesReader::FORMAT_LENGTH_MEDIUM,
        );
    }
}
