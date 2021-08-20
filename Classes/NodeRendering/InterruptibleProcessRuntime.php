<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering;

use Flowpack\DecoupledContentStore\NodeRendering\ProcessEvents\ExitEvent;
use Neos\Flow\Annotations as Flow;

/**
 * Our long-lived processes, especially {@see NodeRenderOrchestrator} and {@see NodeRenderer} are kind of hard to test,
 * for the following reasons:
 * - they assume to be run in two separate processes concurrently
 * - they implement a "control loop", which is essentially a long while(true) loop, also with nested loops inside
 *
 * Especially for certain failure scenarios, it is important to be able to run such a process up to a specific point in
 * time, e.g. to then inject an error or simulate a user interaction. Later, processing should continue where we interrupted
 * it.
 * Using "normal control flow" constructs, this is impossible, because you normally cannot interrupt a program inside
 * a method, do something else, and later resume at exactly this point in time.
 *
 * However, using Generators and Yield, exactly this behavior is possible:
 *
 * > A yield statement [...] provides a value to the code looping over the generator and **pauses execution of the generator function**.
 * > (https://www.php.net/manual/en/language.generators.syntax.php#control-structures.yield)
 *
 * Thus, the idea is that such a long-lived process (like {@see NodeRenderOrchestrator}), will yield a set of Events
 * at defined points of its control loop.
 *
 * For "normal" usage (i.e. during production), we simply instantiate the {@see InterruptibleProcessRuntime} and
 * call {@see InterruptibleProcessRuntime::runUntilEnd()}, which **never pauses the generator** because it just pulls
 * values from the generator again and again.
 *
 * When being inside a test case, we instead call {@see InterruptibleProcessRuntime::runUntilEventEncountered()},
 * interrupting the process flow at a specific point in time. Then, we can f.e. inject faults, and continue running.
 *
 * Additionally, the system can handle exit() calls specifically, so you can skip them during Testcases. For this to work,
 * instead of calling exit(), you should `yield ExitEvent::createWithStatusCode()` instead.
 *
 * For this to work,
 *
 * @Flow\Proxy(false)
 */
final class InterruptibleProcessRuntime
{
    private \Generator $generator;

    private bool $inTestingMode;

    private bool $encounteredExitEvent = false;
    private bool $generatorIsPaused = false;

    private function __construct(\Generator $generator, bool $inTestingMode)
    {
        $this->generator = $generator;
        $this->inTestingMode = $inTestingMode;
    }

    /**
     * Generate a new Process Runtime in production mode
     *
     * @param \Generator $generator
     * @return InterruptibleProcessRuntime
     */
    public static function create(\Generator $generator): self
    {
        return new self($generator, false);
    }

    /**
     * Generate a new Process Runtime in testing mode, where {@see ExitEvent} won't trigger an exit() call.
     *
     * @param \Generator $generator
     * @return InterruptibleProcessRuntime
     */
    public static function createForTesting(\Generator $generator): self
    {
        return new self($generator, true);
    }

    /**
     *
     */
    public function runUntilEnd(): void
    {
        $this->runUntilEventEncountered('_____NOT_EXISTING__________');
    }

    /**
     * This is useful in testcases, to run the generator up to a certain point in time.
     * @param string ...$eventClassNames
     * @return InterruptibleProcessRuntimeEventInterface|null the event of type $eventClassName
     */
    public function runUntilEventEncountered(string ...$eventClassNames): ?InterruptibleProcessRuntimeEventInterface
    {
        if ($this->encounteredExitEvent) {
            throw new \RuntimeException('Cannot continue running the Process, as we received an Exit Event already.');
        }

        if ($this->generatorIsPaused) {
            // we need to continue with the NEXT event, otherwise, we read the same event twice.
            $this->generator->next();
        }

        while ($this->generator->valid()) {
            $currentEvent = $this->generator->current();
            if ($currentEvent instanceof ExitEvent) {
                $this->encounteredExitEvent = true;
                // stop iterating the iterator in all cases
                return $this->handleExitEvent($currentEvent);
            }
            $shortName = (new \ReflectionClass($currentEvent))->getShortName();
            foreach ($eventClassNames as $eventClassName) {
                if ($eventClassName === $shortName || is_a($currentEvent, $eventClassName)) {
                    // stop here, can be restarted lateron. We still need to continue to the next event here.
                    $this->generatorIsPaused = true;

                    return $currentEvent;
                }
            }
            // try to read next event (if not stopped)
            $this->generator->next();
        }
        return null;
    }

    protected function handleExitEvent(ExitEvent $event): InterruptibleProcessRuntimeEventInterface
    {
        if ($this->inTestingMode === true) {
            return $event;
        }
        exit($event->getStatusCode());
    }

}