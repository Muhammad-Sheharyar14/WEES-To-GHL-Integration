<?php

namespace Tests\Feature;

use App\Models\GhlToken;
use App\Models\SyncedAppointment;
use App\Models\WessConfig;
use App\Models\WebhookLog;
use App\Services\Ghl\GhlCalendarService;
use App\Services\Wess\WessSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TherapistCalendarSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        GhlToken::create([
            'location_id'   => 'loc_therapist_test',
            'access_token'  => 'mock_token',
            'refresh_token' => 'mock_refresh',
            'user_type'     => 'Location',
            'expires_at'    => now()->addHours(2),
            'is_active'     => true,
        ]);

        WessConfig::create([
            'location_id'        => 'loc_therapist_test',
            'api_token'          => 'wess_mock_token',
            'base_url'           => 'https://api.wessconnect.com/api/v1/online',
            'branch_id'          => 1,
            'calendar_id'        => 'cal_default_fallback',
            'calendar_user_id'   => 'user_default_fallback',
            'is_sync_enabled'    => true,
            'is_connected'       => true,
        ]);
    }

    public function test_dynamic_therapist_calendar_routing_and_status_mapping(): void
    {
        Http::fake([
            // Mock WESS appointments (Aarin with Open status, Cherry with Cancelled status)
            'https://api.wessconnect.com/api/v1/online/branches/1/appointments*' => Http::response([
                'data' => [
                    [
                        'id'              => 5001,
                        'date'            => '2026-11-20T10:00:00Z',
                        'status'          => 'Open',
                        'processing_time' => 60,
                        'service_name'    => 'Deep Tissue Massage',
                        'customer'        => [
                            'first_name'   => 'Alice',
                            'last_name'    => 'Smith',
                            'phone_mobile' => '+6591234567',
                        ],
                        'employees'       => [
                            [
                                'id'         => 10290,
                                'first_name' => 'Aarin',
                                'last_name'  => '',
                            ],
                        ],
                    ],
                    [
                        'id'              => 5002,
                        'date'            => '2026-11-21T14:00:00Z',
                        'status'          => 'Confirmed',
                        'processing_time' => 45,
                        'service_name'    => 'Facial Glow',
                        'customer'        => [
                            'first_name'   => 'Bob',
                            'last_name'    => 'Lee',
                            'phone_mobile' => '+6597654321',
                        ],
                        'employees'       => [
                            [
                                'id'         => 37390,
                                'first_name' => 'Cherry',
                                'last_name'  => 'Nguyen',
                            ],
                        ],
                    ],
                ],
                'meta' => [
                    'pagination' => ['total' => 2, 'count' => 2, 'per_page' => 100, 'current_page' => 1, 'total_pages' => 1],
                ],
            ], 200),

            // Mock Contact creation
            'https://services.leadconnectorhq.com/contacts/upsert' => Http::response([
                'contact' => ['id' => 'contact_test_id'],
            ], 200),

            // Specific appointment booking endpoint (MUST be listed before calendars/* wildcard)
            'https://services.leadconnectorhq.com/calendars/events/appointments*' => function ($request) {
                $body = $request->data();
                return Http::response([
                    'id'                => 'ghl_appt_' . rand(1000, 9999),
                    'calendarId'        => $body['calendarId'] ?? 'cal_default',
                    'appointmentStatus' => $body['appointmentStatus'] ?? 'confirmed',
                ], 200);
            },

            // Mock GHL users endpoint
            'https://services.leadconnectorhq.com/users/*' => Http::response([
                'users' => [
                    [
                        'id'        => 'usr_aarin_999',
                        'name'      => 'Aarin EP01009',
                        'firstName' => 'Aarin',
                        'lastName'  => 'EP01009',
                    ],
                    [
                        'id'        => 'usr_cherry_888',
                        'name'      => 'Cherry EP01025',
                        'firstName' => 'Cherry',
                        'lastName'  => 'EP01025',
                    ],
                ],
            ], 200),

            // Mock GHL calendars list endpoint
            'https://services.leadconnectorhq.com/calendars/*' => Http::response([
                'calendars' => [
                    [
                        'id'      => 'cal_aarin_personal',
                        'name'    => 'Aarin EP01009 Personal',
                        'user_id' => 'usr_aarin_999',
                    ],
                    [
                        'id'      => 'cal_cherry_personal',
                        'name'    => 'Cherry EP01025 Personal',
                        'user_id' => 'usr_cherry_888',
                    ],
                ],
            ], 200),
        ]);

        $service = app(WessSyncService::class);
        $results = $service->syncAllLocations('loc_therapist_test');

        $this->assertEquals(2, $results['loc_therapist_test']['synced_count']);

        // Assert Appointment 5001 routed to Aarin's personal calendar
        $this->assertDatabaseHas('synced_appointments', [
            'location_id'         => 'loc_therapist_test',
            'wess_appointment_id' => '5001',
            'calendar_id'         => 'cal_aarin_personal',
            'assigned_user_id'    => 'usr_aarin_999',
            'status'              => 'Open',
        ]);

        // Assert Appointment 5002 routed to Cherry's personal calendar
        $this->assertDatabaseHas('synced_appointments', [
            'location_id'         => 'loc_therapist_test',
            'wess_appointment_id' => '5002',
            'calendar_id'         => 'cal_cherry_personal',
            'assigned_user_id'    => 'usr_cherry_888',
            'status'              => 'Confirmed',
        ]);
    }

    public function test_reverse_sync_webhook_assigns_wess_employee_id(): void
    {
        $capturedBookingPayload = null;

        Http::fake([
            // Mock GHL users & calendars for reverse sync therapist resolution
            'https://services.leadconnectorhq.com/users/*' => Http::response([
                'users' => [
                    [
                        'id'        => 'usr_aarin_999',
                        'name'      => 'Aarin EP01009',
                        'firstName' => 'Aarin',
                        'lastName'  => 'EP01009',
                    ],
                ],
            ], 200),
            'https://services.leadconnectorhq.com/calendars/*' => Http::response([
                'calendars' => [
                    [
                        'id'      => 'cal_aarin_personal',
                        'name'    => 'Aarin EP01009 Personal',
                        'user_id' => 'usr_aarin_999',
                    ],
                ],
            ], 200),

            // Mock WESS branch employees
            'https://api.wessconnect.com/api/v1/online/branches/1/appointments/employees' => Http::response([
                'data' => [
                    [
                        'id'         => 10290,
                        'first_name' => 'Aarin',
                        'last_name'  => '',
                    ],
                ],
            ], 200),

            // Mock WESS customer lookup by phone number
            'https://api.wessconnect.com/api/v1/online/customers/lookup/phone-number/*' => Http::response([
                [
                    'id'         => 8881,
                    'first_name' => 'Test',
                    'last_name'  => 'Customer',
                ],
            ], 200),

            // Mock WESS services
            'https://api.wessconnect.com/api/v1/online/branches/1/appointments/services' => Http::response([
                'data' => [
                    [
                        'id'   => 329401,
                        'code' => 'S01007',
                        'name' => 'MC Generic Service',
                    ],
                ],
            ], 200),

            // Mock WESS booking creation and capture payload
            'https://api.wessconnect.com/api/v1/online/branches/1/appointments' => function ($request) use (&$capturedBookingPayload) {
                $capturedBookingPayload = $request->data();
                return Http::response([
                    'data' => [
                        'id'     => 77701,
                        'status' => 'Request',
                    ],
                ], 200);
            },
        ]);

        $response = $this->postJson('/api/webhook', [
            'type'           => 'AppointmentCreate',
            'locationId'     => 'loc_therapist_test',
            'appointment'    => [
                'id'             => 'ghl_appt_test_77',
                'calendarId'     => 'cal_aarin_personal',
                'assignedUserId' => 'usr_aarin_999',
                'startTime'      => '2026-11-25 10:00:00',
                'phone'          => '+6591234567',
                'firstName'      => 'Test',
                'lastName'       => 'Customer',
            ],
        ]);

        $response->assertStatus(200);

        // Verify WESS received employee_id: 10290 dynamically mapped from usr_aarin_999
        $this->assertNotNull($capturedBookingPayload);
        $this->assertEquals(10290, $capturedBookingPayload['employee_id']);
        $this->assertEquals(8881, $capturedBookingPayload['customer_id']);

        // Verify synced_appointments database record created
        $this->assertDatabaseHas('synced_appointments', [
            'location_id'         => 'loc_therapist_test',
            'wess_appointment_id' => '77701',
            'ghl_appointment_id'  => 'ghl_appt_test_77',
            'assigned_user_id'    => 'usr_aarin_999',
            'calendar_id'         => 'cal_aarin_personal',
        ]);
    }
}
