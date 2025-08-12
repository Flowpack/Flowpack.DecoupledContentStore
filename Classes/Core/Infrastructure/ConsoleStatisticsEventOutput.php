<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Core\Infrastructure;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Neos\Flow\Cli\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

class ConsoleStatisticsEventOutput implements StatisticsEventOutputInterface
{
    protected OutputInterface $output;

    function __construct(OutputInterface $output)
    {
        $this->output = $output;
    }

    public static function fromConsoleOutput(ConsoleOutput $output): self
    {
        return static::fromSymfonyOutput($output->getOutput());
    }

    public static function fromSymfonyOutput(OutputInterface $output): self
    {
        return new static($output);
    }

    public function writeEvent(ContentReleaseIdentifier $contentReleaseIdentifier, string $prefix, string $event, array $additionalPayload): void
    {
        $this->output->writeln($prefix . 'STATISTICS EVENT ' . $event . ($additionalPayload ? ' ' . json_encode($additionalPayload) : ''));
    }
}
