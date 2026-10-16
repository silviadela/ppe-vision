<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSummarySyncTokenIsValid
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.summary_sync.token');
        $provided = (string) $request->bearerToken();

        abort_if($expected === '' || $provided === '' || ! hash_equals($expected, $provided), 401);

        return $next($request);
    }
}
