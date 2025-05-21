<?php

namespace Flowpack\DecoupledContentStore\Core\Infrastructure;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\RendererIdentifier;
use Neos\Flow\Cli\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

class ContentReleaseLogger
{
    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * @var ContentReleaseIdentifier
     */
    protected $contentReleaseIdentifier;

    protected string $logPrefix = '';

    protected ?RendererIdentifier $rendererIdentifier;

    protected function __construct(OutputInterface $output, ContentReleaseIdentifier $contentReleaseIdentifier, ?RendererIdentifier $rendererIdentifier)
    {
        $this->output = $output;
        $this->contentReleaseIdentifier = $contentReleaseIdentifier;
        $this->rendererIdentifier = $rendererIdentifier;
        $this->logPrefix = '';

        if ($this->rendererIdentifier !== null) {
            $this->logPrefix = '[Renderer ' . $this->rendererIdentifier->string() . '] ';
        }
    }


    public static function fromConsoleOutput(ConsoleOutput $output, ContentReleaseIdentifier $contentReleaseIdentifier): self
    {
        return new static($output->getOutput(), $contentReleaseIdentifier, null);
    }

    public static function fromSymfonyOutput(OutputInterface $output, ContentReleaseIdentifier $contentReleaseIdentifier): self
    {
        return new static($output, $contentReleaseIdentifier, null);
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

    protected function logToOutput(string $level, string $message, array $additionalPayload = []): void
    {
        $formattedPayload = $additionalPayload ? json_encode($additionalPayload) : '';
        $this->output->writeln($this->logPrefix . $level . ': ' . $message . $formattedPayload);
    }

    public function logException(\Exception $exception, string $message, array $additionalPayload)
    {
        $this->output->writeln($this->logPrefix . $message . "\n\n" . $exception->getMessage() . "\n\n" . $exception->getTraceAsString() . "\n\n" . json_encode($additionalPayload));
    }

    public function withRenderer(RendererIdentifier $rendererIdentifier): self
    {
        return new ContentReleaseLogger($this->output, $this->contentReleaseIdentifier, $rendererIdentifier);
    }
}
