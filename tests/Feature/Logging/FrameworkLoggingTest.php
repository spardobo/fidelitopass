<?php

use App\Http\Middleware\RequestLogContext;
use App\Logging\JsonLoggingTap;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Log\Logger as LaravelLogger;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Monolog\LogRecord;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function () {
    $this->logPath = tempnam(sys_get_temp_dir(), 'safe-log-');
    config([
        'logging.default' => 'probe',
        'logging.channels.probe' => [
            ...config('logging.channels.single'),
            'path' => $this->logPath,
            'level' => 'debug',
        ],
    ]);
    app('log')->forgetChannel('probe');
});

afterEach(function () {
    app('log')->forgetChannel('probe');
    unlink($this->logPath);
});

it('uses the real container native channel and thresholds for every severity', function (string $threshold, int $count) {
    config(['logging.channels.probe.level' => $threshold]);
    expect(app('log')::class)->toBe(LogManager::class);
    foreach (Level::cases() as $level) {
        Log::log(strtolower($level->name), 'customer_pass.credentials.prepared', [
            'business_id' => 1,
        ]);
    }

    $lines = array_filter(explode("\n", file_get_contents($this->logPath)));
    expect($lines)->toHaveCount($count);
    foreach ($lines as $line) {
        $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        expect($record)->toHaveKeys(['message', 'context', 'level', 'level_name', 'channel', 'datetime', 'extra']);
        expect($record['context'])->toBe(['business_id' => 1]);
    }
})->with(['debug' => ['debug', 8], 'warning' => ['warning', 5], 'error' => ['error', 4]]);

it('registers one JSON tap only on emitting leaf channels', function (string $channel, bool $emits) {
    $configuration = config('logging.channels.'.$channel);

    expect($configuration['tap'] ?? [])->toBe($emits ? [JsonLoggingTap::class] : []);
    expect($configuration)->not->toHaveKeys(['formatter', 'formatter_with']);
})->with([
    'single' => ['single', true],
    'daily' => ['daily', true],
    'monthly' => ['monthly', true],
    'stderr' => ['stderr', true],
    'syslog' => ['syslog', true],
    'errorlog' => ['errorlog', true],
    'papertrail' => ['papertrail', true],
    'slack' => ['slack', true],
    'stack' => ['stack', false],
    'null' => ['null', false],
    'emergency' => ['emergency', false],
]);

it('configures native formattable handlers without changing non-formatting handlers', function () {
    $stream = new StreamHandler('php://memory');
    $null = new NullHandler;
    $native = new MonologLogger('tap-probe', [$stream, $null]);

    (new JsonLoggingTap)(new LaravelLogger($native));

    expect($stream->getFormatter()::class)->toBe(JsonFormatter::class);
    expect($native->getHandlers())->toBe([$stream, $null]);
    $native->close();
});

it('formats supported collection handlers without using a remote destination', function (string $channel) {
    $configuration = config('logging.channels.'.$channel);
    expect($configuration['tap'])->toBe([JsonLoggingTap::class]);
    if (in_array($channel, ['single', 'daily', 'monthly'], true)) {
        $configuration['path'] = $this->logPath;
    }
    if ($channel === 'papertrail') {
        $configuration['handler_with'] = ['host' => '127.0.0.1', 'port' => 514];
    }

    config(['logging.channels.'.$channel => $configuration]);
    app('log')->forgetChannel($channel);
    $logger = Log::channel($channel);
    $formatter = $logger->getLogger()->getHandlers()[0]->getFormatter();
    expect($formatter::class)->toBe(JsonFormatter::class);
    expect($formatter->getBatchMode())->toBe(JsonFormatter::BATCH_MODE_NEWLINES);
    expect($formatter->isAppendingNewlines())->toBeTrue();
    $record = new LogRecord(new DateTimeImmutable, 'application', Level::Info, 'application.started');
    $line = $formatter->format($record);
    expect($line)->toEndWith("\n");
    expect(json_decode($line, true, flags: JSON_THROW_ON_ERROR))->toHaveKeys([
        'message', 'context', 'level', 'level_name', 'channel', 'datetime', 'extra',
    ]);
    expect($formatter->formatBatch([$record, $record]))->toBe(rtrim($line, "\n")."\n".rtrim($line, "\n"));
    $logger->getLogger()->close();
})->with(['single', 'daily', 'monthly', 'stderr', 'syslog', 'errorlog', 'papertrail']);

