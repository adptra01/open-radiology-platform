<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentikasi machine-to-machine untuk endpoint DICOM adapter.
 * Header `X-API-Key` dibandingkan (timing-safe) dengan config('dicom.api_key').
 */
class DicomApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('dicom.api_key');
        $provided = (string) $request->header('X-API-Key', '');

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}