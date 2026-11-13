# Project Rules: Ample-WESS-Integration

## CRITICAL SAFETY RULE: STRICT READ-ONLY ACCESS TO LIVE WESS

### 1. Absolute Prohibition on Live Creation & Mutation
- **NEVER EVER attempt to create, write, modify, update, cancel, or delete anything in the Live WESS account (`api.wessconnect.com`).**
- You are strictly forbidden from creating:
  - New appointments or bookings in Live WESS
  - New customers or contact profiles in Live WESS
  - Any transactions, services, or blocks in Live WESS
- **ZERO MUTATION REQUESTS**: Do NOT execute any `POST`, `PUT`, `PATCH`, or `DELETE` HTTP requests against `https://api.wessconnect.com` or any production WESS endpoints.

### 2. Permitted Operations: Read-Only Only
- You are **ONLY** permitted to read (HTTP `GET` requests only) live WESS data for diagnostics and synchronization:
  - Reading/fetching appointments (`GET .../appointments`)
  - Reading/fetching customer details (`GET .../customers/{id}`)
  - Looking up customers by phone number (`GET .../customers/lookup/phone-number/...`)
  - Reading services, staff/employees, and available time slots (`GET ...`)

### 3. Testing & Creation Guidelines
- Any creation or write tests must use:
  1. Unit tests with mocked HTTP responses (`Http::fake()`), OR
  2. The dedicated Prelive/Sandbox environment (`https://api.prelive.wessconnect.net`).
- If any script, command, or request attempts to create or mutate anything in Live WESS, **STOP immediately and abort the action**.