it('keeps optional Slack notification text inside the safe envelope without attachments or requests', function () {
    $configuration = config('logging.channels.slack');
    expect($configuration['attachment'])->toBeFalse()->and($configuration['context'])->toBeFalse();
    expect($configuration['tap'])->toBe([JsonLoggingTap::class]);
    $configuration['url'] = 'http://127.0.0.1/unused-test-webhook';
    config(['logging.channels.slack' => $configuration]);
    app('log')->forgetChannel('slack');
    $logger = Log::channel('slack');
    $handler = $logger->getLogger()->getHandlers()[0];
    expect($handler->getFormatter()::class)->toBe(JsonFormatter::class);
    expect($handler->getFormatter()->getBatchMode())->toBe(JsonFormatter::BATCH_MODE_NEWLINES);
    $slack = $handler->getSlackRecord();
    $data = $slack->getSlackData(new LogRecord(new DateTimeImmutable, 'application', Level::Error,
        'wallet.synchronization.failed', ['reason' => 'provider_unavailable']));
    expect($data)->not->toHaveKey('attachments');
    expect(json_decode($data['text'], true, flags: JSON_THROW_ON_ERROR)['context'])
        ->toBe(['reason' => 'provider_unavailable']);
    expect(json_decode($data['text'], true, flags: JSON_THROW_ON_ERROR)['message'])->toBe('wallet.synchronization.failed');
    $logger->getLogger()->close();
});

it('reports safe exception diagnostics once and preserves ignored exceptions', function (bool $isDatabaseFailure) {
    $handler = app(ExceptionHandler::class);
    $failure = $isDatabaseFailure
        ? new QueryException('pgsql', 'select secret_column where token = ?', ['secret-binding'], new RuntimeException('secret-inner'))
        : new RuntimeException('secret-outer', previous: new RuntimeException('secret-inner'));
    $handler->report($failure);
    $handler->report(new NotFoundHttpException('secret-ignored'));

    $lines = array_filter(explode("\n", file_get_contents($this->logPath)));
    expect($lines)->toHaveCount(1);
    expect(implode('', $lines))->not->toContain('secret', 'secret_column', 'arguments', 'previous', '"args"', '"object"', '"type"');
    $record = json_decode(array_values($lines)[0], true, flags: JSON_THROW_ON_ERROR);
    expect($record['context'])->toMatchArray([
        'exception_class' => $isDatabaseFailure ? QueryException::class : RuntimeException::class,
        'source_file' => $failure->getFile(),
        'source_line' => $failure->getLine(),
    ]);
    expect($record['context']['exception_frames'])->not->toBeEmpty();
    foreach ($record['context']['exception_frames'] as $frame) {
        expect(array_diff(array_keys($frame), ['file', 'line', 'class', 'function']))->toBe([]);
    }
    expect(json_decode(array_values($lines)[0], true, flags: JSON_THROW_ON_ERROR)['message'])
        ->toBe('application.unexpected_exception');
})->with(['runtime failure' => false, 'database failure' => true]);

it('provides only selected safe exception diagnostics to native log listeners', function (bool $isDatabaseFailure) {
    $events = [];
    Log::listen(function (MessageLogged $event) use (&$events): void {
        $events[] = $event;
    });
    $ignoreArguments = ini_set('zend.exception_ignore_args', '0');
    try {
        $failure = (new FrameworkLoggingFailureProbe)->capture('private-ordinary-token', 'private-sensitive-token', 30, $isDatabaseFailure);
    } finally {
        ini_set('zend.exception_ignore_args', $ignoreArguments);
    }

    app(ExceptionHandler::class)->report($failure);

    expect($events)->toHaveCount(1);
    expect($events[0]->message)->toBe('application.unexpected_exception');
    $context = $events[0]->context;
    expect(array_keys($context))->toBe([
        'exception_class',
        'source_file',
        'source_line',
        'exception_frames',
    ]);
    foreach ($context as $value) {
        expect($value)->not->toBeInstanceOf(Throwable::class);
    }
    expect($context['exception_frames'])->toHaveCount(count($failure->getTrace()));
    foreach ($context['exception_frames'] as $frame) {
        expect($frame)->toBeArray();
        expect(array_diff(array_keys($frame), ['file', 'line', 'class', 'function']))->toBe([]);
        foreach ($frame as $value) {
            expect($value)->toBeScalar();
        }
    }
    expect(json_encode($context, JSON_THROW_ON_ERROR))
        ->not->toContain('private-', 'secret_column', 'select ', 'bindings', 'previous', 'SensitiveParameterValue', '"args"', '"object"');
})->with(['runtime failure' => false, 'database failure' => true]);

