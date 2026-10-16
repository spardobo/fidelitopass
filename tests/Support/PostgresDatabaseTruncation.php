<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Throwable;

/**
 * Isolates native cleanup on the default PostgreSQL connection without wrapping test bodies.
 */
trait PostgresDatabaseTruncation
{
    use DatabaseTruncation {
        truncateTablesForAllConnections as private truncateTablesUsingFramework;
    }

    /**
     * Commits complete cleanup or rolls it back while preserving the connection dispatcher.
     *
     * @throws Throwable If native cleanup fails.
     */
    public function truncateTablesForAllConnections(): void
    {
        $connection = $this->app->make('db')->connection();
        $dispatcher = $connection->getEventDispatcher();

        try {
            $connection->transaction(fn () => $this->truncateTablesUsingFramework(), 1);
        } finally {
            $connection->setEventDispatcher($dispatcher);
        }
    }
}
