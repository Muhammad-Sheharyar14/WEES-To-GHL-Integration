# WESS Salon & Spa — GoHighLevel Integration

A dedicated Laravel integration connecting **WESS Salon & Spa Management Software** (by Refine Solutions) with **GoHighLevel (GHL)** for Ample Life.

---

## Key Features

1. **GHL OAuth 2.0 & Auto-Refresh Token Service**:
   - Handles Agency (`Company`) and Sub-Account (`Location`) authorization.
   - For Agency installations, securely stores agency credentials without unnecessary sub-account redirects.
   - Automatically generates and auto-refreshes sub-account tokens via GHL's `/oauth/locationToken` endpoint when opened inside sub-accounts.

2. **Custom Embedded Settings Page (Light Theme)**:
   - Embedded inside GoHighLevel sub-account settings.
   - Light theme, zero scrollbars, standard readable fonts.
   - Location Master Sync Switch (instant ON/OFF kill-switch).
   - Enter WESS Base URL & Bearer Token with live connection testing and automatic branch loading.

3. **2-Way Calendar & Booking Sync**:
   - Syncs appointments between GoHighLevel calendars and WESS.
   - Slot availability masking to protect customer and staff privacy.
   - Automatic customer lookup by phone number and new customer creation with `WESS Customer Code` mapping back to GHL contact fields.

4. **HTTP Status Code Resilience**:
   - All GHL API responses validate against both HTTP `200` and `201` status codes.

---

## Core Endpoints

* **OAuth Connect**: `GET /connect` or `GET /oauth/ghl`
* **OAuth Callback**: `GET /callback`
* **Custom App Settings Page**: `GET /custom-page`
* **Custom Page Init & Credentials**: `POST /api/custom-page/get`
* **Save WESS Credentials**: `POST /api/custom-page/save`
* **Test WESS Connection**: `POST /api/custom-page/test`
* **Master Sync Toggle**: `POST /api/custom-page/toggle-sync`
* **Marketplace Webhook**: `POST /webhook`
* **Log Viewer**: `GET /logs`

---

## Local Setup

```bash
# Configure Environment
cp .env.example .env
php artisan key:generate

# Run Migrations
php artisan migrate

# Run Tests
php artisan test

# Start Server
php artisan serve
```