it('retains the complete native caller chain and excludes ordinary and sensitive arguments', function (bool $isDatabaseFailure) {
    $ignoreArguments = ini_set('zend.exception_ignore_args', '0');
    try {
        $failure = (new FrameworkLoggingFailureProbe)->capture('private-ordinary-token', 'private-sensitive-token', 30, $isDatabaseFailure);
    } finally {
        ini_set('zend.exception_ignore_args', $ignoreArguments);
    }
    expect($failure->getTrace()[0]['args'][0])->toBe('private-ordinary-token');
    expect($failure->getTrace()[0]['args'][1])->toBeInstanceOf(SensitiveParameterValue::class);

    app(ExceptionHandler::class)->report($failure);

    $output = trim(file_get_contents($this->logPath));
    $record = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    $frames = $record['context']['exception_frames'];
    expect(count($failure->getTrace()))->toBeGreaterThan(20);
    expect($frames)->toHaveCount(count($failure->getTrace()));
    expect($frames[0])->toBe([
        'file' => $failure->getTrace()[0]['file'],
        'line' => $failure->getTrace()[0]['line'],
        'function' => 'capture',
        'class' => FrameworkLoggingFailureProbe::class,
    ]);
    foreach ($failure->getTrace() as $index => $nativeFrame) {
        foreach (['file', 'line', 'class', 'function'] as $field) {
            if (isset($nativeFrame[$field])) {
                expect($frames[$index][$field])->toBe($nativeFrame[$field]);
            }
        }
    }
    expect($record['context']['source_line'])->toBe($failure->getLine());
    expect($record['context']['source_line'])->not->toBe($frames[0]['line']);
    expect($output)->not->toContain('private-', 'secret_column', 'select ', 'bindings', 'previous', 'SensitiveParameterValue', '"args"', '"object"', '"type"');
})->with(['runtime failure' => false, 'database failure' => true]);

it('retains native source paths and closure and anonymous class symbols for server diagnostics', function () {
    $probe = new class
    {
        /** @return Throwable Failure captured through a native closure and anonymous class. */
        public function capture(): Throwable
        {
            return (fn () => new class('private-anonymous-message') extends RuntimeException {})();
        }
    };
    $failure = $probe->capture();
    expect($failure->getTrace()[0]['function'])->toContain('{closure:');
    expect($failure::class)->toContain("\0");

    app(ExceptionHandler::class)->report($failure);

    $output = trim(file_get_contents($this->logPath));
    $context = json_decode($output, true, flags: JSON_THROW_ON_ERROR)['context'];
    expect($context['exception_frames'][0]['function'])->toBe($failure->getTrace()[0]['function']);
    expect($context['exception_frames'][1]['class'])->toBe($failure->getTrace()[1]['class']);
    expect($context['exception_class'])->toBe($failure::class);
    expect($context['source_file'])->toBe($failure->getFile());
    expect($output)->not->toContain('private-anonymous-message');
});

it('generates fresh request correlation ignores forged headers and restores shared context', function () {
    $logs = app('log');
    $logs->shareContext(['worker' => 'test-worker']);
    Route::get('/logging-probe', function () {
        Log::info('customer_pass.credentials.prepared', ['batch' => ['size' => 2]]);

        return response()->json(['ok' => true]);
    });

    $first = $this->getJson('/logging-probe', ['X-Request-ID' => 'forged-secret']);
    $firstRecord = json_decode(trim(file_get_contents($this->logPath)), true, flags: JSON_THROW_ON_ERROR);
    $firstId = $firstRecord['context']['request_id'];
    $second = $this->getJson('/logging-probe', ['X-Request-ID' => $firstId]);
    $first->assertOk()->assertHeaderMissing('X-Request-ID');
    $second->assertOk()->assertHeaderMissing('X-Request-ID');
    expect($logs->sharedContext())->toBe(['worker' => 'test-worker']);
    $records = array_map(fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
        array_values(array_filter(explode("\n", file_get_contents($this->logPath)))));
    expect($records)->toHaveCount(2);
    $secondId = $records[1]['context']['request_id'];
    expect(Str::isUuid($firstId, 7))->toBeTrue();
    expect(Str::isUuid($secondId, 7))->toBeTrue();
    expect($secondId)->not->toBe($firstId);
    expect(file_get_contents($this->logPath))->not->toContain('secret', '/logging-probe');
});

