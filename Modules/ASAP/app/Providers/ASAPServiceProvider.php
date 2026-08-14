<?php

namespace Modules\ASAP\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class ASAPServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'ASAP';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'asap';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    // protected array $commands = [];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    /**
     * Register services.
     */
    public function register(): void
    {
        parent::register();

        $this->app->bind(
            \Modules\ASAP\Repositories\DeviceRepositoryInterface::class,
            \Modules\ASAP\Repositories\EloquentDeviceRepository::class
        );
        $this->app->bind(
            \Modules\ASAP\Repositories\SessionRepositoryInterface::class,
            \Modules\ASAP\Repositories\EloquentSessionRepository::class
        );
        $this->app->bind(
            \Modules\ASAP\Services\PolicyEngineInterface::class,
            \Modules\ASAP\Services\DefaultPolicyEngine::class
        );
    }

    /**
     * Boot services.
     */
    public function boot(): void
    {
        parent::boot();

        $this->registerRateLimiters();
    }

    /**
     * Register composite rate limiters.
     */
    protected function registerRateLimiters(): void
    {
        \Illuminate\Support\Facades\RateLimiter::for('asap_session', function (\Illuminate\Http\Request $request) {
            $userId = $request->user()?->id ?: $request->ip();
            $deviceUuid = $request->input('device_uuid', '');
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by($userId . '_' . $deviceUuid);
        });

        \Illuminate\Support\Facades\RateLimiter::for('asap_event', function (\Illuminate\Http\Request $request) {
            $userId = $request->user()?->id ?: $request->ip();
            $sessionId = $request->input('session_id', '');
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(60)->by($userId . '_' . $sessionId);
        });

        \Illuminate\Support\Facades\RateLimiter::for('asap_heartbeat', function (\Illuminate\Http\Request $request) {
            $userId = $request->user()?->id ?: $request->ip();
            $sessionId = $request->input('session_id', '');
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(12)->by($userId . '_' . $sessionId);
        });
    }
}
