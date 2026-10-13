<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Assigns server-owned correlation without retaining request data in worker context. */
class RequestLogContext
{
    /**
     * Shares one new correlation UUID and resets request-owned channel context on unwind.
     *
     * Only pre-request shared metadata is restored, not arbitrary channel-only context.
     * Incoming correlation headers are not authority and are never reused.
     *
     * @param  Request  $request  Current request passed through the middleware pipeline.
     * @param  Closure(Request): Response  $next  Remaining middleware pipeline.
     * @return Response Unchanged downstream response.
     *
     * @throws Throwable Propagates downstream failures without reporting them twice.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid7();
        $logs = app('log');
        $previous = $logs->sharedContext();
        $logs->shareContext(['request_id' => $requestId]);

        try {
            return $next($request);
        } finally {
            $logs->withoutContext()->flushSharedContext()->shareContext($previous);
        }
    }
}