it('inherits request correlation when reporting inside the actual middleware scope', function () {
    Route::get('/logging-report-probe', function () {
        app(ExceptionHandler::class)->report(new RuntimeException('private-in-scope-failure'));

        return response()->json(['ok' => true]);
    });

    $response = $this->getJson('/logging-report-probe');

    $response->assertOk()->assertHeaderMissing('X-Request-ID');
    $record = json_decode(trim(file_get_contents($this->logPath)), true, flags: JSON_THROW_ON_ERROR);
    expect($record['message'])->toBe('application.unexpected_exception');
    expect(Str::isUuid($record['context']['request_id'], 7))->toBeTrue();
    expect(file_get_contents($this->logPath))->not->toContain('private-');
    expect(app('log')->sharedContext())->toBe([]);
});

it('reports safely after exceptional unwind without recovering request correlation', function () {
    $middleware = new RequestLogContext;
    $request = Request::create('/secret-path');
    app()->instance('request', $request);
    $logs = app('log');
    $logs->shareContext(['business_id' => 19]);
    $failure = new RuntimeException('secret-exception');
    try {
        $middleware->handle($request, function () use ($failure) {
            throw $failure;
        });
        test()->fail('Downstream failure must propagate.');
    } catch (RuntimeException $caught) {
        expect($caught)->toBe($failure);
    }
    expect($logs->sharedContext())->toBe(['business_id' => 19]);
    app(ExceptionHandler::class)->report($failure);
    $record = json_decode(trim(file_get_contents($this->logPath)), true, flags: JSON_THROW_ON_ERROR);
    expect($record['context'])->not->toHaveKey('request_id');
    expect($request->attributes->has('request_id'))->toBeFalse();
    expect(file_get_contents($this->logPath))->not->toContain('secret');
});

it('clears downstream channel metadata between requests and restores only shared metadata', function (string $contextMethod, bool $fails) {
    config(['app.debug' => false]);
    $logs = app('log');
    $logs->shareContext(['customer_pass_id' => 7]);
    $logs->channel()->withContext(['provider_status' => 202]);
    Route::get('/logging-context-first', function () use ($logs, $contextMethod, $fails) {
        $logs->{$contextMethod}(['business_id' => 99]);
        Log::info('customer_pass.credentials.prepared');

        if ($fails) {
            throw new RuntimeException('private-first-request');
        }

        return response()->json(['ok' => true]);
    });
    Route::get('/logging-context-next', function () {
        Log::info('customer_pass.credentials.prepared');

        return response()->json(['ok' => true]);
    });

    $first = $this->getJson('/logging-context-first', ['X-Request-ID' => 'forged-secret']);
    $first->assertStatus($fails ? 500 : 200)->assertHeaderMissing('X-Request-ID');
    expect($logs->sharedContext())->toBe(['customer_pass_id' => 7]);
    $firstLine = explode("\n", file_get_contents($this->logPath))[0];
    $firstId = json_decode($firstLine, true, flags: JSON_THROW_ON_ERROR)['context']['request_id'];
    $next = $this->getJson('/logging-context-next', ['X-Request-ID' => $firstId]);
    $next->assertOk()->assertHeaderMissing('X-Request-ID');
    expect($logs->sharedContext())->toBe(['customer_pass_id' => 7]);
    $records = array_map(fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
        array_values(array_filter(explode("\n", file_get_contents($this->logPath)))));
    expect($records)->toHaveCount($fails ? 3 : 2);
    $nextId = $records[array_key_last($records)]['context']['request_id'];
    expect(Str::isUuid($firstId, 7))->toBeTrue();
    expect(Str::isUuid($nextId, 7))->toBeTrue();
    expect($nextId)->not->toBe($firstId);
    expect($records[0]['context']['business_id'])->toBe(99);
    expect($records[0]['context']['request_id'])->toBe($firstId);
    expect($records[array_key_last($records)]['context'])->toBe([
        'customer_pass_id' => 7,
        'request_id' => $nextId,
    ]);
    expect(file_get_contents($this->logPath))->not->toContain('private-', 'forged-secret');
})->with([
    'shared context after success' => ['shareContext', false],
    'channel context after success' => ['withContext', false],
    'shared context after exception' => ['shareContext', true],
    'channel context after exception' => ['withContext', true],
]);

