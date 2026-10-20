<?php

namespace App\Console\Commands;

use App\Services\Wess\WessSyncService;
use Illuminate\Console\Command;

class SyncWessAppointments extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'wess:sync {--location= : Optional specific location ID} {--limit= : Limit appointments to sync for testing} {--test : Enable test mode with sample appointments}';

    /**
     * The console command description.
     */
    protected $description = 'Synchronize appointments and customer data between WESS and CRM';

    /**
     * Execute the console command.
     */
    public function handle(WessSyncService $syncService): int
    {
        $locationId = $this->option('location');
        $limit      = $this->option('limit') ? (int)$this->option('limit') : null;
        $isTest     = (bool)$this->option('test');

        $this->info("Starting WESS <-> CRM synchronization" 
            . ($locationId ? " for location: {$locationId}" : "") 
            . ($limit ? " [LIMIT: {$limit}]" : "") 
            . ($isTest ? " [TEST MODE]" : "") 
            . "...");

        $results = $syncService->syncAllLocations($locationId, $limit, $isTest);

        foreach ($results as $loc => $res) {
            $status = $res['status'] ?? 'unknown';
            if ($status === 'success') {
                $count = $res['synced_count'] ?? 0;
                $limitNote = $limit ? " (Test Limit: {$limit})" : "";
                $this->info("✓ Location [{$loc}]: Synced {$count} appointment(s){$limitNote}.");
            } elseif ($status === 'stopped') {
                $this->warn("⏸ Location [{$loc}]: {$res['message']}");
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
