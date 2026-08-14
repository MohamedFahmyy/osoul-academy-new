<?php

namespace Modules\ASAP\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\ASAP\Models\Policy;
use Modules\ASAP\Models\PolicyRule;
use Modules\ASAP\Enums\PolicyAction;

class ASAPDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Seed the default Policy
        $policy = Policy::create([
            'name' => 'Default ASAP Security Policy',
            'warning_threshold' => 40.00,
            'pause_threshold' => 60.00,
            'terminate_threshold' => 85.00,
            'is_default' => true,
        ]);

        // 2. Seed Default Rules
        $rules = [
            [
                'event_code' => 'WINDOW_UNFOCUS',
                'weight' => 10.00,
                'cooldown_window' => 30,
                'action' => PolicyAction::WARN,
            ],
            [
                'event_code' => 'FULLSCREEN_EXIT',
                'weight' => 20.00,
                'cooldown_window' => 30,
                'action' => PolicyAction::WARN,
            ],
            [
                'event_code' => 'DISPLAY_ADDED',
                'weight' => 30.00,
                'cooldown_window' => 0,
                'action' => PolicyAction::PAUSE,
            ],
            [
                'event_code' => 'PROCESS_BLACKLIST_DETECTED',
                'weight' => 60.00,
                'cooldown_window' => 0,
                'action' => PolicyAction::TERMINATE,
            ],
            [
                'event_code' => 'HEARTBEAT_TIMEOUT',
                'weight' => 40.00,
                'cooldown_window' => 0,
                'action' => PolicyAction::PAUSE,
            ],
            [
                'event_code' => 'DEVTOOLS_DETECTED',
                'weight' => 50.00,
                'cooldown_window' => 0,
                'action' => PolicyAction::PAUSE,
            ],
            [
                'event_code' => 'VM_DETECTED',
                'weight' => 15.00,
                'cooldown_window' => 0,
                'action' => PolicyAction::WARN,
            ]
        ];

        foreach ($rules as $rule) {
            PolicyRule::create(array_merge($rule, [
                'policy_id' => $policy->id,
            ]));
        }
    }
}
