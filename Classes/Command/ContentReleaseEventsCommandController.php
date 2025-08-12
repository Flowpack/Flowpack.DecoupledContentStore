<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Command;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\PrunnerJobId;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisStatisticsEventService;
use Neos\Flow\Annotations as Flow;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Neos\Flow\Cli\CommandController;

/**
 * Commands to read statistics events for a content release from redis
 */
class ContentReleaseEventsCommandController extends CommandController
{
    #[Flow\Inject]
    protected RedisStatisticsEventService $redisStatisticsEventService;

    /**
     * Count statistics events in a content release.
     *
     * This command will count how many statics events with given filters, grouped by the specified keys exist in a content release.
     *
     * Use --where to filter the events (e.g. "--where=event=title") and --groupBy to count events separately
     * (e.g. "--groupBy=additionalPayload.preset"). You can specify multiple filters and groups by separating them with
     * ',' (e.g "--groupBy=additionalPayloads.preset,additionalPayloads.documentId")
     *
     * Do not use "--where event=title"! Flow will remove "event=" and the filter will not be applied.
     *
     * @param string $contentReleaseIdentifier The contentReleaseIdentifier which statistics should be counted
     * @param string $where filter the events before counting
     * @param string $groupBy group the events by this value into separately counted groups
     * @return void
     */
    public function countStatisticsEventCommand(string $contentReleaseIdentifier, string $where = '', string $groupBy = ''): void
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        // split every string in $where by the first '=' and use the left part as key and the right part as value
        $where = $where ? array_column(array_map(fn($s) => explode('=', $s, 2), explode(',', $where)), 1, 0) : [];
        $groupBy = $groupBy ? explode(',', $groupBy) : [];

        $this->output("Filters: \n");
        if($where) {
            foreach ($where as $key=>$value) {
                $this->output("  $key = \"$value\"\n");
            }
        } else {
            $this->output("  None \n");
        }

        $eventCounts = $this->redisStatisticsEventService->countEvents($contentReleaseIdentifier, $where, $groupBy);
        $this->output->outputTable($eventCounts, array_merge(['count'], $groupBy));

        $this->output("Total: %d\n", [array_sum(array_map(fn($e) => $e['count'], $eventCounts))]);
    }
}
