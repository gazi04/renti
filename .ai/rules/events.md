---
paths:
  - 'app/Events/**'
---

# Events

## Events must implement ShouldDispatchAfterCommit
Every App\Events class implements Illuminate\Contracts\Events\ShouldDispatchAfterCommit (enforced by tests/Arch/ArchTest.php). Their listeners are queued and email/notify people; fired inside DB::transaction (BookingService::create/move), a worker could otherwise read the pre-commit row (stale "moved" emails, waitlist missing the freed slot) or mail about a booking that rolled back. Outside a transaction they fire immediately. config/queue.php also sets after_commit => true on redis/database as a safety net. See docs/bug-report-2026-10-05.md #03.
