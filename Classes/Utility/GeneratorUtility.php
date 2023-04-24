<?php

namespace Flowpack\DecoupledContentStore\Utility;

class GeneratorUtility
{

    /**
     * @template T
     * @param iterable<T> $iterable
     * @param int $chunkSize
     * @return iterable<array<T>>
     */
    static public function createArrayBatch(iterable $iterable, int $chunkSize): iterable
    {
        $accumulator = [];
        $i = 0;
        foreach ($iterable as $item) {
            $i++;
            $accumulator[] = $item;
            if ($i % $chunkSize === 0) {
                yield $accumulator;

                $accumulator = [];
                $i = 0;
            }
        }

        if ($i > 0) {
            yield $accumulator;
        }
    }
}
