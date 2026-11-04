<?php

declare(strict_types=1);

namespace App\Tests\Aggregation;

use App\Aggregation\ChunkQueue;
use MongoDB\BSON\Binary;
use MongoDB\Client;
use MongoDB\Collection;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

use function is_string;
use function uniqid;

class ChunkQueueTest extends TestCase
{
    private Collection $collection;

    protected function setUp(): void
    {
        $this->collection = $this->getClient()->getDatabase('test')->getCollection(uniqid('chunkQueueTest_'));
    }

    protected function tearDown(): void
    {
        $this->collection->drop();
    }

    public function testClaimsEachChunkOnceInOrder(): void
    {
        $a = new Binary('aaaaaaaaaaaaaaaa', Binary::TYPE_UUID);
        $b = new Binary('bbbbbbbbbbbbbbbb', Binary::TYPE_UUID);
        $c = new Binary('cccccccccccccccc', Binary::TYPE_UUID);

        $queue = new ChunkQueue($this->collection);
        $queue->enqueue([[$a, $b], [$c]]);

        $first = $queue->claim();
        $second = $queue->claim();

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame(0, $first['id']);
        self::assertEquals([$a, $b], $first['stations']);
        self::assertSame(1, $second['id']);
        self::assertEquals([$c], $second['stations']);
        self::assertNull($queue->claim());

        $queue->complete(0);
        self::assertSame(1, $this->collection->countDocuments(['_id' => 0, 'state' => 'done']));
        self::assertSame(1, $this->collection->countDocuments(['_id' => 1, 'state' => 'running']));
    }

    public function testEmptyQueueHasNothingToClaim(): void
    {
        $queue = new ChunkQueue($this->collection);
        $queue->enqueue([]);

        self::assertNull($queue->claim());
    }

    private function getClient(): Client
    {
        $uri = $_SERVER['MONGODB_URL'] ?? null;
        if (! is_string($uri)) {
            throw new UnexpectedValueException('Expected the MONGODB_URL environment variable to be set.');
        }

        return new Client($uri);
    }
}
