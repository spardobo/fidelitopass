<?php

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;

it('keeps PHPUnit application logs observable without a console sink', function () {
    $records = [];
    Log::listen(function (MessageLogged $record) use (&$records): void {
        $records[] = $record;
    });
    Log::shareContext(['probe_id' => 'phpunit-probe']);

    Log::error('phpunit.logging.probe', ['operation' => 'test']);

    expect(config('logging.default'))->toBe('null')
        ->and($records)->toHaveCount(1)
        ->and($records[0]->level)->toBe('error')
        ->and($records[0]->message)->toBe('phpunit.logging.probe')
        ->and($records[0]->context)->toBe([
            'probe_id' => 'phpunit-probe',
            'operation' => 'test',
        ]);
});

it('does not swallow listener failures when the PHPUnit sink is quiet', function () {
    Log::listen(function (MessageLogged $record): void {
        if ($record->message === 'phpunit.listener.probe') {
            throw new RuntimeException('Test listener failure.');
        }
    });

    expect(fn () => Log::error('phpunit.listener.probe'))
        ->toThrow(RuntimeException::class, 'Test listener failure.');
});
