---
name: backend-booking-race-audit
description: Diagnose and fix backend duplicate-booking race conditions in Doctrine/GraphQL booking systems.
---

# Backend Booking Race Audit

Use this skill when a booking system sometimes creates duplicate or overlapping bookings after a customer submits the same slot more than once, especially when the frontend can send concurrent GraphQL mutations.

## Workflow

1. Trace the write path from the API mutation to the command handler, entity, repository, and database migration.
2. Confirm whether availability is checked only during slot listing. If the create command does not re-check the slot immediately before saving, treat that as a race-condition risk.
3. Run the final duplicate check, overlap check, and insert in one transaction.
4. Serialize booking writes per stylist with an explicit lock row updated inside the transaction before the overlap check.
5. Use an explicit client-provided idempotency key for create-booking retries. Store a request hash with the key and reject key reuse with different booking data.
6. Carry a correlation ID alongside the idempotency key so the booking request can later be traced through logs, outbox events, webhooks, and downstream services.
7. Reject active overlapping bookings for the same stylist before inserting a new row.
8. Verify with either an automated test or two concurrent identical create calls. The expected result is one active booking row for the stylist/start pair.

## Checklist

- The create command checks the explicit idempotency key before overlap checks.
- The create command checks active overlap before saving.
- The duplicate check, overlap check, and insert are protected by a per-stylist transaction-level write lock.
- Idempotency is explicit; do not infer duplicate requests from customer name or contact details.
- Correlation ID is tracing metadata and is not part of the idempotency request hash.
- Rejected bookings do not block a slot.
- The API returns a user-safe error for a raced conflicting request.
