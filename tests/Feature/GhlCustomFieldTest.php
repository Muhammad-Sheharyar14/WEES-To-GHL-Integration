<?php

namespace Tests\Feature;

use App\Models\GhlToken;
use App\Services\Ghl\GhlCustomFieldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GhlCustomFieldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_reuses_existing_custom_fields_when_present()
    {
        $locationId = 'loc_test_existing_123';

        GhlToken::create([
            'location_id'   => $locationId,
            'access_token'  => 'fake_ghl_token',
            'refresh_token' => 'fake_refresh_token',
            'user_type'     => 'Location',
            'expires_at'    => now()->addDay(),
        ]);

        Http::fake([
            "https://services.leadconnectorhq.com/locations/{$locationId}/customFields" => Http::response([
                'customFields' => [
                    ['id' => 'field_wess_code_999', 'name' => 'WESS Code'],
                    ['id' => 'field_last_visit_888', 'name' => 'Last Visit'],
                    ['id' => 'field_salutation_777', 'name' => 'Salutation'],
                    ['id' => 'field_gender_666', 'name' => 'Gender'],
                    ['id' => 'field_nric_555', 'name' => 'NRIC'],
                    ['id' => 'field_contact_type_444', 'name' => 'Contact Type'],
                    ['id' => 'field_bday_month_333', 'name' => 'Birthday Month'],
                    ['id' => 'field_pref_name_222', 'name' => 'Preferred Name'],
                ]
            ], 200),
        ]);

        $service = app(GhlCustomFieldService::class);
        $fields = $service->getOrCreateContactCustomFields($locationId);

        $this->assertEquals('field_wess_code_999', $fields['wess_code_id']);
        $this->assertEquals('field_last_visit_888', $fields['last_visit_id']);
        $this->assertEquals('field_salutation_777', $fields['salutation_id']);
        $this->assertEquals('field_gender_666', $fields['gender_id']);
        $this->assertEquals('field_nric_555', $fields['nric_id']);
        $this->assertEquals('field_contact_type_444', $fields['contact_type_id']);
        $this->assertEquals('field_bday_month_333', $fields['birthday_month_id']);
        $this->assertEquals('field_pref_name_222', $fields['preferred_name_id']);

        // Verify no POST requests to create new fields were made
        Http::assertSentCount(1);
    }

    public function test_creates_custom_fields_when_they_do_not_exist()
    {
        $locationId = 'loc_test_create_456';

        GhlToken::create([
            'location_id'   => $locationId,
            'access_token'  => 'fake_ghl_token',
            'refresh_token' => 'fake_refresh_token',
            'user_type'     => 'Location',
            'expires_at'    => now()->addDay(),
        ]);

        Http::fake([
            // 1. Initial GET returns empty customFields
            "https://services.leadconnectorhq.com/locations/{$locationId}/customFields" => function ($request) {
                if ($request->method() === 'GET') {
                    return Http::response(['customFields' => []], 200);
                }
                // POST creates
                $data = $request->data();
                $name = $data['name'] ?? '';
                $clean = strtolower(str_replace(' ', '_', $name));
                return Http::response(['customField' => ['id' => "new_id_{$clean}", 'name' => $name]], 201);
            },
        ]);

        $service = app(GhlCustomFieldService::class);
        $fields = $service->getOrCreateContactCustomFields($locationId);

        $this->assertEquals('new_id_wess_code', $fields['wess_code_id']);
        $this->assertEquals('new_id_last_visit', $fields['last_visit_id']);
        $this->assertEquals('new_id_salutation', $fields['salutation_id']);
        $this->assertEquals('new_id_gender', $fields['gender_id']);
        $this->assertEquals('new_id_nric', $fields['nric_id']);
        $this->assertEquals('new_id_contact_type', $fields['contact_type_id']);
        $this->assertEquals('new_id_birthday_month', $fields['birthday_month_id']);
        $this->assertEquals('new_id_preferred_name', $fields['preferred_name_id']);

        // Next call should use cache and not hit HTTP
        $cachedFields = $service->getOrCreateContactCustomFields($locationId);
        $this->assertEquals('new_id_wess_code', $cachedFields['wess_code_id']);
        $this->assertEquals('new_id_last_visit', $cachedFields['last_visit_id']);
    }

    public function test_updates_contact_with_resolved_custom_field_ids()
    {
        $locationId = 'loc_test_update_789';
        $contactId  = 'contact_xyz_123';

        GhlToken::create([
            'location_id'   => $locationId,
            'access_token'  => 'fake_ghl_token',
            'refresh_token' => 'fake_refresh_token',
            'user_type'     => 'Location',
            'expires_at'    => now()->addDay(),
        ]);

        Http::fake([
            "https://services.leadconnectorhq.com/locations/{$locationId}/customFields" => Http::response([
                'customFields' => [
                    ['id' => 'cf_wess_111', 'name' => 'WESS Code'],
                    ['id' => 'cf_visit_222', 'name' => 'Last Visit'],
                    ['id' => 'cf_salut_333', 'name' => 'Salutation'],
                    ['id' => 'cf_gend_444', 'name' => 'Gender'],
                    ['id' => 'cf_nric_555', 'name' => 'NRIC'],
                    ['id' => 'cf_ctype_666', 'name' => 'Contact Type'],
                    ['id' => 'cf_bmonth_777', 'name' => 'Birthday Month'],
                    ['id' => 'cf_pname_888', 'name' => 'Preferred Name'],
                ]
            ], 200),
            "https://services.leadconnectorhq.com/contacts/{$contactId}" => Http::response([
                'succeded' => true,
                'contact'  => ['id' => $contactId]
            ], 200),
        ]);

        $service = app(GhlCustomFieldService::class);
        $result = $service->updateContactCustomFields(
            $locationId, 
            $contactId, 
            'WESS-CLIENT-007', 
            '2026-10-09',
            [
                'salutation'     => 'Ms',
                'gender'         => 'F',
                'nric'           => 'S1234567A',
                'contact_type'   => 'Existing Member',
                'birthday_month' => 'October',
                'preferred_name' => 'Sherry',
            ]
        );

        $this->assertTrue($result);

        Http::assertSent(function ($request) use ($contactId) {
            if ($request->url() === "https://services.leadconnectorhq.com/contacts/{$contactId}" && $request->method() === 'PUT') {
                $payload = $request->data();
                $customFields = $payload['customFields'] ?? [];
                // 2 core + 6 additional = 8 custom fields
                return count($customFields) === 8
                    && $customFields[0]['id'] === 'cf_wess_111'
                    && $customFields[0]['field_value'] === 'WESS-CLIENT-007'
                    && $customFields[1]['id'] === 'cf_visit_222'
                    && $customFields[1]['field_value'] === '2026-10-09';
            }
            return false;
        });
    }

    public function test_get_or_create_contact_attaches_wess_tag(): void
    {
        $locationId = 'loc_test_wess_tag';
        GhlToken::create([
            'location_id'   => $locationId,
            'access_token'  => 'fake_ghl_token',
            'refresh_token' => 'fake_refresh_token',
            'user_type'     => 'Location',
            'expires_at'    => now()->addDay(),
        ]);

        Http::fake([
            'https://services.leadconnectorhq.com/contacts/upsert' => Http::response([
                'contact' => ['id' => 'cnt_wess_tag_123']
            ], 200),
        ]);

        $calendarService = app(\App\Services\Ghl\GhlCalendarService::class);
        $contactId = $calendarService->getOrCreateContact($locationId, 'John Doe', '+6591234567', 'john@example.com');

        $this->assertEquals('cnt_wess_tag_123', $contactId);

        Http::assertSent(function ($request) {
            if ($request->url() === 'https://services.leadconnectorhq.com/contacts/upsert' && $request->method() === 'POST') {
                $data = $request->data();
                return isset($data['tags']) && in_array('WESS', $data['tags']);
            }
            return false;
        });
    }
}
