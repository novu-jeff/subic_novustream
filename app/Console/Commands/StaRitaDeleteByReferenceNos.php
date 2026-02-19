<?php

namespace App\Console\Commands;

use App\Models\Bill;
use App\Models\Reading;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StaRitaDeleteByReferenceNos extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sta-rita:delete-by-reference-nos
                            {file? : Path to text file with one reference_no per line (default: storage/app/sta_rita_reference_nos_to_delete.txt)}
                            {--dry-run : List what would be deleted without deleting}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete Sta Rita entries (Readings, Bills, Bill Breakdown, Bill Discount, related payments) by reference_no list. Uses DB cascades; does not break system logic.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $file = $this->argument('file') ?? storage_path('app/sta_rita_reference_nos_to_delete.txt');

        if (!is_file($file)) {
            $this->error("File not found: {$file}");
            return self::FAILURE;
        }

        $lines = array_filter(array_map('trim', file($file)));
        $referenceNos = array_values(array_unique($lines));

        if (empty($referenceNos)) {
            $this->warn('No reference numbers found in file.');
            return self::SUCCESS;
        }

        $this->info('Reference numbers to process: ' . count($referenceNos));

        $bills = Bill::whereIn('reference_no', $referenceNos)->get();
        $readingIds = $bills->pluck('reading_id')->unique()->values()->all();
        $billIds = $bills->pluck('id')->all();

        $offlineCount = DB::table('offline_payments')->whereIn('reference_no', $referenceNos)->count();

        $this->table(
            ['Metric', 'Count'],
            [
                ['Bills (by reference_no)', count($billIds)],
                ['Readings (to delete)', count($readingIds)],
                ['Offline payments (reference_no)', $offlineCount],
            ]
        );

        if (count($billIds) === 0 && $offlineCount === 0) {
            $this->warn('No matching bills or offline_payments found for the given reference numbers.');
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info('[DRY RUN] Would delete:');
            $this->info('  - ' . count($readingIds) . ' reading(s) → cascade: bill, bill_breakdown, bill_discount, advance_payments, partial_payments');
            $this->info('  - ' . $offlineCount . ' offline_payment(s) by reference_no');
            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm('Proceed with deletion? This cannot be undone.')) {
            $this->info('Aborted.');
            return self::SUCCESS;
        }

        DB::beginTransaction();
        try {
            $deletedOffline = DB::table('offline_payments')->whereIn('reference_no', $referenceNos)->delete();
            $this->info("Deleted {$deletedOffline} offline_payment(s).");

            $deletedReadings = Reading::whereIn('id', $readingIds)->delete();
            $this->info("Deleted {$deletedReadings} reading(s). Cascaded: bill, bill_breakdown, bill_discount, advance_payments, partial_payments.");

            DB::commit();
            $this->info('Done. System logic preserved (deletion order and cascades respected).');
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Deletion failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
