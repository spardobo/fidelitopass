<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FormattableHandlerInterface;

class JsonLoggingTap
{
    /**
     * Configures native JSON output on an emitting channel's handlers.
     *
     * @param  Logger  $logger  Resolved Laravel channel whose formattable handlers emit JSON.
     */
    public function __invoke(Logger $logger): void
    {
        $formatter = new JsonFormatter(JsonFormatter::BATCH_MODE_NEWLINES);
        $formatter->includeStacktraces();

        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface) {
                $handler->setFormatter($formatter);
            }
        }
    }
}
