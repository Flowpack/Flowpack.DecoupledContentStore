<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Tests\Behavior\Fixtures;

use Flowpack\Prunner\PrunnerApiService;
use Flowpack\Prunner\ValueObject\JobId;
use Flowpack\Prunner\ValueObject\PipelineName;
use Flowpack\Prunner\ValueObject\QueuePartitionName;

class StubPrunnerApiService extends PrunnerApiService
{
    public array $calls = [];

    public function schedulePipeline(
        PipelineName $pipeline,
        array $variables,
        ?QueuePartitionName $queuePartition = null,
    ): JobId {
        $this->calls[] = [
            'method' => 'schedulePipeline',
            'pipeline' => $pipeline,
            'variables' => $variables,
            'queuePartition' => $queuePartition,
        ];

        return JobId::create('STUB' . count($this->calls));
    }
}
