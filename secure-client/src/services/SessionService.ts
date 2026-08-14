export class SessionService {
    private static instance: SessionService;
    private token: string | null = null;
    private sessionId: string | null = null;
    private sessionKeyId: string | null = null;
    private sessionKey: string | null = null;
    private backendUrl: string = 'http://127.0.0.1:8001';
    
    // Additional launch context properties
    private attemptId: string | number | null = null;
    private targetUrl: string | null = null;
    private bootstrappedAt: string | null = null;

    private constructor() {}

    public static getInstance(): SessionService {
        if (!SessionService.instance) {
            SessionService.instance = new SessionService();
        }
        return SessionService.instance;
    }

    /**
     * Clear all session parameters (for logout/cleanup/termination/expiration).
     */
    public clear(): void {
        this.token = null;
        this.sessionId = null;
        this.sessionKeyId = null;
        this.sessionKey = null;
        this.attemptId = null;
        this.targetUrl = null;
        this.bootstrappedAt = null;
        console.log('Session state cleared.');
    }

    /**
     * Bootstrap the session by performing a secure handshake to fetch session keys.
     */
    public async bootstrap(
        token: string,
        attemptId: string | number,
        signature: string,
        backendUrl?: string
    ): Promise<boolean> {
        // If already bootstrapped for this attempt, return true
        if (this.attemptId === attemptId && this.sessionKey) {
            return true;
        }

        this.token = token;
        this.attemptId = attemptId;
        if (backendUrl) {
            this.backendUrl = backendUrl;
        }

        try {
            const response = await fetch(`${this.backendUrl}/api/v1/asap/session/handshake`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    bootstrap_token: this.token,
                    attempt_id: this.attemptId,
                    signature: signature
                }),
            });

            if (!response.ok) {
                console.error('Handshake failed:', response.statusText);
                return false;
            }

            const data = (await response.json()) as any;
            if (data.status === 'success') {
                this.sessionId = data.session_id;
                this.sessionKeyId = data.session_key_id;
                this.sessionKey = data.session_key; // Saved strictly in main process memory
                this.targetUrl = data.target_url;
                this.bootstrappedAt = new Date().toISOString();
                return true;
            }
        } catch (error) {
            console.error('Error during handshake:', error);
        }

        return false;
    }

    /**
     * Verify if a session is currently active and matches the provided token and session ID.
     */
    public isSessionVerified(token: string, sessionId: string): boolean {
        return (
            this.token === token &&
            this.sessionId === sessionId &&
            this.sessionKey !== null &&
            this.targetUrl !== null
        );
    }

    public getToken(): string | null {
        return this.token;
    }

    public getSessionId(): string | null {
        return this.sessionId;
    }

    public getSessionKeyId(): string | null {
        return this.sessionKeyId;
    }

    public getSessionKey(): string | null {
        return this.sessionKey;
    }

    public getBackendUrl(): string {
        return this.backendUrl;
    }

    public getTargetUrl(): string | null {
        return this.targetUrl;
    }

    public getAttemptId(): string | number | null {
        return this.attemptId;
    }

    public getBootstrappedAt(): string | null {
        return this.bootstrappedAt;
    }
}
