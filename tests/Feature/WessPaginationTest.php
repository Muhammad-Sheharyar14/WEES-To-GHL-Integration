<?php

namespace Tests\Feature;

use App\Services\Wess\WessClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WessPaginationTest extends TestCase
{
    public function test_fetch_all_pages_merges_multiple_pages_of_records(): void
    {
        $baseUrl = 'https://api.wessconnect.com/api/v1/online';
        $client = new WessClient('test_token', $baseUrl);

        Http::fake([
            "{$baseUrl}/branches/1/appointments?per_page=100&page=1" => Http::response([
                'data' => [
                    ['id' => 101, 'status' => 'OP'],
                    ['id' => 102, 'status' => 'CF'],
                ],
                'links' => [
                    'next' => "{$baseUrl}/branches/1/appointments?page=2",
                ],
                'meta' => [
                    'current_page' => 1,
                    'last_page'    => 3,
                    'per_page'     => 100,
                    'total'        => 6,
                ],
            ], 200),

            "{$baseUrl}/branches/1/appointments?per_page=100&page=2" => Http::response([
                'data' => [
                    ['id' => 103, 'status' => 'BC'],
                    ['id' => 104, 'status' => 'CF'],
                ],
                'links' => [
                    'next' => "{$baseUrl}/branches/1/appointments?page=3",
                ],
                'meta' => [
                    'current_page' => 2,
                    'last_page'    => 3,
                    'per_page'     => 100,
                    'total'        => 6,
                ],
            ], 200),

            "{$baseUrl}/branches/1/appointments?per_page=100&page=3" => Http::response([
                'data' => [
                    ['id' => 105, 'status' => 'RQ'],
                    ['id' => 106, 'status' => 'CF'],
                ],
                'links' => [
                    'next' => null,
                ],
                'meta' => [
                    'current_page' => 3,
                    'last_page'    => 3,
                    'per_page'     => 100,
                    'total'        => 6,
                ],
            ], 200),
        ]);

        $appointments = $client->getAppointments(1);

        $this->assertCount(6, $appointments);
        $this->assertEquals([101, 102, 103, 104, 105, 106], array_column($appointments, 'id'));
    }

    public function test_fetch_all_pages_respects_max_items_limit(): void
    {
        $baseUrl = 'https://api.wessconnect.com/api/v1/online';
        $client = new WessClient('test_token', $baseUrl);

        Http::fake([
            "{$baseUrl}/branches/1/appointments*" => Http::response([
                'data' => [
                    ['id' => 201],
                    ['id' => 202],
                    ['id' => 203],
                ],
                'links' => ['next' => "{$baseUrl}/branches/1/appointments?page=2"],
                'meta'  => ['current_page' => 1, 'last_page' => 5, 'per_page' => 2],
            ], 200),
        ]);

        // Request max 2 items
        $appointments = $client->getAppointments(1, [], true, 2);

        $this->assertCount(2, $appointments);
        $this->assertEquals(201, $appointments[0]['id']);
        $this->assertEquals(202, $appointments[1]['id']);
    }

    public function test_get_branches_fetches_all_pages(): void
    {
        $baseUrl = 'https://api.wessconnect.com/api/v1/online';
        $client = new WessClient('test_token', $baseUrl);

        Http::fake([
            "{$baseUrl}/branches?per_page=100&page=1" => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'Branch Alpha'],
                ],
                'links' => ['next' => "{$baseUrl}/branches?page=2"],
                'meta'  => ['current_page' => 1, 'last_page' => 2, 'per_page' => 100],
            ], 200),

            "{$baseUrl}/branches?per_page=100&page=2" => Http::response([
                'data' => [
                    ['id' => 2, 'name' => 'Branch Beta'],
                ],
                'links' => ['next' => null],
                'meta'  => ['current_page' => 2, 'last_page' => 2, 'per_page' => 100],
            ], 200),
        ]);

        $branches = $client->getBranches();

        $this->assertCount(2, $branches);
        $this->assertEquals('Branch Alpha', $branches[0]['name']);
        $this->assertEquals('Branch Beta', $branches[1]['name']);
    }
}