it('uses native emergency output rather than promising the configured JSON envelope', function () {
    config([
        'logging.channels.emergency.path' => $this->logPath,
        'logging.channels.broken' => ['driver' => 'test-invalid-driver'],
    ]);

    $emergency = app('log')->channel('broken');
    $formatter = $emergency->getLogger()->getHandlers()[0]->getFormatter();
    $output = file_get_contents($this->logPath);

    expect($formatter)->toBeInstanceOf(LineFormatter::class);
    expect($output)->toContain('Unable to create configured logger', 'test-invalid-driver');
    expect(json_decode($output, true))->toBeNull();
    $emergency->getLogger()->close();
});

it('surfaces a configured native stack destination write failure', function () {
    expect(config('logging.channels.stack.ignore_exceptions'))->toBeFalse();
    config([
        'logging.default' => 'stack',
        'logging.channels.stack.channels' => ['probe'],
        'logging.channels.probe.path' => '/dev/null/impossible-test-log',
    ]);
    app('log')->forgetChannel('stack');

    expect(fn () => Log::error('customer_pass.creation.failed', ['reason' => 'unexpected_failure']))
        ->toThrow(UnexpectedValueException::class);
    expect(file_get_contents($this->logPath))->toBe('');
});

it('reports a real failing HTTP request once within middleware correlation scope', function () {
    config(['app.debug' => false]);
    Route::get('/logging-failure-probe', function () {
        throw new RuntimeException('private-route-exception', previous: new RuntimeException('private-cause'));
    });

    $response = $this->getJson('/logging-failure-probe', ['X-Request-ID' => 'forged-secret']);
    $response->assertStatus(500)->assertHeaderMissing('X-Request-ID');
    expect($response->getContent())
        ->not->toContain('private-route-exception', 'private-cause', base_path(), 'exception_frames', 'source_file');
    $lines = array_values(array_filter(explode("\n", file_get_contents($this->logPath))));
    expect($lines)->toHaveCount(1);
    $record = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
    expect($record['message'])->toBe('application.unexpected_exception');
    expect(Str::isUuid($record['context']['request_id'], 7))->toBeTrue();
    expect($lines[0])->not->toContain('private-', 'forged-secret');
    expect(app('log')->sharedContext())->toBe([]);
});

it('writes new application-authored event and reason codes without central registration', function () {
    Log::info('promotion.publication.confirmed', ['reason' => 'publication_ready', 'business_id' => 3]);

    $record = json_decode(trim(file_get_contents($this->logPath)), true, flags: JSON_THROW_ON_ERROR);
    expect($record['message'])->toBe('promotion.publication.confirmed');
    expect($record['context'])->toBe(['reason' => 'publication_ready', 'business_id' => 3]);
});

it('surfaces a native log listener failure during exception reporting', function () {
    $attempts = 0;
    Log::listen(function () use (&$attempts): void {
        $attempts++;
        throw new RuntimeException('private-listener-failure');
    });

    expect(fn () => app(ExceptionHandler::class)->report(new RuntimeException('private-original-failure')))
        ->toThrow(RuntimeException::class, 'private-listener-failure');

    expect($attempts)->toBe(1);
    $lines = array_values(array_filter(explode("\n", file_get_contents($this->logPath))));
    expect($lines)->toHaveCount(1);
    expect($lines[0])->not->toContain('private-');
    expect(json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR)['message'])->toBe('application.unexpected_exception');
});

/** Creates native traces with deliberately secret-bearing arguments and causes. */
class FrameworkLoggingFailureProbe
{
    /**
     * Captures a failure after a controlled caller chain without performing a query.
     *
     * @param  string  $ordinary  Deliberately unredacted argument that must not enter logs.
     * @param  string  $sensitive  Argument protected by native parameter redaction.
     * @param  int  $depth  Remaining recursive calls for a long native caller chain.
     * @param  bool  $database  Whether to include SQL and bindings in a native query failure.
     * @return Throwable Native failure carrying private diagnostics for boundary testing.
     */
    public function capture(string $ordinary, #[SensitiveParameter] string $sensitive, int $depth, bool $database): Throwable
    {
        if ($depth > 0) {
            return $this->capture($ordinary, $sensitive, $depth - 1, $database);
        }

        return $database
            ? new QueryException('pgsql', 'select secret_column where token = ?', [$ordinary, $sensitive], new RuntimeException('private-cause'))
            : new RuntimeException('private-message', previous: new RuntimeException('private-cause'));
    }
}
