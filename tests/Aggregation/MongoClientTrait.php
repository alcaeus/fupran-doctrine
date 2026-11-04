<?php

declare(strict_types=1);

namespace App\Tests\Aggregation;

use MongoDB\Client;
use UnexpectedValueException;

use function is_string;

trait MongoClientTrait
{
    private function getClient(): Client
    {
        $uri = $_SERVER['MONGODB_URL'] ?? null;
        if (! is_string($uri)) {
            throw new UnexpectedValueException('Expected the MONGODB_URL environment variable to be set.');
        }

        return new Client($uri);
    }
}
