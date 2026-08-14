<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

use Illuminate\Support\Facades\Auth;
use Modules\Exam\Models\ExamAttempt;

class AsapE2EAutoLogin
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // For E2E testing: auto-login the attempt user if a valid signed URL is intercepted
        if (app()->environment('local', 'testing') && $request->has('bootstrapToken') && $request->has('attempt') && $request->has('signature')) {
            $token = $request->input('bootstrapToken');
            $attemptId = $request->input('attempt');
            $signature = $request->input('signature');
            
            $version = config('asap.active_key_version', 2);
            $activeKey = config("asap.keys.{$version}");
            $expectedSig = hash_hmac('sha256', $token . '|' . $attemptId, $activeKey);
            
            if (hash_equals($expectedSig, $signature)) {
                $attempt = ExamAttempt::find($attemptId);
                if ($attempt) {
                    $attemptUser = \App\Models\User::find($attempt->user_id);
                    if ($attemptUser) {
                        Auth::login($attemptUser);
                    }
                }
            }
        }

        return $next($request);
    }
}
