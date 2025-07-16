<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Core\Infrastructure;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;

interface StatisticsEventOutputInterface
{
    public function writeEvent(ContentReleaseIdentifier $contentReleaseIdentifier, string $prefix, string $event, array $additionalPayload): void;
}
