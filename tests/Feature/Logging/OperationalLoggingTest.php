<?php

use Illuminate\Support\Facades\Log;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;

it('formats authored events and open explicit metadata at every PSR level and in batches', function () {
    $formatter = Log::channel('single')->getLogger()->getHandlers()[0]->getFormatter();
    expect($formatter::class)->toBe(JsonFormatter::class);
    $context = [
        'batch_size' => 12,
        'component' => 'import',
        'timing' => ['elapsed_ms' => 2.5, 'cached' => false],
        'labels' => ['first', 'second'],
        'optional' => null,
    ];
    $records = [];

    foreach (Level::cases() as $level) {
        $record = new LogRecord(
            new DateTimeImmutable('2030-01-01T03:00:00+03:00'),
            'application',
            $level,
            'inventory.import.completed',
            $context,
        );
        $line = $formatter->format($record);
        $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

        expect($line)->toEndWith("\n");
        expect($decoded)->toBe([
            'message' => 'inventory.import.completed',
            'context' => $context,
            'level' => $level->value,
            'level_name' => $level->getName(),
            'channel' => 'application',
            'datetime' => '2030-01-01T03:00:00+03:00',
            'extra' => [],
        ]);
        $records[] = $record;
    }

    $batch = $formatter->formatBatch($records);
    $lines = array_values(array_filter(explode("\n", $batch)));
    expect($batch)->not->toEndWith("\n");
    expect($lines)->toHaveCount(8);
    foreach ($lines as $line) {
        expect(json_decode($line, true, flags: JSON_THROW_ON_ERROR)['context'])->toBe($context);
    }
});

it('keeps empty metadata as JSON objects and preserves explicit native extras', function () {
    $line = Log::channel('single')->getLogger()->getHandlers()[0]->getFormatter()->format(new LogRecord(
        new DateTimeImmutable, 'application', Level::Info, 'application.started', [], ['process' => 42],
    ));

    expect(json_decode($line, flags: JSON_THROW_ON_ERROR)->context)->toBeInstanceOf(stdClass::class);
    expect(json_decode($line, true, flags: JSON_THROW_ON_ERROR)['extra'])->toBe(['process' => 42]);
    $empty = Log::channel('single')->getLogger()->getHandlers()[0]->getFormatter()->format(new LogRecord(
        new DateTimeImmutable, 'application', Level::Info, 'application.started',
    ));
    expect(json_decode($empty, flags: JSON_THROW_ON_ERROR)->extra)->toBeInstanceOf(stdClass::class);
});

it('does not attach exception diagnostics to ordinary informational records', function () {
    $path = tempnam(sys_get_temp_dir(), 'info-log-');
    config(['logging.channels.information-probe' => [
        ...config('logging.channels.single'),
        'path' => $path,
    ]]);
    $logger = Log::channel('information-probe');

    try {
        $logger->info('application.started', ['component' => 'worker']);
        $record = json_decode(trim(file_get_contents($path)), true, flags: JSON_THROW_ON_ERROR);

        expect($record['context'])->toBe(['component' => 'worker']);
        expect($record['extra'])->toBe([]);
    } finally {
        $logger->getLogger()->close();
        app('log')->forgetChannel('information-probe');
        unlink($path);
    }
});

it('includes native stacktraces when directly formatting a synthetic exception record', function () {
    $formatter = Log::channel('single')->getLogger()->getHandlers()[0]->getFormatter();
    $failure = new RuntimeException('Synthetic safe diagnostic');

    $line = $formatter->format(new LogRecord(
        new DateTimeImmutable,
        'application',
        Level::Error,
        'formatter.synthetic_exception',
        ['exception' => $failure],
    ));

    $exception = json_decode($line, true, flags: JSON_THROW_ON_ERROR)['context']['exception'];
    expect($exception['class'])->toBe(RuntimeException::class);
    expect($exception)->toHaveKey('trace');
    expect($exception['trace'])->not->toBeEmpty();
});

it('keeps the example envelope and open context aligned with the machine contract', function () {
    $schema = json_decode(file_get_contents(base_path('tests/Fixtures/Logging/operational-log.schema.json')), true, flags: JSON_THROW_ON_ERROR);
    $example = json_decode(file_get_contents(base_path('tests/Fixtures/Logging/operational-log.example.json')), true, flags: JSON_THROW_ON_ERROR);
    $line = Log::channel('single')->getLogger()->getHandlers()[0]->getFormatter()->format(new LogRecord(
        new DateTimeImmutable($example['datetime']), 'application', Level::Info, $example['message'], $example['context'],
    ));

    expect(array_keys($example))->toBe($schema['required']);
    expect(json_decode($line, true, flags: JSON_THROW_ON_ERROR))->toBe($example);
    expect($schema['properties']['context'])->toBe(['type' => 'object', 'additionalProperties' => true]);
    expect($schema['properties']['message'])->toBe(['type' => 'string']);
    expect($schema['properties']['extra'])->toBe(['type' => 'object', 'additionalProperties' => true]);
    expect($schema['properties']['datetime'])->toBe(['type' => 'string', 'format' => 'date-time']);
    expect($schema['properties']['level_name']['enum'])->toBe(array_map(fn (Level $level): string => $level->getName(), Level::cases()));
    expect($schema['properties']['level']['enum'])->toBe(array_map(fn (Level $level): int => $level->value, Level::cases()));
});
