<?php

namespace App\Http\Middleware;

use Illuminate\Support\Facades\Log;
use Closure;
use Illuminate\Http\Request;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user() && $request->user()->tokenCan('admin')) {
            Log::info('Admin access granted.');
            return $next($request);
        }

        Log::warning('Unauthorized access attempt.', [
            'user' => $request->user(),
        ]);

        return response()->json(['message' => 'Unauthorized'], 403);
    }
}
