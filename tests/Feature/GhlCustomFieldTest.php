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
                    [
                        'id'       => 'field_wess_code_999',
                        'name'     => 'WESS Code',
                        'dataType' => 'TEXT',
                        'model'    => 'contact',
                    ],
                    [
                        'id'       => 'field_last_visit_888',
                        'name'     => 'Last Visit',
                        'dataType' => 'TEXT',
                        'model'    => 'contact',
                    ],
                ]
            ], 200),
        ]);

        $service = app(GhlCustomFieldService::class);
        $fields = $service->getOrCreateContactCustomFields($locationId);

        $this->assertEquals('field_wess_code_999', $fields['wess_code_id']);
        $this->assertEquals('field_last_visit_888', $fields['last_visit_id']);

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
                if ($name === 'WESS Code') {
                    return Http::response(['customField' => ['id' => 'new_id_wess_code', 'name' => 'WESS Code']], 201);
                }
                if ($name === 'Last Visit') {
                    return Http::response(['customField' => ['id' => 'new_id_last_visit', 'name' => 'Last Visit']], 201);
                }
                return Http::response([], 400);
            },
        ]);

        $service = app(GhlCustomFieldService::class);
        $fields = $service->getOrCreateContactCustomFields($locationId);

        $this->assertEquals('new_id_wess_code', $fields['wess_code_id']);
        $this->assertEquals('new_id_last_visit', $fields['last_visit_id']);

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
                ]
            ], 200),
            "https://services.leadconnectorhq.com/contacts/{$contactId}" => Http::response([
                'succeded' => true,
                'contact'  => ['id' => $contactId]
            ], 200),
        ]);

        $service = app(GhlCustomFieldService::class);
        $result = $service->updateContactCustomFields($locationId, $contactId, 'WESS-CLIENT-007', '2026-10-09');

        $this->assertTrue($result);

        Http::assertSent(function ($request) use ($contactId) {
            if ($request->url() === "https://services.leadconnectorhq.com/contacts/{$contactId}" && $request->method() === 'PUT') {
                $payload = $request->data();
                $customFields = $payload['customFields'] ?? [];
                return count($customFields) === 2
                    && $customFields[0]['id'] === 'cf_wess_111'
                    && $customFields[0]['field_value'] === 'WESS-CLIENT-007'
                    && $customFields[1]['id'] === 'cf_visit_222'
                    && $customFields[1]['field_value'] === '2026-10-09';
            }
            return false;
        });
    }
}
