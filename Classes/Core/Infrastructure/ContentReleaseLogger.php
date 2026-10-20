<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Core\Infrastructure;

use Exception;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\RendererIdentifier;
use Neos\Flow\Cli\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class ContentReleaseLogger
{
    private OutputInterface $output;

    private StatisticsEventOutputInterface $statisticsEventOutput;

    private ContentReleaseIdentifier $contentReleaseIdentifier;

    private string $logPrefix;

    private function __construct(
        OutputInterface $output,
        ContentReleaseIdentifier $contentReleaseIdentifier,
        StatisticsEventOutputInterface $statisticsEventOutput,
        ?RendererIdentifier $rendererIdentifier,
    ) {
        $this->output = $output;
        $this->contentReleaseIdentifier = $contentReleaseIdentifier;
        $this->statisticsEventOutput = $statisticsEventOutput;
        $this->logPrefix = $rendererIdentifier !== null ? '[Renderer ' . $rendererIdentifier->string() . '] ' : '';
    }

    public static function fromConsoleOutput(
        ConsoleOutput $output,
        ContentReleaseIdentifier $contentReleaseIdentifier,
        StatisticsEventOutputInterface $statisticsEventOutput = new RedisStatisticsEventOutput(),
    ): self {
        return new static($output->getOutput(), $contentReleaseIdentifier, $statisticsEventOutput, null);
    }

    public static function fromSymfonyOutput(
        OutputInterface $output,
        ContentReleaseIdentifier $contentReleaseIdentifier,
        StatisticsEventOutputInterface $statisticsEventOutput = new RedisStatisticsEventOutput(),
    ): self {
        return new static($output, $contentReleaseIdentifier, $statisticsEventOutput, null);
    }

    public function debug(string $message, array $additionalPayload = []): void
    {
        $this->logToOutput('DEBUG', $message, $additionalPayload);
    }

    public function info(string $message, array $additionalPayload = []): void
    {
        $this->logToOutput('INFO', $message, $additionalPayload);
    }

    public function warn(string $message, array $additionalPayload = []): void
    {
        $this->logToOutput('WARNING', $message, $additionalPayload);
    }

    public function error(string $message, array $additionalPayload = []): void
    {
        $this->logToOutput('ERROR', $message, $additionalPayload);
    }

    private function logToOutput(string $level, string $message, array $additionalPayload = []): void
    {
        $formattedPayload = $additionalPayload ? ' ' . json_encode($additionalPayload) : '';
        $this->output->writeln($this->timePrefix() . $this->logPrefix . $level . ': ' . $message . $formattedPayload);
    }

    public function logException(Exception $exception, string $message, array $additionalPayload): void
    {
        $this->output->writeln(
            $this->timePrefix()
                . $this->logPrefix
                . $message
                . "\n\n"
                . $exception->getMessage()
                . "\n\n"
                . $exception->getTraceAsString()
                . "\n\n"
                . json_encode($additionalPayload),
        );
    }

    /**
     * Every log line carries the wall-clock time it was written, so that a pipeline task which looks
     * stuck in the UI can be told apart from one which is simply slow (compare the last line's time).
     */
    private function timePrefix(): string
    {
        return '[' . date('H:i:s') . '] ';
    }

    public function logStatisticsEvent(string $event, array $additionalPayload = []): void
    {
        $this->statisticsEventOutput->writeEvent(
            $this->contentReleaseIdentifier,
            $this->logPrefix,
            $event,
            $additionalPayload,
        );
    }

    public function withRenderer(RendererIdentifier $rendererIdentifier): self
    {
        return new ContentReleaseLogger(
            $this->output,
            $this->contentReleaseIdentifier,
            $this->statisticsEventOutput,
            $rendererIdentifier,
        );
    }
}
