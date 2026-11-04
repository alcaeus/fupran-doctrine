<?php

declare(strict_types=1);

namespace App\Aggregation;

use JsonException;
use UnexpectedValueException;

use function is_array;
use function is_int;
use function is_numeric;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/** Outcome of aggregating a single chunk of stations; workers report it to the parent as one JSON line. */
final readonly class ChunkResult
{
    /**
     * @param int|null $spills Number of times the server reported spilling to disk while the chunk ran. This is a
     *                         server-wide counter, so it also includes spills of chunks running concurrently.
     *                         Null if the server does not expose it.
     */
    public function __construct(
        public int $chunk,
        public int $stations,
        public int $documents,
        public float $seconds,
        public ?int $spills,
    ) {
    }

    public function toJson(): string
    {
        return json_encode([
            'chunk' => $this->chunk,
            'stations' => $this->stations,
            'documents' => $this->documents,
            'seconds' => $this->seconds,
            'spills' => $this->spills,
        ], JSON_THROW_ON_ERROR);
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UnexpectedValueException('Invalid chunk result: ' . $json, 0, $e);
        }

        if (! is_array($data)) {
            throw new UnexpectedValueException('Invalid chunk result: ' . $json);
        }

        $chunk = $data['chunk'] ?? null;
        $stations = $data['stations'] ?? null;
        $documents = $data['documents'] ?? null;
        $seconds = $data['seconds'] ?? null;
        $spills = $data['spills'] ?? null;

        if (! is_int($chunk) || ! is_int($stations) || ! is_int($documents) || ! is_numeric($seconds) || ($spills !== null && ! is_int($spills))) {
            throw new UnexpectedValueException('Invalid chunk result: ' . $json);
        }

        return new self($chunk, $stations, $documents, (float) $seconds, $spills);
    }
}
