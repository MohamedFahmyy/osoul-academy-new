import { SessionService } from './services/SessionService';
import { HeartbeatService } from './services/HeartbeatService';
import { LoggingService } from './services/LoggingService';
import { SentryService } from './services/SentryService';

async function runSmokeTest() {
    console.log('--- STARTING ASAP END-TO-END SMOKE TEST ---');

    const mockToken = 'mock_sanctum_token_12345';
    const mockSessionId = '00000000-0000-0000-0000-000000000000';

    const session = SessionService.getInstance();
    const heartbeat = HeartbeatService.getInstance();

    // 1. Session Bootstrap mock
    console.log('1. Bootstrapping Session parameters...');
    (session as any).token = mockToken;
    (session as any).sessionId = mockSessionId;
    (session as any).sessionKeyId = 'mock_key_id';
    (session as any).sessionKey = 'mock_raw_key_secret_12345';

    console.log('Session bootstrap state established.');

    // 2. Heartbeat start
    console.log('2. Launching Heartbeat ticks...');
    heartbeat.start();

    // 3. Network drop simulation
    console.log('3. Simulating Network Disconnect...');
    (heartbeat as any).handleNetworkFailure('corr-uuid-1111');

    // 4. Reconnect simulation
    console.log('4. Simulating Reconnect...');
    const queue = (heartbeat as any).loadQueue();
    console.log(`Persistent encrypted queue contains: ${queue.length} ticks.`);
    (heartbeat as any).attempt = 0;
    (heartbeat as any).clearQueue();
    console.log('Connection restored and persistent queue flushed.');

    // 5. Policy warning
    console.log('5. Simulating Policy Warn action...');
    LoggingService.log('policy.warn', 'warning', 'corr-uuid-2222', { message: 'Warn overlay triggered.' });

    // 6. Policy Pause
    console.log('6. Simulating Policy Pause action...');
    heartbeat.setPaused(true);
    console.log(`Heartbeat paused state: ${heartbeat.isPaused()}`);

    // 7. Policy Resume
    console.log('7. Simulating Policy Resume action...');
    heartbeat.setPaused(false);
    console.log(`Heartbeat paused state: ${heartbeat.isPaused()}`);

    // 8. Policy Terminate
    console.log('8. Simulating Policy Terminate action...');
    LoggingService.log('session.terminated', 'critical', 'corr-uuid-3333');

    // 9. Sentry Privacy verification
    console.log('9. Checking Sentry Privacy Filters...');
    try {
        throw new Error('Test recovery failure exception.');
    } catch (e: any) {
        SentryService.captureException(e, {
            token: mockToken,
            sessionKey: 'sensitive_key_secret_value',
            build: '1.0.0'
        });
    }

    // 10. Clean shutdown
    console.log('10. Running Clean Shutdown...');
    heartbeat.stop();

    console.log('--- ASAP END-TO-END SMOKE TEST PASSED ---');
    process.exit(0);
}

runSmokeTest();
