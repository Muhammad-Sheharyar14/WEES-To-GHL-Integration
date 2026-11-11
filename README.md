# WESS — GoHighLevel Integration

A dedicated, high-performance two-way synchronization engine connecting **WESS** with **GoHighLevel (GHL)**.

---

## Key Features

1. **Two-Way Appointment & Calendar Synchronization**:
   - Bi-directional scheduling between GoHighLevel calendars and WESS.
   - **Staff & Therapist Calendar Priority**: Automatically resolves and synchronizes appointments directly to each therapist's personal calendar based on staff employee code and name.
   - **Double-Booking Prevention**: When an appointment is scheduled on a therapist's personal calendar, GoHighLevel automatically blocks their availability across all booking funnels.
   - **Contactless Slot Handling**: Open or blocked slots without customer contact details route directly to GoHighLevel block-slots, preventing HTTP 400 validation errors.

2. **Full Customer Profile Synchronization**:
   - Automatically synchronizes comprehensive customer profiles whenever an appointment is booked or synced:
     - **First & Last Name**: Preserves separate first and last names directly from WESS.
     - **Phone Number & Email**: Formats international mobile numbers and contact emails.
     - **WESS Customer Code**: Maps member ID directly to custom field (`WESS Code`).
     - **Salutation**: Normalizes honorifics (`Mr`, `Ms`, `Mrs`, `Dr`).
     - **Gender**: Standardizes to `Male` or `Female`.
     - **Birthday Month**: Converts month numbers or dates to full month names (e.g. `October`).
     - **NRIC**: Preserves IC / identity numbers where available.
   - **Read-Only Profile Enrichment**: Automatically retrieves missing profile attributes directly from WESS using read-only `GET` endpoints with 24-hour caching.
   - **Additive Tagging**: Attaches tags additively without overwriting existing contact tags.

3. **Loop Prevention & Debounce Architecture**:
   - Eliminates cancellation feedback loops using a high-precision 120-second debounce lock and database state pre-validation.
   - Prevents duplicate webhook triggers and redundant API requests through multi-attribute hash tracking.

4. **GoHighLevel OAuth 2.0 & Auto-Refresh Token Service**:
   - Supports both Agency (`Company`) and Sub-Account (`Location`) authorization.
   - Automatically provisions and auto-refreshes sub-account tokens via GoHighLevel's `/oauth/locationToken` endpoint.
   - Resilient API handling supporting both HTTP `200` and `201` responses.

5. **Embedded Configuration Dashboard**:
   - Embedded directly within GoHighLevel sub-account settings.
   - Location Master Sync Switch (instant ON/OFF kill-switch).
   - Real-time WESS connection tester with live branch loading.

---

## Core Endpoints

* **OAuth Connect**: `GET /connect` or `GET /oauth/ghl`
* **OAuth Callback**: `GET /callback`
* **Custom Settings Page**: `GET /custom-page`
* **Custom Page Init & Credentials**: `POST /api/custom-page/get`
* **Save WESS Credentials**: `POST /api/custom-page/save`
* **Test WESS Connection**: `POST /api/custom-page/test`
* **Master Sync Toggle**: `POST /api/custom-page/toggle-sync`
* **GoHighLevel Webhook Listener**: `POST /api/webhook`
* **Activity & Audit Logs**: `GET /logs`

---

## Local Setup & Development

```bash
# Configure Environment
cp .env.example .env
php artisan key:generate

# Run Database Migrations
php artisan migrate

# Run Automated Test Suite
php artisan test

# Start Local Server
php artisan serve
```
