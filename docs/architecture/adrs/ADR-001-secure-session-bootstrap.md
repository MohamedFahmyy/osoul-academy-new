# ADR-001: Secure Session Bootstrap

## Status
Accepted

## Context
When a student initiates a secured exam, the application must transition the student's browser session into a secure desktop client environment. Historically, platforms passed long-lived personal access tokens (PAT) like Laravel Sanctum tokens to the desktop application to authenticate it. This approach introduces risk if the token is intercepted, as PATs have wide API access and long lifetimes.

## Decision
We establish a dedicated "Secure Session Bootstrap" contract. Instead of sharing user credentials or Sanctum tokens, the server creates a transient, single-use, scope-restricted Session record associated with the specific User and Exam. The desktop application establishes security context purely by referring to this unique `asap_session_id`.

## Identity Contract Flow
```
Student (Browser)
   ↓ (Click Take Exam)
ExamAttempt Created
   ↓
ASAP Session Init (Status: CREATED)
   ↓ (Browser deep-links to asap://)
Bootstrap Handshake (One-time Ephemeral Token)
   ↓
Session READY / RUNNING (HMAC Heartbeats Start)
   ↓
Exam Interface Revealed in Client
   ↓ (If violations or exit occur)
Termination / Complete
```

## Consequences
- Long-lived user authentication tokens are never exposed to the client operating system or protocol handler.
- Session authorization is strictly bounded to the specific exam attempt.
- Compromising the session ID does not grant access to the user's account or other exams.
