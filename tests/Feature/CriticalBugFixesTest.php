<?php

namespace Tests\Feature;

use App\Models\GhlToken;
use App\Models\SyncedAppointment;
use App\Models\WessConfig;
use App\Models\WebhookLog;
use App\Services\Ghl\GhlCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CriticalBugFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        GhlToken::create([
            'location_id'   => 'loc_fix_test',
            'access_token'  => 'mock_access_token',
            'refresh_token' => 'mock_refresh_token',
            'user_type'     => 'Location',
            'expires_at'    => now()->addHours(2),
            'is_active'     => true,
        ]);

        WessConfig::create([
            'location_id'        => 'loc_fix_test',
            'api_token'          => 'wess_test_token',
            'base_url'           => 'https://api.prelive.wessconnect.net/api/v1/online',
            'branch_id'          => 1,
            'calendar_id'        => 'cal_default',
            'calendar_user_id'   => 'usr_default',
            'is_sync_enabled'    => true,
            'is_connected'       => true,
        ]);
    }

    /**
     * Test 1: Cancellation webhook with active debounce lock is SKIPPED without calling WESS
     */
    public function test_cancellation_webhook_with_debounce_lock_is_skipped(): void
    {
        $wessCancelCalled = false;
        Http::fake([
            'https://api.prelive.wessconnect.net/*' => function () use (&$wessCancelCalled) {
                $wessCancelCalled = true;
                return Http::response(['status' => 'cancelled'], 200);
            },
        ]);

        // Put active debounce lock in cache as set by syncAppointmentToGhl or cancelAppointmentInGhl
        Cache::put("wess_origin_appt_ghl_test_appt_123", true, now()->addMinutes(2));

        SyncedAppointment::create([
            'location_id'         => 'loc_fix_test',
            'wess_appointment_id' => '99001',
            'ghl_appointment_id'  => 'ghl_test_appt_123',
            'status'              => 'confirmed',
        ]);

        $res = $this->postJson('/api/webhook', [
            'type'        => 'AppointmentUpdate',
            'locationId'  => 'loc_fix_test',
            'appointment' => [
                'id'                => 'ghl_test_appt_123',
                'appointmentStatus' => 'cancelled',
            ],
        ]);

        $res->assertStatus(200);
        $this->assertFalse($wessCancelCalled, 'WESS cancel API must NEVER be called when debounce lock is active.');

        $log = WebhookLog::where('location_id', 'loc_fix_test')->latest()->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('skipped', $log->response_body);
    }

    /**
     * Test 2: Cancellation webhook when appointment is already marked cancelled in DB is SKIPPED
     */
    public function test_cancellation_webhook_already_marked_cancelled_is_skipped(): void
    {
        $wessCancelCalled = false;
        Http::fake([
            'https://api.prelive.wessconnect.net/*' => function () use (&$wessCancelCalled) {
                $wessCancelCalled = true;
                return Http::response(['status' => 'cancelled'], 200);
            },
        ]);

        SyncedAppointment::create([
            'location_id'         => 'loc_fix_test',
            'wess_appointment_id' => '99002',
            'ghl_appointment_id'  => 'ghl_test_appt_already_cancelled',
            'status'              => 'cancelled', // Already cancelled
        ]);

        $res = $this->postJson('/api/webhook', [
            'type'        => 'AppointmentDelete',
            'locationId'  => 'loc_fix_test',
            'appointment' => [
                'id'                => 'ghl_test_appt_already_cancelled',
                'appointmentStatus' => 'cancelled',
            ],
        ]);

        $res->assertStatus(200);
        $this->assertFalse($wessCancelCalled, 'WESS cancel API must NEVER be called when appointment is already cancelled in database.');
    }

    /**
     * Test 3: getOrCreateContact does NOT send 'tags' in contacts/upsert payload (preserves staff tags)
     */
    public function test_get_or_create_contact_does_not_pass_tags_in_upsert_payload(): void
    {
        $capturedUpsertPayload = null;
        $tagsEndpointCalled    = false;

        Http::fake([
            'https://services.leadconnectorhq.com/contacts/upsert' => function ($request) use (&$capturedUpsertPayload) {
                $capturedUpsertPayload = $request->data();
                return Http::response(['contact' => ['id' => 'cnt_safe_tags']], 200);
            },
            'https://services.leadconnectorhq.com/contacts/cnt_safe_tags/tags' => function ($request) use (&$tagsEndpointCalled) {
                $tagsEndpointCalled = true;
                return Http::response(['tags' => ['WESS']], 200);
            },
        ]);

        $service = app(GhlCalendarService::class);
        $contactId = $service->getOrCreateContact('loc_fix_test', 'Jane Test Doe', '+6591112222', 'jane@example.com');

        $this->assertEquals('cnt_safe_tags', $contactId);
        $this->assertNotNull($capturedUpsertPayload);
        $this->assertArrayNotHasKey('tags', $capturedUpsertPayload, 'contacts/upsert must NOT contain tags array to avoid overwriting existing customer tags.');
        $this->assertTrue($tagsEndpointCalled, 'addContactTags must be called additively after upsert.');
    }

    /**
     * Test 4: WESS appointment with no customer routes directly to block-slots (no 400 Bad Request)
     */
    public function test_sync_appointment_without_contact_routes_directly_to_block_slots(): void
    {
        $appointmentsEndpointCalled = false;
        $blockSlotsEndpointCalled   = false;

        Http::fake([
            'https://services.leadconnectorhq.com/calendars/events/appointments*' => function () use (&$appointmentsEndpointCalled) {
                $appointmentsEndpointCalled = true;
                return Http::response(['statusCode' => 400, 'message' => 'Appointment ContactId must be provided'], 400);
            },
            'https://services.leadconnectorhq.com/calendars/events/block-slots' => function () use (&$blockSlotsEndpointCalled) {
                $blockSlotsEndpointCalled = true;
                return Http::response(['id' => 'block_slot_created_123'], 201);
            },
            'https://services.leadconnectorhq.com/calendars/*' => Http::response(['calendars' => []], 200),
            'https://services.leadconnectorhq.com/users/*' => Http::response(['users' => []], 200),
        ]);

        $service = app(GhlCalendarService::class);
        $wessAppointmentWithoutCustomer = [
            'id'              => 28299386,
            'date'            => '2026-11-12T10:00:00Z',
            'status'          => 'confirmed',
            'processing_time' => 60,
            'service_name'    => 'Open Slot',
            'customer'        => null, // Customer is null!
            'employees'       => [],
        ];

        $res = $service->syncAppointmentToGhl('loc_fix_test', $wessAppointmentWithoutCustomer, true, 'cal_test_1', 'usr_test_1');

        $this->assertFalse($appointmentsEndpointCalled, 'Appointments endpoint must NOT be called when contactId is missing.');
        $this->assertTrue($blockSlotsEndpointCalled, 'Block-slots endpoint must be called directly when contactId is missing.');
        $this->assertEquals('block_slot_created_123', $res['id']);
    }

    /**
     * Test 5: getTherapistCalendarMap prioritizes personal calendar over service_booking calendar
     */
    public function test_therapist_calendar_mapping_prefers_personal_calendar_over_service_booking(): void
    {
        Http::fake([
            'https://services.leadconnectorhq.com/calendars*' => Http::response([
                'calendars' => [
                    [
                        'id'           => 'cal_service_bust_me_up',
                        'name'         => 'Bust Me Up',
                        'calendarType' => 'service_booking',
                        'user_id'      => 'usr_aarin',
                    ],
                    [
                        'id'           => 'cal_aarin_personal',
                        'name'         => "Aarin EP01009's Personal Calendar",
                        'calendarType' => 'personal',
                        'user_id'      => 'usr_aarin',
                    ],
                ],
            ], 200),
            'https://services.leadconnectorhq.com/users*' => Http::response([
                'users' => [
                    [
                        'id'        => 'usr_aarin',
                        'name'      => 'Aarin EP01009',
                        'firstName' => 'Aarin',
                        'lastName'  => 'EP01009',
                    ],
                ],
            ], 200),
        ]);

        $service = app(GhlCalendarService::class);
        $map = $service->getTherapistCalendarMap('loc_fix_test', null, true);

        $this->assertEquals('cal_aarin_personal', $map['by_user_id']['usr_aarin']['calendar_id']);
        $this->assertEquals('cal_aarin_personal', $map['by_code']['EP01009']['calendar_id']);
        $this->assertEquals('cal_aarin_personal', $map['by_name']['aarin']['calendar_id']);
    }

    /**
     * Test 6: syncAppointmentToGhl synchronizes full customer profile to MiniChat:
     * - First & Last Name
     * - Phone Number & Email
     * - WESS Customer Code
     * - Salutation (Mr/Ms)
     * - Gender (Male/Female)
     * - Birthday Month
     */
    public function test_sync_appointment_updates_full_customer_profile_fields(): void
    {
        $contactUpsertPayload = null;
        $contactPutPayload    = null;

        Http::fake([
            'https://services.leadconnectorhq.com/contacts/upsert' => function ($request) use (&$contactUpsertPayload) {
                $contactUpsertPayload = $request->data();
                return Http::response(['contact' => ['id' => 'cnt_profile_test_999']], 200);
            },
            'https://services.leadconnectorhq.com/contacts/cnt_profile_test_999/tags' => Http::response([
                'tags' => ['WESS']
            ], 200),
            'https://services.leadconnectorhq.com/locations/loc_fix_test/customFields' => Http::response([
                'customFields' => [
                    ['id' => 'cf_wess_code_1', 'name' => 'WESS Code'],
                    ['id' => 'cf_salutation_2', 'name' => 'Salutation'],
                    ['id' => 'cf_gender_3', 'name' => 'Gender'],
                    ['id' => 'cf_bday_month_4', 'name' => 'Birthday Month'],
                    ['id' => 'cf_nric_5', 'name' => 'NRIC'],
                ]
            ], 200),
            'https://services.leadconnectorhq.com/contacts/cnt_profile_test_999' => function ($request) use (&$contactPutPayload) {
                if ($request->method() === 'PUT') {
                    $contactPutPayload = $request->data();
                }
                return Http::response(['contact' => ['id' => 'cnt_profile_test_999']], 200);
            },
            'https://services.leadconnectorhq.com/calendars/events/appointments*' => Http::response([
                'id' => 'ghl_appt_profile_test'
            ], 200),
            'https://services.leadconnectorhq.com/calendars/*' => Http::response(['calendars' => []], 200),
            'https://services.leadconnectorhq.com/users/*' => Http::response(['users' => []], 200),
        ]);

        $service = app(GhlCalendarService::class);
        $wessAppointment = [
            'id'              => 887766,
            'date'            => '2026-11-15T14:00:00Z',
            'status'          => 'confirmed',
            'processing_time' => 60,
            'service_name'    => 'Deep Tissue Massage',
            'customer'        => [
                'id'              => 3508759,
                'code'            => 'C004794',
                'salutation'      => 'MS',
                'first_name'      => 'Wui Yen',
                'last_name'       => 'Fong',
                'phone_mobile'    => '+6592354523',
                'email'           => 'Wuiyen@gmail.com',
                'customer_detail' => [
                    'gender'         => 'F',
                    'birthday_month' => 10,
                ],
            ],
            'employees'       => [],
        ];

        $service->syncAppointmentToGhl('loc_fix_test', $wessAppointment, true, 'cal_test_1', 'usr_test_1');

        // 1. Verify contacts/upsert payload received explicit firstName and lastName
        $this->assertNotNull($contactUpsertPayload);
        $this->assertEquals('Wui Yen', $contactUpsertPayload['firstName']);
        $this->assertEquals('Fong', $contactUpsertPayload['lastName']);
        $this->assertEquals('+6592354523', $contactUpsertPayload['phone']);
        $this->assertEquals('Wuiyen@gmail.com', $contactUpsertPayload['email']);

        // 2. Verify PUT /contacts/{id} updated all 6 fields (Core + Custom Fields)
        $this->assertNotNull($contactPutPayload, 'PUT /contacts/{contactId} must be called to update custom fields.');
        $this->assertEquals('Wui Yen', $contactPutPayload['firstName']);
        $this->assertEquals('Fong', $contactPutPayload['lastName']);
        $this->assertEquals('+6592354523', $contactPutPayload['phone']);
        $this->assertEquals('Wuiyen@gmail.com', $contactPutPayload['email']);

        // Verify custom fields payload
        $customFields = $contactPutPayload['customFields'] ?? [];
        $fieldMap = [];
        foreach ($customFields as $cf) {
            $fieldMap[$cf['id']] = $cf['field_value'] ?? $cf['value'];
        }

        $this->assertEquals('C004794', $fieldMap['cf_wess_code_1'] ?? null, 'WESS Code custom field must be set');
        $this->assertEquals('Ms', $fieldMap['cf_salutation_2'] ?? null, 'Salutation custom field must be normalized');
        $this->assertEquals('Female', $fieldMap['cf_gender_3'] ?? null, 'Gender custom field must be normalized');
        $this->assertEquals('October', $fieldMap['cf_bday_month_4'] ?? null, 'Birthday Month custom field must be formatted');
    }

    /**
     * Test 7: syncAppointmentToGhl enriches missing gender & birthday month via read-only GET to WESS
     */
    public function test_sync_appointment_enriches_customer_from_wess_if_missing_details(): void
    {
        $contactPutPayload = null;
        $wessCustomerLookupCalled = false;

        Http::fake([
            'https://api.prelive.wessconnect.net/api/v1/online/branches/1/customers/554433' => function ($request) use (&$wessCustomerLookupCalled) {
                $wessCustomerLookupCalled = true;
                return Http::response([
                    'data' => [
                        'id'              => 554433,
                        'code'            => 'C009988',
                        'salutation'      => 'MR',
                        'first_name'      => 'Daniel',
                        'last_name'       => 'Tan',
                        'customer_detail' => [
                            'gender'         => 'M',
                            'birthday_month' => 5,
                        ],
                    ]
                ], 200);
            },
            'https://services.leadconnectorhq.com/contacts/upsert' => Http::response([
                'contact' => ['id' => 'cnt_enriched_tan']
            ], 200),
            'https://services.leadconnectorhq.com/contacts/cnt_enriched_tan/tags' => Http::response([
                'tags' => ['WESS']
            ], 200),
            'https://services.leadconnectorhq.com/locations/loc_fix_test/customFields' => Http::response([
                'customFields' => [
                    ['id' => 'cf_wess_code_1', 'name' => 'WESS Code'],
                    ['id' => 'cf_salutation_2', 'name' => 'Salutation'],
                    ['id' => 'cf_gender_3', 'name' => 'Gender'],
                    ['id' => 'cf_bday_month_4', 'name' => 'Birthday Month'],
                ]
            ], 200),
            'https://services.leadconnectorhq.com/contacts/cnt_enriched_tan' => function ($request) use (&$contactPutPayload) {
                if ($request->method() === 'PUT') {
                    $contactPutPayload = $request->data();
                }
                return Http::response(['contact' => ['id' => 'cnt_enriched_tan']], 200);
            },
            'https://services.leadconnectorhq.com/calendars/events/appointments*' => Http::response([
                'id' => 'ghl_appt_enriched'
            ], 200),
            'https://services.leadconnectorhq.com/calendars/*' => Http::response(['calendars' => []], 200),
            'https://services.leadconnectorhq.com/users/*' => Http::response(['users' => []], 200),
        ]);

        $service = app(GhlCalendarService::class);
        // Customer in appointment payload only has id, name, phone, email (missing gender and birthday_month)
        $wessAppointment = [
            'id'              => 998877,
            'date'            => '2026-11-16T15:00:00Z',
            'status'          => 'confirmed',
            'processing_time' => 45,
            'service_name'    => 'Foot Reflexology',
            'customer'        => [
                'id'           => 554433,
                'first_name'   => 'Daniel',
                'last_name'    => 'Tan',
                'phone_mobile' => '+6598765432',
                'email'        => 'daniel@example.com',
            ],
            'employees'       => [],
        ];

        $service->syncAppointmentToGhl('loc_fix_test', $wessAppointment, true, 'cal_test_1', 'usr_test_1');

        $this->assertTrue($wessCustomerLookupCalled, 'WESS getCustomer (READ ONLY) must be called to enrich missing profile fields.');
        $this->assertNotNull($contactPutPayload);

        $customFields = $contactPutPayload['customFields'] ?? [];
        $fieldMap = [];
        foreach ($customFields as $cf) {
            $fieldMap[$cf['id']] = $cf['field_value'] ?? $cf['value'];
        }

        $this->assertEquals('C009988', $fieldMap['cf_wess_code_1'] ?? null);
        $this->assertEquals('Mr', $fieldMap['cf_salutation_2'] ?? null);
        $this->assertEquals('Male', $fieldMap['cf_gender_3'] ?? null);
        $this->assertEquals('May', $fieldMap['cf_bday_month_4'] ?? null);
    }
}
