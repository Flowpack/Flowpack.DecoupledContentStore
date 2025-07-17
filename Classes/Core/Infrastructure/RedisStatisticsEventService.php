<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Core\Infrastructure;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\RedisKeyService;
use Flowpack\DecoupledContentStore\Exception;
use Neos\Flow\Annotations as Flow;

#[Flow\Scope("singleton")]
class RedisStatisticsEventService
{
    #[Flow\Inject]
    protected RedisClientManager $redisClientManager;

    #[Flow\Inject]
    protected RedisKeyService $redisKeyService;

    public function addEvent(ContentReleaseIdentifier $contentReleaseIdentifier, string $prefix, string $event, array $additionalPayload): void
    {
        $this->redisClientManager->getPrimaryRedis()->rPush($this->redisKeyService->getRedisKeyForPostfix($contentReleaseIdentifier, 'statisticsEvents'), json_encode([
            'event' => $event,
            'prefix' => $prefix,
            'additionalPayload' => $additionalPayload,
        ]));
    }

    /**
     * @param ContentReleaseIdentifier $contentReleaseIdentifier
     * @param array<string,string> $where
     * @param string[] $groupBy
     * @return array<>
     * @throws Exception
     */
    public function countEvents(
        ContentReleaseIdentifier $contentReleaseIdentifier,
        array                    $where,
        array                    $groupBy,
    ): array
    {
        $redis = $this->redisClientManager->getPrimaryRedis();
        $key = $this->redisKeyService->getRedisKeyForPostfix($contentReleaseIdentifier, 'statisticsEvents');
        $chunkSize = 1000;

        $countedEvents = [];

        $listLength = $redis->lLen($key);
        for ($start = 0; $start < $listLength; $start += $chunkSize) {
            $events = $redis->lRange($key, $start, $start + $chunkSize - 1);

            foreach ($events as $eventJson) {
                $event = $this->flatten(json_decode($eventJson, true));
                if($this->shouldCount($event, $where)) {
                    $group = $this->groupValues($event, $groupBy);
                    $eventKey = json_encode($group);
                    if (array_key_exists($eventKey, $countedEvents)) {
                        $countedEvents[$eventKey]['count'] += 1;
                    } else {
                        $countedEvents[$eventKey] = array_merge(['count' => 1], $group);
                    }
                }
            }
        }
        // throw away the keys and sort in _reverse_ order by count
        usort($countedEvents, fn($a, $b) => $b['count'] - $a['count']);
        return $countedEvents;
    }

    /**
     * @phpstan-type JSONArray array<string, string|JSONArray>
     *´
     * @param JSONArray $array
     * @return array<string,string>
     */
    private function flatten(array $array): array
    {
        $results = [];

        foreach ($array as $key => $value) {
            if (is_array($value) && ! empty($value)) {
                foreach ($this->flatten($value) as $subKey => $subValue) {
                    $results[$key . '.' . $subKey] = $subValue;
                }
            } else {
                $results[$key] = $value;
            }
        }

        return $results;
    }

    /**
     * @param array<string,string> $event
     * @param array<string,string> $where
     * @return bool
     */
    private function shouldCount(array $event, array $where): bool
    {
        foreach ($where as $key=>$value) {
            if (!array_key_exists($key, $event) || $event[$key] !== $value) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string,string> $event
     * @param string[] $groupedBy
     * @return array<string,string>
     */
    private function groupValues(array $event, array $groupedBy): array
    {
        $group = [];
        foreach ($groupedBy as $path) {
            $group[$path] = $event[$path] ?? null;
        }
        return $group;
    }
}
