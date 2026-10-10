<?php

namespace App\Console\Commands;

use App\Services\Wess\WessSyncService;
use Illuminate\Console\Command;

class SyncWessAppointments extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'wess:sync {--location= : Optional specific location ID}';

    /**
     * The console command description.
     */
    protected $description = 'Synchronize appointments and customer data between WESS and GoHighLevel';

    /**
     * Execute the console command.
     */
    public function handle(WessSyncService $syncService): int
    {
        $locationId = $this->option('location');

        $this->info("Starting WESS <-> GoHighLevel synchronization" . ($locationId ? " for location: {$locationId}" : "") . "...");

        $results = $syncService->syncAllLocations($locationId);

        foreach ($results as $loc => $res) {
            $status = $res['status'] ?? 'unknown';
            if ($status === 'success') {
                $this->info("✓ Location [{$loc}]: Synced {$res['synced_count']} appointments.");
            } elseif ($status === 'skipped') {
                $this->warn("⚠ Location [{$loc}]: {$res['message']}");
            } else {
                $this->error("✕ Location [{$loc}]: {$res['message']}");
            }
        }

        $this->info("Synchronization complete.");
        return Command::SUCCESS;
    }
}
