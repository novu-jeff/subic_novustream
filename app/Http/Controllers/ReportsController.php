<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use App\Models\Reading;
use App\Models\Bill;
use App\Models\ConcessionerAccount;
use Carbon\Carbon;
use DB;

class ReportsController extends Controller
{
    /**
     * Show the download options page
     */
    public function downloadFilesIndex()
    {
        $availableReports = [
            'Ageing (Detailed)',
            'Ageing (Summary)',
            'Ageing (Recap)',
            'List of Disconnected Con.',
            'Penalty Report (Detailed)',
            'Penalty Report (Summary)',
            'Franchise Tax Report(Detailed)',
            'Franchise Tax Report(Summary)',
            'Monthly Billing Summary',
            'Billed Con by Category and Size',
            'Consumption by Category & Size',
            'All Payments',
            'Unpaid Bills',
            'Paid Bills',
            'Readings',
            'Senior Count',
            'List of Active',
            'List of Inactive',
            'Book Summary Report',
        ];

        // Fetch all zones ascending for dropdown
        $zones = ConcessionerAccount::select('zone')
            ->distinct()
            ->orderBy('zone', 'asc')
            ->pluck('zone');

        return view('reports.download-index', compact('availableReports', 'zones'));
    }

    /**
     * Generate Excel or CSV files from DB
     */
    public function generateFile(Request $request)
    {
        $request->validate([
            'reports' => 'required|array|min:1',
            'mode' => 'required|in:combined,separate',
            'format' => 'required|in:xlsx,csv',
            'zone' => 'required', // now required for ageing summary
        ]);

        $reports = $request->input('reports', []);
        $mode = $request->input('mode');
        $format = $request->input('format');

        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $zone = $request->input('zone');
        $classification = $request->input('classification');

        $dataByReport = $this->fetchReportsFromDb($reports, $startDate, $endDate, $zone, $classification);

        if ($mode === 'separate') {
            $files = [];
            foreach ($dataByReport as $reportName => $rows) {
                $filePath = $this->createFile($reportName, $rows, $format);
                $files[] = $filePath;
            }

            if (count($files) > 1) {
                $zipName = 'reports-' . now()->format('Ymd_His') . '.zip';
                $zipPath = storage_path("app/reports/{$zipName}");
                $zip = new \ZipArchive();
                if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
                    foreach ($files as $f) {
                        $zip->addFile($f, basename($f));
                    }
                    $zip->close();
                }
                return response()->download($zipPath)->deleteFileAfterSend(true);
            }

            return response()->download($files[0])->deleteFileAfterSend(true);
        }

        $spreadsheet = new Spreadsheet();
        $firstSheet = true;

        foreach ($dataByReport as $reportName => $rows) {
            if ($firstSheet) {
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setTitle(substr($reportName, 0, 31));
                $firstSheet = false;
            } else {
                $sheet = $spreadsheet->createSheet();
                $sheet->setTitle(substr($reportName, 0, 31));
            }

            if (!empty($rows)) {
                $headers = array_keys($rows[0]);
                $sheet->fromArray([$headers], null, 'A1');
                $sheet->fromArray($rows, null, 'A2');
            }
        }

        $fileName = 'combined-reports-' . now()->format('Ymd_His') . '.' . $format;
        $filePath = storage_path("app/reports/{$fileName}");

        $writer = $format === 'csv' ? new Csv($spreadsheet) : new Xlsx($spreadsheet);
        $writer->save($filePath);

        return response()->download($filePath)->deleteFileAfterSend(true);
    }

    /**
     * Fetch report data from database
     */
    protected function fetchReportsFromDb(array $reports, $startDate = null, $endDate = null, $zone = null, $classification = null)
    {
        $result = [];

        foreach ($reports as $report) {
            switch ($report) {
                case 'Ageing (Detailed)':
                    $readings = Reading::with(['concessionaire.user', 'bill'])
                        ->when($zone !== 'all', fn($q) => $q->where('zone', $zone))
                        ->get();

                    $rows = [];
                    foreach ($readings as $reading) {
                        $bill = $reading->bill;
                        if (!$bill) continue;

                        $dueDate = Carbon::parse($bill->due_date);
                        $daysOverdue = $dueDate->diffInDays(now(), false);

                        $current = $daysOverdue <= 0 ? $bill->amount : 0;
                        $oneTo30 = $daysOverdue > 0 && $daysOverdue <= 30 ? $bill->amount : 0;
                        $thirtyOneTo60 = $daysOverdue > 30 && $daysOverdue <= 60 ? $bill->amount : 0;
                        $sixtyOneTo90 = $daysOverdue > 60 && $daysOverdue <= 90 ? $bill->amount : 0;
                        $over90 = $daysOverdue > 90 ? $bill->amount : 0;

                        $rows[] = [
                            'account_number' => $reading->account_no,
                            'name' => optional(optional($reading->concessionaire)->user)->name ?? 'N/A',
                            'current' => $current,
                            '1_30' => $oneTo30,
                            '31_60' => $thirtyOneTo60,
                            '61_90' => $sixtyOneTo90,
                            'over_90' => $over90,
                            'total' => $current + $oneTo30 + $thirtyOneTo60 + $sixtyOneTo90 + $over90,
                        ];
                    }
                    $result[$report] = $rows;
                    break;

                case 'Ageing (Summary)':
                // Fetch readings with their bill; limit to one reading per account
                $query = Reading::with('bill')
                    ->select('readings.*')
                    ->when($zone !== 'all', fn($q) => $q->where('zone', $zone))
                    ->whereHas('bill') // ensure only readings with a bill
                    ->orderBy('zone', 'asc')
                    ->get()
                    ->groupBy('zone');

                $rows = [];
                $totals = [
                    'current' => 0,
                    '1_30' => 0,
                    '31_60' => 0,
                    '61_90' => 0,
                    'over_90' => 0,
                    'total' => 0,
                ];

                foreach ($query as $zoneKey => $readings) {
                    $zoneSummary = [
                        'current' => 0,
                        '1_30' => 0,
                        '31_60' => 0,
                        '61_90' => 0,
                        'over_90' => 0,
                    ];

                    // Avoid redundant bill reads per account
                    $uniqueAccounts = $readings->unique('account_no');

                    foreach ($uniqueAccounts as $reading) {
                        $bill = $reading->bill;
                        if (!$bill) continue;

                        $dueDate = Carbon::parse($bill->due_date);
                        $daysOverdue = $dueDate->diffInDays(now(), false);

                        if ($daysOverdue <= 0) {
                            $zoneSummary['current'] += $bill->amount;
                        } elseif ($daysOverdue <= 30) {
                            $zoneSummary['1_30'] += $bill->amount;
                        } elseif ($daysOverdue <= 60) {
                            $zoneSummary['31_60'] += $bill->amount;
                        } elseif ($daysOverdue <= 90) {
                            $zoneSummary['61_90'] += $bill->amount;
                        } else {
                            $zoneSummary['over_90'] += $bill->amount;
                        }
                    }

                    $total = array_sum($zoneSummary);

                    // Update grand totals
                    foreach ($zoneSummary as $key => $value) {
                        $totals[$key] += $value;
                    }
                    $totals['total'] += $total;

                    $rows[] = [
                        'zone' => $zoneKey,
                        'current' => $zoneSummary['current'],
                        '1_30' => $zoneSummary['1_30'],
                        '31_60' => $zoneSummary['31_60'],
                        '61_90' => $zoneSummary['61_90'],
                        'over_90' => $zoneSummary['over_90'],
                        'total' => $total,
                    ];
                }

                usort($rows, fn($a, $b) => (int)$a['zone'] <=> (int)$b['zone']);

                $rows[] = [
                    'zone' => 'TOTAL',
                    'current' => $totals['current'],
                    '1_30' => $totals['1_30'],
                    '31_60' => $totals['31_60'],
                    '61_90' => $totals['61_90'],
                    'over_90' => $totals['over_90'],
                    'total' => $totals['total'],
                ];

                $result[$report] = $rows;
                break;

                case 'Ageing (Recap)':
                $readings = Reading::with(['bill', 'concessionaire.propertyType'])
                    ->whereHas('bill')
                    ->when($zone !== 'all', fn($q) => $q->where('zone', $zone))
                    ->get();

                $grouped = $readings->groupBy(function($reading) {
                    return optional(optional($reading->concessionaire)->propertyType)->name
                        ?? optional($reading->concessionaire)->property_type
                        ?? 'UNCLASSIFIED';
                });

                $rows = [];
                $totals = [
                    'customers' => 0,
                    'current' => 0,
                    '1_30' => 0,
                    '31_60' => 0,
                    '61_90' => 0,
                    'over_90' => 0,
                    'total' => 0,
                ];

                foreach ($grouped as $classification => $groupReadings) {
                    $summary = [
                        'customers' => $groupReadings->unique('account_no')->count(),
                        'current' => 0,
                        '1_30' => 0,
                        '31_60' => 0,
                        '61_90' => 0,
                        'over_90' => 0,
                    ];

                    foreach ($groupReadings->unique('account_no') as $reading) {
                        $bill = $reading->bill;
                        if (!$bill) continue;

                        $dueDate = Carbon::parse($bill->due_date);
                        $daysOverdue = $dueDate->diffInDays(now(), false);

                        if ($daysOverdue <= 0) $summary['current'] += $bill->amount;
                        elseif ($daysOverdue <= 30) $summary['1_30'] += $bill->amount;
                        elseif ($daysOverdue <= 60) $summary['31_60'] += $bill->amount;
                        elseif ($daysOverdue <= 90) $summary['61_90'] += $bill->amount;
                        else $summary['over_90'] += $bill->amount;
                    }

                    $summary['total'] = $summary['current'] + $summary['1_30'] + $summary['31_60'] + $summary['61_90'] + $summary['over_90'];

                    foreach ($totals as $key => $value) {
                        if (isset($summary[$key])) $totals[$key] += $summary[$key];
                    }

                    $rows[] = array_merge(['classification' => $classification], $summary);
                }

                $rows[] = [
                    'classification' => 'GRAND TOTAL',
                    'customers' => $totals['customers'],
                    'current' => $totals['current'],
                    '1_30' => $totals['1_30'],
                    '31_60' => $totals['31_60'],
                    '61_90' => $totals['61_90'],
                    'over_90' => $totals['over_90'],
                    'total' => $totals['total'],
                ];

                $result[$report] = $rows;
                break;

                case 'List of Disconnected Con.':
                $readings = Reading::with([
                        'concessionaire.user',
                        'concessionaire.propertyType',
                        'concessionaire.statusCode',
                        'bill'
                    ])
                    ->when($zone !== 'all', fn($q) => $q->where('zone', $zone))
                    ->whereHas('concessionaire', fn($q) => $q->where('status', 3))
                    ->get();

                $rows = [];

                foreach ($readings as $reading) {
                    $con = $reading->concessionaire;
                    $lastBill = $reading->bill;

                    $arrears = $lastBill ? $lastBill->amount : 0;
                    $monthInArrears = $lastBill ? now()->diffInMonths(Carbon::parse($lastBill->due_date)) : 0;

                    $rows[] = [
                        'Name of Consumers' => optional($con->user)->name ?? 'N/A',
                        'Zone' => $con->zone ?? 'N/A',
                        'Service Address' => $con->address ?? 'N/A',
                        'Account no.' => $con->account_no ?? 'N/A',
                        'Type' => optional($con->propertyType)->name ?? $con->property_type ?? 'N/A',
                        'Arrears' => $arrears,
                        'Date closed' => $reading->closed_date ?? 'N/A',
                        'Month in arrears' => $monthInArrears,
                    ];
                }

                $rows = collect($rows)->sortBy('Zone')->values()->all();

                $totalArrears = collect($rows)->sum('Arrears');
                $rows[] = [
                    'Name of Consumers' => 'TOTAL',
                    'Zone' => '',
                    'Service Address' => '',
                    'Account no.' => '',
                    'Type' => '',
                    'Arrears' => $totalArrears,
                    'Date closed' => '',
                    'Month in arrears' => '',
                ];

                $result[$report] = $rows;
                break;

                case 'Penalty Report (Detailed)':
                $readings = Reading::with(['bill', 'concessionaire.user'])
                    ->whereHas('bill')
                    ->when($zone !== 'all', fn($q) => $q->where('zone', $zone))
                    ->get();

                $rows = [];

                $totals = [
                    'Current Penalty' => 0,
                    '1-30 days' => 0,
                    '31-60 days' => 0,
                    '61-90 days' => 0,
                    'Waterbill Total' => 0,
                ];

                foreach ($readings->unique('account_no') as $reading) {
                    $bill = $reading->bill;
                    if (!$bill) continue;

                    $accountNo = $reading->account_no;
                    $name = optional(optional($reading->concessionaire)->user)->name ?? 'N/A';
                    $penalty = floatval($bill->penalty ?? 0);
                    $amount = floatval($bill->amount ?? 0);

                    $dueDate = Carbon::parse($bill->due_date);
                    $daysOverdue = $dueDate->diffInDays(now(), false);

                    $buckets = [
                        'Current Penalty' => 0,
                        '1-30 days' => 0,
                        '31-60 days' => 0,
                        '61-90 days' => 0,
                    ];

                    if ($daysOverdue <= 0) {
                        $buckets['Current Penalty'] = $penalty;
                    } elseif ($daysOverdue <= 30) {
                        $buckets['1-30 days'] = $penalty;
                    } elseif ($daysOverdue <= 60) {
                        $buckets['31-60 days'] = $penalty;
                    } elseif ($daysOverdue <= 90) {
                        $buckets['61-90 days'] = $penalty;
                    }

                    $waterbillTotal = array_sum($buckets) + $amount;

                    $rows[] = [
                        'Account Number' => $accountNo,
                        'Name' => $name,
                        'Current Penalty' => $buckets['Current Penalty'],
                        '1-30 days' => $buckets['1-30 days'],
                        '31-60 days' => $buckets['31-60 days'],
                        '61-90 days' => $buckets['61-90 days'],
                        'Waterbill Total' => $waterbillTotal,
                    ];

                    foreach ($buckets as $label => $value) {
                        $totals[$label] += $value;
                    }
                    $totals['Waterbill Total'] += $waterbillTotal;
                }

                $rows[] = [
                    'Account Number' => 'TOTAL',
                    'Name' => '',
                    'Current Penalty' => $totals['Current Penalty'],
                    '1-30 days' => $totals['1-30 days'],
                    '31-60 days' => $totals['31-60 days'],
                    '61-90 days' => $totals['61-90 days'],
                    'Waterbill Total' => $totals['Waterbill Total'],
                ];

                if (!empty($rows)) {
                    $result[$report] = $rows;
                } else {
                    $result[$report] = [['Account Number' => 'No records found']];
                }

                break;

                case 'Penalty Report (Summary)':
                $readings = Reading::with(['bill', 'concessionaire.propertyType'])
                    ->whereHas('bill')
                    ->when($zone !== 'all', fn($q) => $q->where('zone', $zone))
                    ->get();

                $grouped = $readings->groupBy(function($reading) {
                    return optional(optional($reading->concessionaire)->propertyType)->name
                        ?? optional($reading->concessionaire)->property_type
                        ?? 'UNCLASSIFIED';
                });

                $rows = [];
                $totals = [
                    'Number of Customer' => 0,
                    'Current Penalty' => 0,
                    '1-30 days' => 0,
                    '31-60 days' => 0,
                    '61-90 days' => 0,
                    'Over 90 days' => 0,
                    'Penalty Total' => 0,
                ];

                foreach ($grouped as $classification => $groupReadings) {
                    $summary = [
                        'Number of Customer' => $groupReadings->unique('account_no')->count(),
                        'Current Penalty' => 0,
                        '1-30 days' => 0,
                        '31-60 days' => 0,
                        '61-90 days' => 0,
                        'Over 90 days' => 0,
                    ];

                    foreach ($groupReadings->unique('account_no') as $reading) {
                        $bill = $reading->bill;
                        if (!$bill) continue;

                        $penalty = floatval($bill->penalty ?? 0);
                        if ($penalty <= 0) continue; // skip zero penalties for cleaner output

                        $dueDate = Carbon::parse($bill->due_date);
                        $daysOverdue = $dueDate->diffInDays(now(), false);

                        if ($daysOverdue <= 0) $summary['Current Penalty'] += $penalty;
                        elseif ($daysOverdue <= 30) $summary['1-30 days'] += $penalty;
                        elseif ($daysOverdue <= 60) $summary['31-60 days'] += $penalty;
                        elseif ($daysOverdue <= 90) $summary['61-90 days'] += $penalty;
                        else $summary['Over 90 days'] += $penalty;
                    }

                    $summary['Penalty Total'] = $summary['Current Penalty'] + $summary['1-30 days'] +
                        $summary['31-60 days'] + $summary['61-90 days'] + $summary['Over 90 days'];

                    foreach ($totals as $key => $value) {
                        if (isset($summary[$key])) $totals[$key] += $summary[$key];
                    }

                    $rows[] = array_merge(['Classification' => $classification], $summary);
                }

                // Grand Total row
                $rows[] = [
                    'Classification' => 'GRAND TOTAL',
                    'Number of Customer' => $totals['Number of Customer'],
                    'Current Penalty' => $totals['Current Penalty'],
                    '1-30 days' => $totals['1-30 days'],
                    '31-60 days' => $totals['31-60 days'],
                    '61-90 days' => $totals['61-90 days'],
                    'Over 90 days' => $totals['Over 90 days'],
                    'Penalty Total' => $totals['Penalty Total'],
                ];

                $result[$report] = $rows;
                break;

                case 'Franchise Tax Report(Detailed)':
                $readings = Reading::with(['bill', 'concessionaire.user'])
                    ->whereHas('bill')
                    ->when($zone !== 'all', fn($q) => $q->where('zone', $zone))
                    ->get();

                $rows = [];

                $totals = [
                    'Current Penalty' => 0,
                    '1-30 days' => 0,
                    '31-60 days' => 0,
                    '61-90 days' => 0,
                    'Franchise Tax Total' => 0,
                ];

                foreach ($readings->unique('account_no') as $reading) {
                    $bill = $reading->bill;
                    if (!$bill) continue;

                    $accountNo = $reading->account_no;
                    $name = optional(optional($reading->concessionaire)->user)->name ?? 'N/A';
                    $tax = floatval($bill->tax ?? 0); // 👈 franchise tax only

                    $dueDate = Carbon::parse($bill->due_date);
                    $daysOverdue = $dueDate->diffInDays(now(), false);

                    $buckets = [
                        'Current Penalty' => 0,
                        '1-30 days' => 0,
                        '31-60 days' => 0,
                        '61-90 days' => 0,
                    ];

                    if ($daysOverdue <= 0) {
                        $buckets['Current Penalty'] = $tax;
                    } elseif ($daysOverdue <= 30) {
                        $buckets['1-30 days'] = $tax;
                    } elseif ($daysOverdue <= 60) {
                        $buckets['31-60 days'] = $tax;
                    } elseif ($daysOverdue <= 90) {
                        $buckets['61-90 days'] = $tax;
                    }

                    $franchiseTaxTotal = array_sum($buckets);

                    $rows[] = [
                        'Account Number' => $accountNo,
                        'Name' => $name,
                        'Current Penalty' => $buckets['Current Penalty'],
                        '1-30 days' => $buckets['1-30 days'],
                        '31-60 days' => $buckets['31-60 days'],
                        '61-90 days' => $buckets['61-90 days'],
                        'Franchise Tax Total' => $franchiseTaxTotal,
                    ];

                    foreach ($buckets as $label => $value) {
                        $totals[$label] += $value;
                    }
                    $totals['Franchise Tax Total'] += $franchiseTaxTotal;
                }

                // Add total row
                $rows[] = [
                    'Account Number' => 'TOTAL',
                    'Name' => '',
                    'Current Penalty' => $totals['Current Penalty'],
                    '1-30 days' => $totals['1-30 days'],
                    '31-60 days' => $totals['31-60 days'],
                    '61-90 days' => $totals['61-90 days'],
                    'Franchise Tax Total' => $totals['Franchise Tax Total'],
                ];

                $result[$report] = !empty($rows) ? $rows : [['Account Number' => 'No records found']];

                break;

                case 'Franchise Tax Report(Summary)':
                $readings = Reading::with(['bill', 'concessionaire.propertyType'])
                    ->whereHas('bill')
                    ->when($zone !== 'all', fn($q) => $q->where('zone', $zone))
                    ->get();

                // Group readings by property type / classification
                $grouped = $readings->groupBy(function($reading) {
                    return optional(optional($reading->concessionaire)->propertyType)->name
                        ?? optional($reading->concessionaire)->property_type
                        ?? 'UNCLASSIFIED';
                });

                $rows = [];
                $totals = [
                    'customers' => 0,
                    'current' => 0,
                    '1_30' => 0,
                    '31_60' => 0,
                    '61_90' => 0,
                    'over_90' => 0,
                    'total' => 0,
                ];

                foreach ($grouped as $classification => $groupReadings) {
                    $uniqueAccounts = $groupReadings->unique('account_no');
                    $summary = [
                        'customers' => $uniqueAccounts->count(),
                        'current' => 0,
                        '1_30' => 0,
                        '31_60' => 0,
                        '61_90' => 0,
                        'over_90' => 0,
                    ];

                    foreach ($uniqueAccounts as $reading) {
                        $bill = $reading->bill;
                        if (!$bill) continue;

                        $tax = floatval($bill->tax ?? 0);
                        $dueDate = Carbon::parse($bill->due_date);

                        // Correct overdue calculation: positive = overdue, negative = not yet due
                        $daysOverdue = now()->diffInDays($dueDate, false);

                        if ($daysOverdue < 0) $summary['current'] += $tax;         // Not yet due
                        elseif ($daysOverdue <= 30) $summary['1_30'] += $tax;
                        elseif ($daysOverdue <= 60) $summary['31_60'] += $tax;
                        elseif ($daysOverdue <= 90) $summary['61_90'] += $tax;
                        else $summary['over_90'] += $tax;
                    }

                    $summary['total'] = $summary['current'] + $summary['1_30'] + $summary['31_60'] + $summary['61_90'] + $summary['over_90'];

                    // Add to grand totals
                    $totals['customers'] += $summary['customers'];
                    $totals['current'] += $summary['current'];
                    $totals['1_30'] += $summary['1_30'];
                    $totals['31_60'] += $summary['31_60'];
                    $totals['61_90'] += $summary['61_90'];
                    $totals['over_90'] += $summary['over_90'];
                    $totals['total'] += $summary['total'];

                    $rows[] = [
                        'Classification' => $classification,
                        'Number of Customer' => $summary['customers'],
                        'Current Penalty' => $summary['current'],
                        '1-30 days' => $summary['1_30'],
                        '31-60 days' => $summary['31_60'],
                        '61-90 days' => $summary['61_90'],
                        'Over 90 days' => $summary['over_90'],
                        'Franchise Tax Total' => $summary['total'],
                    ];
                }

                // Add GRAND TOTAL row
                $rows[] = [
                    'Classification' => 'GRAND TOTAL',
                    'Number of Customer' => $totals['customers'],
                    'Current Penalty' => $totals['current'],
                    '1-30 days' => $totals['1_30'],
                    '31-60 days' => $totals['31_60'],
                    '61-90 days' => $totals['61_90'],
                    'Over 90 days' => $totals['over_90'],
                    'Franchise Tax Total' => $totals['total'],
                ];

                $result[$report] = $rows;
                break;

                case 'Monthly Billing Summary':
                $readings = Reading::with(['bill', 'concessionaire'])
                    ->whereHas('bill')
                    ->when($zone !== 'all', fn($q) => $q->where('zone', $zone))
                    ->orderBy('zone', 'asc')
                    ->get();

                if ($readings->isEmpty()) {
                    $result[$report] = [['Zone' => 'No data found']];
                    break;
                }

                // Group by zone
                $zonesGrouped = $readings->groupBy('zone');

                $rows = [];

                foreach ($zonesGrouped as $zoneKey => $zoneReadings) {
                    // Group by property_type (e.g. RESIDENTIAL/GOVERNMENT 1/2'')
                    $byType = $zoneReadings->groupBy(fn($r) => $r->concessionaire->property_type ?? 'UNCLASSIFIED');

                    // Zone header row
                    $rows[] = ['Zone' => "Zone $zoneKey", 'Connections' => '', 'Usage' => '', 'Water Bills' => '', 'Penalty' => '', 'Total' => ''];

                    $zoneTotals = ['connections' => 0, 'usage' => 0, 'waterBills' => 0, 'penalty' => 0, 'total' => 0];

                    foreach ($byType as $type => $group) {
                        $connections = $group->unique('account_no')->count();
                        $usage = $group->sum(fn($r) => max(0, ($r->present_reading ?? 0) - ($r->previous_reading ?? 0)));

                        $waterBills = $group->sum(fn($r) => floatval(optional($r->bill)->amount ?? 0));
                        $penalty = $group->sum(fn($r) => floatval(optional($r->bill)->penalty ?? 0));
                        $total = $waterBills + $penalty;

                        $zoneTotals['connections'] += $connections;
                        $zoneTotals['usage'] += $usage;
                        $zoneTotals['waterBills'] += $waterBills;
                        $zoneTotals['penalty'] += $penalty;
                        $zoneTotals['total'] += $total;

                        $rows[] = [
                            'Zone' => $type,
                            'Connections' => $connections,
                            'Usage' => $usage,
                            'Water Bills' => number_format($waterBills, 2),
                            'Penalty' => number_format($penalty, 2),
                            'Total' => number_format($total, 2),
                        ];
                    }

                    // Zone total row
                    $rows[] = [
                        'Zone' => "TOTAL Zone $zoneKey",
                        'Connections' => $zoneTotals['connections'],
                        'Usage' => $zoneTotals['usage'],
                        'Water Bills' => number_format($zoneTotals['waterBills'], 2),
                        'Penalty' => number_format($zoneTotals['penalty'], 2),
                        'Total' => number_format($zoneTotals['total'], 2),
                    ];

                    // Empty spacer row
                    $rows[] = ['', '', '', '', '', ''];
                }

                $result[$report] = $rows;
                break;

                case 'Billed Con by Category and Size':
                $readings = Reading::with(['bill', 'concessionaire'])
                    ->whereHas('bill')
                    ->when($zone !== 'all', fn($q) => $q->where('zone', $zone))
                    ->orderBy('zone', 'asc')
                    ->get();

                if ($readings->isEmpty()) {
                    $result[$report] = [['Classification' => 'No data found']];
                    break;
                }

                // Extract sizes dynamically from property_types table
                $sizes = \App\Models\PropertyTypes::pluck('name')
                    ->map(function ($n) {
                        preg_match('/(\d+ ?\/? ?\d*)"?/', $n, $m);
                        return $m[1] ?? null;
                    })
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values()
                    ->toArray();

                $columns = array_merge(['Classification / Zone'], $sizes, ['Total']);
                $rows = [];

                // Group by zone
                $zonesGrouped = $readings->groupBy('zone');

                // Initialize grand totals
                $grandTotals = array_fill_keys($sizes, 0);
                $grandTotals['Total'] = 0;

                foreach ($zonesGrouped as $zoneKey => $zoneReadings) {
                    // Zone header row
                    $rows[] = array_merge(['Classification / Zone' => "Zone $zoneKey"], array_fill_keys($sizes, ''), ['Total' => '']);

                    // Group by classification (main type, e.g. "RESIDENTIAL/GOVERNMENT")
                    $byClassification = $zoneReadings->groupBy(function ($r) {
                        $type = $r->concessionaire->property_type ?? '';
                        return preg_replace('/\s+\d.*$/', '', $type); // remove size portion
                    });

                    foreach ($byClassification as $classification => $classReadings) {
                        $counts = array_fill_keys($sizes, 0);

                        foreach ($classReadings as $r) {
                            $type = $r->concessionaire->property_type ?? '';
                            preg_match('/(\d+ ?\/? ?\d*)"?/', $type, $m);
                            $size = $m[1] ?? null;

                            if ($size && isset($counts[$size])) {
                                $counts[$size]++;
                            }
                        }

                        $rowTotal = array_sum($counts);
                        foreach ($counts as $size => $count) {
                            $grandTotals[$size] += $count;
                        }
                        $grandTotals['Total'] += $rowTotal;

                        $rows[] = array_merge(
                            ['Classification / Zone' => $classification],
                            $counts,
                            ['Total' => $rowTotal]
                        );
                    }

                    // Blank spacer after each zone
                    $rows[] = array_fill_keys($columns, '');
                }

                // Add GRAND TOTAL row
                $rows[] = array_merge(['Classification' => 'GRAND TOTAL'], $grandTotals);

                $result[$report] = $rows;
                break;

                case 'Consumption by Category & Size':
                $readings = Reading::with(['bill', 'concessionaire'])
                    ->whereHas('bill')
                    ->when($zone !== 'all', fn($q) => $q->where('zone', $zone))
                    ->orderBy('zone', 'asc')
                    ->get();

                if ($readings->isEmpty()) {
                    $result[$report] = [['Classification' => 'No data found']];
                    break;
                }

                // Extract sizes dynamically from property_types table
                $sizes = \App\Models\PropertyTypes::pluck('name')
                    ->map(function ($n) {
                        preg_match('/(\d+ ?\/? ?\d*)"?/', $n, $m);
                        return $m[1] ?? null;
                    })
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values()
                    ->toArray();

                $columns = array_merge(['Classification / Zone'], $sizes, ['Total']);
                $rows = [];

                // Group by zone
                $zonesGrouped = $readings->groupBy('zone');

                // Initialize grand totals
                $grandTotals = array_fill_keys($sizes, 0);
                $grandTotals['Total'] = 0;

                foreach ($zonesGrouped as $zoneKey => $zoneReadings) {
                    // Zone header row
                    $rows[] = array_merge(['Classification / Zone' => "Zone $zoneKey"], array_fill_keys($sizes, ''), ['Total' => '']);

                    // Group by classification (main type, e.g. "RESIDENTIAL/GOVERNMENT")
                    $byClassification = $zoneReadings->groupBy(function ($r) {
                        $type = $r->concessionaire->property_type ?? '';
                        return preg_replace('/\s+\d.*$/', '', $type); // remove size portion
                    });

                    foreach ($byClassification as $classification => $classReadings) {
                        // initialize total cubic meters per size
                        $consumptionTotals = array_fill_keys($sizes, 0);

                        foreach ($classReadings as $r) {
                            $type = $r->concessionaire->property_type ?? '';
                            preg_match('/(\d+ ?\/? ?\d*)"?/', $type, $m);
                            $size = $m[1] ?? null;

                            if ($size && isset($consumptionTotals[$size])) {
                                $consumptionTotals[$size] += $r->consumption ?? 0;
                            }
                        }

                        $rowTotal = array_sum($consumptionTotals);
                        foreach ($consumptionTotals as $size => $value) {
                            $grandTotals[$size] += $value;
                        }
                        $grandTotals['Total'] += $rowTotal;

                        $rows[] = array_merge(
                            ['Classification / Zone' => $classification],
                            array_map(fn($v) => number_format($v, 2), $consumptionTotals),
                            ['Total' => number_format($rowTotal, 2)]
                        );
                    }

                    // Spacer
                    $rows[] = array_fill_keys($columns, '');
                }

                // Add GRAND TOTAL row
                $rows[] = array_merge(
                    ['Classification / Zone' => 'GRAND TOTAL'],
                    array_map(fn($v) => number_format($v, 2), $grandTotals)
                );

                $result[$report] = $rows;
                break;

                case 'All Payments':

                $query = Bill::query()
                    ->with(['reading.concessionaire.user'])
                    ->whereNotNull('amount_paid') // only paid bills
                    ->when($zone !== 'all', function ($q) use ($zone) {
                        $q->whereHas('reading', fn($q2) => $q2->where('zone', $zone));
                    })
                    ->when($startDate, fn($q) => $q->whereDate('date_paid', '>=', $startDate))
                    ->when($endDate, fn($q) => $q->whereDate('date_paid', '<=', $endDate))
                    ->orderBy('date_paid', 'asc')
                    ->get();

                $rows = [];

                foreach ($query as $bill) {
                    $reading = $bill->reading;

                    $rows[] = [
                        'ACCOUNT NO'    => $reading->account_no ?? 'N/A',
                        'ZONE'          => $reading->zone ?? 'N/A',
                        'CONCESSIONAIRE' => optional(optional($reading->concessionaire)->user)->name ?? 'N/A',
                        'REFERENCE NO'  => $bill->reference_no,
                        'BILL PERIOD'   => $bill->bill_period_from . ' - ' . $bill->bill_period_to,
                        'AMOUNT'        => $bill->amount,
                        'PENALTY'       => $bill->penalty,
                        'DISCOUNT'      => $bill->discount,
                        'TOTAL'         => $bill->total,
                        'IS PARTIAL'    => $bill->isPartial,
                        'PARTIAL PAYMENT'   => $bill->partial_payment,
                        'IS PAID'       => $bill->isPaid,
                        'AMOUNT PAID'   => $bill->amount_paid,
                        'PAYMENT METHOD'=> $bill->payment_method ?? 'N/A',
                        'DATE PAID'     => $bill->date_paid,
                    ];
                }

                $result[$report] = $rows;
                break;

                case 'Unpaid Bills':

                $query = Bill::query()
                    ->join('readings', 'readings.id', '=', 'bill.reading_id')
                    ->join(
                        'concessioner_accounts',
                        'concessioner_accounts.account_no',
                        '=',
                        'readings.account_no'
                    )
                    ->with(['reading.concessionaire.user'])
                    ->where('bill.isPaid', 0)
                    ->whereNull('bill.amount_paid')
                    ->when($zone !== 'all', function ($q) use ($zone) {
                        $q->where('concessioner_accounts.zone', $zone);
                    })
                    ->when($startDate, fn ($q) => $q->whereDate('bill.created_at', '>=', $startDate))
                    ->when($endDate, fn ($q) => $q->whereDate('bill.created_at', '<=', $endDate))
                    ->orderBy('concessioner_accounts.sequence_no', 'asc')
                    ->orderBy('bill.created_at', 'asc')
                    ->select('bill.*', 'concessioner_accounts.sequence_no as sequence_no')
                    ->get();

                $rows = [];

                foreach ($query as $bill) {
                    $reading = $bill->reading;

                    $rows[] = [
                        'REFERENCE NO'          => $bill->reference_no ?? 'N/A',
                        'ACCOUNT NO'            => $reading->account_no ?? 'N/A',
                        'CONCESSIONAIRE'        => optional(optional($reading->concessionaire)->user)->name ?? 'N/A',
                        'PREVIOUS CONSUMPTION'  => $reading->previous_reading ?? 0,
                        'CURRENT CONSUMPTION'   => $reading->present_reading ?? 0,
                        'CONSUMPTION'           => $reading->consumption ?? 0,
                        'PREVIOUS UNPAID'       => $bill->previous_unpaid ?? 0,
                        'BASIC CHARGE'          => $bill->total - $bill->previous_unpaid ?? 0,
                        'TOTAL'                 => $bill->total ?? 0,
                        'DISCOUNT'              => $bill->discount ?? 0,
                        'PENALTY'               => $bill->penalty ?? 0,
                        'AMOUNT AFTER DUE'      => $bill->amount ?? 0,
                        'SEQUENCE NO'           => $bill->sequence_no ?? 0,
                    ];
                }

                $result[$report] = $rows;
                break;

                case 'Paid Bills':

                $query = Bill::query()
                    ->join('readings', 'readings.id', '=', 'bill.reading_id')
                    ->join(
                        'concessioner_accounts',
                        'concessioner_accounts.account_no',
                        '=',
                        'readings.account_no'
                    )
                    ->with(['reading.concessionaire.user'])
                    ->where('bill.isPaid', 1)
                    ->whereNull('bill.amount_paid')
                    ->when($zone !== 'all', function ($q) use ($zone) {
                        $q->where('concessioner_accounts.zone', $zone);
                    })
                    ->when($startDate, fn ($q) => $q->whereDate('bill.created_at', '>=', $startDate))
                    ->when($endDate, fn ($q) => $q->whereDate('bill.created_at', '<=', $endDate))
                    ->orderBy('concessioner_accounts.sequence_no', 'asc')
                    ->orderBy('bill.created_at', 'asc')
                    ->select('bill.*', 'concessioner_accounts.sequence_no as sequence_no')
                    ->get();

                $rows = [];

                foreach ($query as $bill) {
                    $reading = $bill->reading;

                    $rows[] = [
                        'REFERENCE NO'          => $bill->reference_no ?? 'N/A',
                        'ACCOUNT NO'            => $reading->account_no ?? 'N/A',
                        'CONCESSIONAIRE'        => optional(optional($reading->concessionaire)->user)->name ?? 'N/A',
                        'PREVIOUS CONSUMPTION'  => $reading->previous_reading ?? 0,
                        'CURRENT CONSUMPTION'   => $reading->present_reading ?? 0,
                        'CONSUMPTION'           => $reading->consumption ?? 0,
                        'PREVIOUS UNPAID'       => $bill->previous_unpaid ?? 0,
                        'BASIC CHARGE'          => $bill->total - $bill->previous_unpaid ?? 0,
                        'TOTAL'                 => $bill->total ?? 0,
                        'DISCOUNT'              => $bill->discount ?? 0,
                        'PENALTY'               => $bill->penalty ?? 0,
                        'AMOUNT AFTER DUE'      => $bill->amount ?? 0,
                        'AMOUNT PAID'           => $bill->amount_paid?? 0,
                        'SEQUENCE NO'           => $bill->sequence_no ?? 0,
                    ];
                }

                $result[$report] = $rows;
                break;

                case 'Readings':

                    $query = Bill::query()
                        ->join('readings', 'readings.id', '=', 'bill.reading_id')
                        ->join('concessioner_accounts', 'concessioner_accounts.account_no', '=', 'readings.account_no')
                        ->leftJoin('users', 'users.id', '=', 'concessioner_accounts.user_id')

                        ->when($zone !== 'all', fn ($q) =>
                            $q->where('concessioner_accounts.zone', $zone)
                        )

                        ->when($startDate && $endDate, fn ($q) =>
                            $q->whereBetween('bill.bill_period_to', [$startDate, $endDate])
                        )

                        ->selectRaw("
                            concessioner_accounts.zone AS zone,
                            readings.account_no,
                            users.name AS concessionaire_name,

                            MAX(concessioner_accounts.sequence_no) AS sequence_no,
                            MAX(CAST(readings.present_reading AS UNSIGNED)) AS current_reading,

                            SUM(CASE WHEN CAST(readings.consumption AS UNSIGNED) BETWEEN 1 AND 30
                                THEN CAST(readings.consumption AS UNSIGNED) ELSE 0 END) AS cons_1_30,

                            SUM(CASE WHEN CAST(readings.consumption AS UNSIGNED) BETWEEN 31 AND 60
                                THEN CAST(readings.consumption AS UNSIGNED) ELSE 0 END) AS cons_31_60,

                            SUM(CASE WHEN CAST(readings.consumption AS UNSIGNED) BETWEEN 61 AND 90
                                THEN CAST(readings.consumption AS UNSIGNED) ELSE 0 END) AS cons_61_90,

                            SUM(CASE WHEN CAST(readings.consumption AS UNSIGNED) > 90
                                THEN CAST(readings.consumption AS UNSIGNED) ELSE 0 END) AS cons_over_90,

                            SUM(CAST(readings.consumption AS UNSIGNED)) AS total_consumption,

                            SUM(CAST(bill.total - bill.previous_unpaid AS DECIMAL(12,2))) AS total_amount,
                            SUM(CAST(bill.previous_unpaid AS DECIMAL(12,2))) AS arrears,
                            SUM(CAST(bill.discount AS DECIMAL(12,2))) AS discount,

                            MAX(concessioner_accounts.status) AS status,
                            MAX(concessioner_accounts.rate_code) AS rate_code,
                            SUM(CAST(bill.amount AS DECIMAL(12,2))) AS amount
                        ")

                        ->groupBy(
                            'concessioner_accounts.zone',
                            'readings.account_no',
                            'users.name'
                        )

                        ->orderBy('concessioner_accounts.zone', 'ASC')
                        ->orderBy('sequence_no', 'ASC')

                        ->get();

                    /**
                     * ===============================
                     * BUILD SHEETS PER ZONE
                     * ===============================
                     */
                    $result = [];

                    foreach ($query as $row) {

                        $sheetName = 'ZONE ' . $row->zone;

                        $result[$sheetName][] = [
                            'ACCOUNT NUMBER'      => $row->account_no,
                            'NAME'                => $row->concessionaire_name ?? 'N/A',
                            'STATUS'              => $row->status ?? 'N/A',
                            'RATE CODE'           => $row->rate_code ?? 'N/A',
                            'CURRENT READING'     => $row->current_reading ?? 0,
                            '1–30 CONSUMPTION'    => $row->cons_1_30 ?? 0,
                            '31–60 CONSUMPTION'   => $row->cons_31_60 ?? 0,
                            '61–90 CONSUMPTION'   => $row->cons_61_90 ?? 0,
                            'OVER 90 CONSUMPTION' => $row->cons_over_90 ?? 0,
                            'TOTAL CONSUMPTION'   => $row->total_consumption ?? 0,
                            'TOTAL AMOUNT'        => $row->total_amount ?? 0,
                            'ARREARS'             => $row->arrears ?? 0,
                            'DISCOUNT'            => $row->discount ?? 0,
                            'TOTAL DUE'           => $row->amount ?? 0,
                        ];
                    }

                    break;

                    case 'Senior Count':

                    $query = DB::table('discount')
                        ->join(
                            'concessioner_accounts',
                            'concessioner_accounts.account_no',
                            '=',
                            'discount.account_no'
                        )
                        ->where('discount.discount_type_id', 1) // Senior Citizen
                        ->whereNotNull('concessioner_accounts.zone')
                        ->groupBy('concessioner_accounts.zone')
                        ->select(
                            'concessioner_accounts.zone as zone',
                            DB::raw('COUNT(DISTINCT discount.account_no) as senior_count'),
                            DB::raw('SUM(bill.amount) as total_amount') // 👈 CHANGE COLUMN IF NEEDED
                        )
                        ->orderBy('concessioner_accounts.zone', 'asc')
                        ->get();

                    $rows = [];

                    foreach ($query as $row) {
                        $rows[] = [
                            'ZONE'            => $row->zone ?? 'N/A',
                            'NO. OF SENIORS'  => $row->senior_count,
                            'TOTAL AMOUNT'   => number_format($row->total_amount ?? 0, 2),
                        ];
                    }

                    $result[$report] = $rows;
                    break;

                case 'List of Active':

                $query = Bill::query()
                    ->join('readings', 'readings.id', '=', 'bill.reading_id')
                    ->join('concessioner_accounts', 'concessioner_accounts.account_no', '=', 'readings.account_no')
                    ->leftJoin('users', 'users.id', '=', 'concessioner_accounts.user_id')

                    ->where('bill.isPaid', 0)

                    ->when($zone !== 'all', fn ($q) =>
                        $q->where('concessioner_accounts.zone', $zone)
                    )

                    ->selectRaw("
                        concessioner_accounts.zone,
                        concessioner_accounts.status,
                        concessioner_accounts.sequence_no,
                        readings.account_no,
                        users.name AS customer_name,

                        SUM(CASE WHEN MONTH(bill.bill_period_to) = 2 THEN bill.amount_after_due ELSE 0 END) AS feb,
                        SUM(CASE WHEN MONTH(bill.bill_period_to) = 1 THEN bill.amount_after_due ELSE 0 END) AS jan,
                        SUM(CASE WHEN MONTH(bill.bill_period_to) = 12 THEN bill.amount_after_due ELSE 0 END) AS `dec`,
                        SUM(CASE WHEN MONTH(bill.bill_period_to) = 11 THEN bill.amount_after_due ELSE 0 END) AS nov,

                        SUM(
                            CASE
                                WHEN MONTH(bill.bill_period_to) <= 10
                                THEN bill.amount_after_due
                                ELSE 0
                            END
                        ) AS oct_over,

                        SUM(bill.amount_after_due) AS total,

                        SUM(
                            CASE
                                WHEN bill.amount_paid > bill.amount_after_due
                                THEN bill.amount_paid - bill.amount_after_due
                                ELSE 0
                            END
                        ) AS over_payt
                    ")

                    ->groupBy(
                        'concessioner_accounts.zone',
                        'concessioner_accounts.status',
                        'concessioner_accounts.sequence_no',
                        'readings.account_no',
                        'users.name'
                    )

                    ->orderBy('concessioner_accounts.zone', 'ASC')
                    ->orderBy('concessioner_accounts.sequence_no', 'ASC')

                    ->get();

                /**
                 * =====================================
                 * BUILD ACTIVE / INACTIVE + ZONE GROUPS
                 * =====================================
                 */
                $result = [
                    'ACTIVE CONCESSIONAIRES'   => [],
                    'INACTIVE CONCESSIONAIRES' => [],
                ];

                $zoneCounter = [
                    'ACTIVE CONCESSIONAIRES'   => [],
                    'INACTIVE CONCESSIONAIRES' => [],
                ];

                foreach ($query as $row) {

                    $sheet = ($row->status === 'AB')
                        ? 'ACTIVE CONCESSIONAIRES'
                        : 'INACTIVE CONCESSIONAIRES';

                    // Initialize zone counter
                    if (!isset($zoneCounter[$sheet][$row->zone])) {

                        $zoneCounter[$sheet][$row->zone] = 1;

                        // ZONE HEADER ROW
                        $result[$sheet][] = [
                            'NO.'           => "ZONE {$row->zone}",
                            'ACCOUNT NO.'   => '',
                            'CUSTOMER NAME' => '',
                            'FEB'           => '',
                            'JAN'           => '',
                            'DEC'           => '',
                            'NOV'           => '',
                            'OCT & OVER'    => '',
                            'TOTAL'         => '',
                            'OVER PAYT'     => '',
                        ];
                    }

                    // DATA ROW
                    $result[$sheet][] = [
                        'NO.'           => $zoneCounter[$sheet][$row->zone]++,
                        'ACCOUNT NO.'   => $row->account_no,
                        'CUSTOMER NAME' => $row->customer_name ?? 'N/A',
                        'FEB'           => $row->feb ?: 0,
                        'JAN'           => $row->jan ?: 0,
                        'DEC'           => $row->dec ?: 0,
                        'NOV'           => $row->nov ?: 0,
                        'OCT & OVER'    => $row->oct_over ?: 0,
                        'TOTAL'         => $row->total ?: 0,
                        'OVER PAYT'     => $row->over_payt ?: 0,
                    ];
                }

                break;

                case 'List of Inactive':

                $data = ConcessionerAccount::query()
                    ->leftJoin('readings', 'readings.account_no', '=', 'concessioner_accounts.account_no')
                    ->leftJoin('bill', function ($join) use ($startDate, $endDate) {
                        $join->on('bill.reading_id', '=', 'readings.id')
                            ->where('bill.isPaid', 0); // unpaid only

                        // Add date filter inside the join
                        if ($startDate && $endDate) {
                            $join->whereBetween('bill.bill_period_to', [$startDate, $endDate]);
                        }
                    })
                    ->whereIn('concessioner_accounts.status', ['ID', 'IV', 'BL'])
                    ->selectRaw("
                        concessioner_accounts.zone AS zone,
                        COUNT(DISTINCT concessioner_accounts.account_no) AS total_inactive,
                        COALESCE(SUM(CAST(bill.amount AS DECIMAL(12,2))), 0) AS total_unpaid_amount
                    ")
                    ->groupBy('concessioner_accounts.zone')
                    ->orderBy('concessioner_accounts.zone')
                    ->get();

                $rows = [];

                foreach ($data as $row) {
                    $rows[] = [
                        'ZONE'         => 'ZONE ' . $row->zone,
                        'TOTAL'        => $row->total_inactive,
                        'TOTAL AMOUNT' => $row->total_unpaid_amount,
                    ];
                }

                $result[$report] = $rows;
                break;


                case 'Book Summary Report':

                /* ==========================================
                * BOOKS
                * ========================================== */
                $bookList = ConcessionerAccount::query()
                    ->select('zone')
                    ->distinct()
                    ->orderBy('zone')
                    ->pluck('zone')
                    ->map(fn ($z) => "ZONE {$z}")
                    ->values();


                /* ==========================================
                * PROPERTY CATEGORIES
                * ========================================== */
                $categories = [
                    'RESIDENTIAL',
                    'GOVERNMENT',
                    'COMMERCIAL & INDUSTRIAL',
                    'COMMERCIAL A',
                    'COMMERCIAL B',
                    'COMMERCIAL C',
                ];

                /* ==========================================
                * BARANGAYS
                * ========================================== */
                $barangays = [
                    'SAN BASILIO',
                    'BECURAN',
                    'DILA-DILA',
                    'SAN MATIAS',
                    'SAN VICENTE',
                ];

                /* ==========================================
                * MAIN BOOK SUMMARY DATA
                * ========================================== */
                $data = Bill::query()
                    ->join('readings', 'bill.reading_id', '=', 'readings.id')
                    ->join('concessioner_accounts', 'readings.account_no', '=', 'concessioner_accounts.account_no')
                    ->join('property_types', 'concessioner_accounts.rate_code', '=', 'property_types.rate_code')
                    // ✅ Filter by date range
                    ->when($startDate && $endDate, function($q) use ($startDate, $endDate) {
                        $q->whereBetween('bill.bill_period_to', [$startDate, $endDate]);
                    })
                    ->selectRaw("
                        concessioner_accounts.zone AS zone,
                        CASE
                            WHEN property_types.rate_code = 12 THEN 'RESIDENTIAL'
                            WHEN property_types.rate_code = 22 THEN 'GOVERNMENT'
                            WHEN property_types.rate_code = 32 THEN 'COMMERCIAL & INDUSTRIAL'
                            WHEN property_types.rate_code = 62 THEN 'COMMERCIAL A'
                            WHEN property_types.rate_code = 52 THEN 'COMMERCIAL B'
                            WHEN property_types.rate_code = 42 THEN 'COMMERCIAL C'
                        END AS category,
                        COUNT(DISTINCT concessioner_accounts.account_no) AS cnt,
                        SUM(readings.consumption) AS cum,
                        SUM(COALESCE(bill.total - bill.previous_unpaid, 0)) AS amt,
                        SUM(COALESCE(bill.penalty, 0)) AS penalty
                    ")
                    ->groupBy('concessioner_accounts.zone', 'category')
                    ->orderBy('concessioner_accounts.zone')
                    ->get();


                /* ==========================================
                * INITIALIZE BOOK ROWS
                * ========================================== */
                $books = [];

                foreach ($bookList as $book) {
                    $books[$book] = [
                        'ZONE' => $book,
                        'TOTAL AMOUNT PER BOOK' => 0,
                        'TOTAL PENALTY' => 0,
                    ];

                    foreach ($categories as $cat) {
                        $books[$book]["{$cat} - NO. OF CONCESSIONAIRES"] = 0;
                        $books[$book]["{$cat} - CUBIC METER"] = 0;
                        $books[$book]["{$cat} - AMOUNT"] = 0;
                    }
                }

                /* ==========================================
                * POPULATE BOOK DATA
                * ========================================== */
                foreach ($data as $row) {
                    $key = "ZONE {$row->zone}";

                    if (!isset($books[$key])) continue;

                    $books[$key]["{$row->category} - NO. OF CONCESSIONAIRES"] += $row->cnt;
                    $books[$key]["{$row->category} - CUBIC METER"] += $row->cum;
                    $books[$key]["{$row->category} - AMOUNT"] += $row->amt;

                    $books[$key]['TOTAL AMOUNT PER BOOK'] += $row->amt;
                    $books[$key]['TOTAL PENALTY'] += $row->penalty;
                }


                /* ==========================================
                * ZONE SUMMARY (AUTHORITATIVE)
                * ========================================== */
                $zoneSummary = Bill::query()
                    ->join('readings', 'bill.reading_id', '=', 'readings.id')
                    ->join('concessioner_accounts', 'readings.account_no', '=', 'concessioner_accounts.account_no')
                    ->selectRaw('
                        concessioner_accounts.zone AS zone,
                        COUNT(DISTINCT concessioner_accounts.account_no) AS cnt,
                        SUM(COALESCE(bill.total - bill.previous_unpaid, 0)) AS total_paid,
                        SUM(COALESCE(bill.penalty, 0)) AS total_penalty
                    ')
                    ->where(function ($q) {
                        $q->where('bill.hasPenalty', 1);
                    })
                    ->when($zone !== 'all', fn($q) => $q->where('concessioner_accounts.zone', $zone))
                    ->when($startDate && $endDate, function($q) use ($startDate, $endDate) {
                        $q->whereBetween('bill.bill_period_to', [$startDate, $endDate]);
                    })
                    ->groupBy('concessioner_accounts.zone')
                    ->orderBy('concessioner_accounts.zone')
                    ->get();


                /* ==========================================
                * SENIOR CITIZEN PENALTY PER ZONE
                * ========================================== */
                $seniorPenaltyPerZone = Bill::query()
                    ->join('readings', 'bill.reading_id', '=', 'readings.id')
                    ->join('concessioner_accounts', 'readings.account_no', '=', 'concessioner_accounts.account_no')
                    ->join('discount', function($join) {
                        $join->on('discount.account_no', '=', 'concessioner_accounts.account_no')
                            ->where('discount.discount_type_id', 1); // SENIOR only
                    })
                    ->where('bill.penalty', '>', 0)               // only bills with penalty
                    ->when($zone !== 'all', fn($q) => $q->where('concessioner_accounts.zone', $zone))
                    ->when($startDate && $endDate, function($q) use ($startDate, $endDate) {
                        $q->whereBetween('bill.bill_period_to', [$startDate, $endDate]);
                    })
                    ->selectRaw('
                        concessioner_accounts.zone AS zone,
                        COUNT(DISTINCT concessioner_accounts.account_no) AS cnt,
                        SUM(bill.penalty) AS total_penalty
                    ')
                    ->groupBy('concessioner_accounts.zone')
                    ->orderBy('concessioner_accounts.zone')
                    ->get();


                /* ==========================================
                * SENIOR DISCOUNT PER ZONE (FIXED)
                * ========================================== */
                $seniorDiscountPerZone = DB::table('discount')
                    ->join('concessioner_accounts', 'discount.account_no', '=', 'concessioner_accounts.account_no')
                    ->leftJoin('readings', 'concessioner_accounts.account_no', '=', 'readings.account_no')
                    ->leftJoin('bill', function($join) use ($startDate, $endDate) {
                        $join->on('bill.reading_id', '=', 'readings.id');
                        if ($startDate && $endDate) {
                            $join->whereBetween('bill.bill_period_to', [$startDate, $endDate]);
                        }
                    })
                    ->where('discount.discount_type_id', 1) // SENIOR
                    ->whereNotNull('concessioner_accounts.zone')
                    ->when($zone !== 'all', fn($q) => $q->where('concessioner_accounts.zone', $zone))
                    ->selectRaw('
                        concessioner_accounts.zone AS zone,
                        COUNT(DISTINCT discount.account_no) AS cnt,
                        COALESCE(SUM(CAST(bill.discount AS DECIMAL(10,2))), 0) AS total_discount
                    ')
                    ->groupBy('concessioner_accounts.zone')
                    ->orderBy('concessioner_accounts.zone')
                    ->get();

                /* ==========================================
                * TOTAL ACTIVE PER PROPERTY TYPE
                * ========================================== */
                $activePerProperty = \DB::table(function($query) use ($startDate, $endDate) {
                    $query->from('bill')
                        ->join('readings', 'bill.reading_id', '=', 'readings.id')
                        ->join('concessioner_accounts', 'readings.account_no', '=', 'concessioner_accounts.account_no')
                        ->where('concessioner_accounts.status', 'AB')
                        ->when($startDate && $endDate, function($q) use ($startDate, $endDate) {
                            $q->whereBetween('readings.created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
                        })
                        ->selectRaw('
                            concessioner_accounts.account_no,
                            concessioner_accounts.rate_code,
                            SUM(readings.consumption) AS account_consumption,
                            SUM(COALESCE(bill.total - bill.previous_unpaid, 0)) AS account_amount
                        ')
                        ->groupBy('concessioner_accounts.account_no', 'concessioner_accounts.rate_code');
                }, 'account_summary')
                ->join('property_types as pt', 'account_summary.rate_code', '=', 'pt.rate_code')
                ->selectRaw("
                    CASE
                        WHEN pt.rate_code = 12 THEN 'RESIDENTIAL'
                        WHEN pt.rate_code = 22 THEN 'GOVERNMENT'
                        WHEN pt.rate_code = 32 THEN 'COMMERCIAL & INDUSTRIAL'
                        WHEN pt.rate_code = 62 THEN 'COMMERCIAL A'
                        WHEN pt.rate_code = 52 THEN 'COMMERCIAL B'
                        WHEN pt.rate_code = 42 THEN 'COMMERCIAL C'
                    END AS property_type,
                    COUNT(DISTINCT account_summary.account_no) AS cnt,
                    SUM(account_consumption) AS cum,
                    SUM(account_amount) AS amt
                ")
                ->groupBy('pt.rate_code')
                ->get();

                $totalArrearsPerZone = \DB::table(function ($query) use ($endDate) {
                    $query->from('bill as b')
                        ->join('readings as r', 'b.reading_id', '=', 'r.id')
                        ->join('concessioner_accounts as c', 'c.account_no', '=', 'r.account_no')
                        ->where('b.bill_period_to', '<=', $endDate)
                        ->whereIn('c.status', ['ID', 'IV', 'BL']) // ✅ inactive only
                        ->whereRaw('CAST(COALESCE(b.previous_unpaid, 0) AS DECIMAL(10,2)) > 0')
                        ->selectRaw('
                            r.account_no,
                            MAX(b.bill_period_to) AS last_period
                        ')
                        ->groupBy('r.account_no');
                }, 'last_bill')
                ->join('readings as r', 'r.account_no', '=', 'last_bill.account_no')
                ->join('bill as b', function ($join) {
                    $join->on('b.reading_id', '=', 'r.id')
                        ->on('b.bill_period_to', '=', 'last_bill.last_period');
                })
                ->join('concessioner_accounts as c', 'c.account_no', '=', 'r.account_no')
                ->selectRaw('
                    c.zone AS zone,
                    COUNT(DISTINCT c.account_no) AS cnt,
                    SUM(CAST(b.previous_unpaid AS DECIMAL(10,2))) AS total_arrears
                ')
                ->groupBy('c.zone')
                ->orderBy('c.zone')
                ->get();


                $inactiveCountsPerZone = \DB::table('concessioner_accounts')
                    ->whereIn('status', ['ID', 'IV', 'BL'])
                    ->selectRaw('
                        zone,
                        COUNT(DISTINCT account_no) AS inactive_cnt
                    ')
                    ->groupBy('zone')
                    ->pluck('inactive_cnt', 'zone');


                    /* ==========================================
                * DISCONNECTED PER ZONE
                * ========================================== */
                $disconnectedPerZone = ConcessionerAccount::query()
                    ->whereIn('status', ['IV', 'ID', 'BL'])  // only disconnected statuses
                    ->groupBy('zone')                        // group by zone
                    ->selectRaw('zone, COUNT(DISTINCT account_no) AS disconnected_cnt')
                    ->pluck('disconnected_cnt', 'zone');     // return as [zone => count]



                /* ==========================================
                * ACTIVE PER BARANGAY
                * ========================================== */
                $activePerBarangay = [];

                foreach ($barangays as $brgy) {
                    $activePerBarangay[$brgy] = ConcessionerAccount::query()
                        ->where('status', 'AB')
                        ->where('address', 'LIKE', "%{$brgy}%")
                        ->count();
                }

                /* ==========================================
                * DISCONNECTED
                * ========================================== */
                $disconnected = ConcessionerAccount::whereIn('status', [3, 5])->count();

                /* ==========================================
                * FINAL ROWS
                * ========================================== */
                $rows = array_values($books);
                /* ==========================================
                * PENALTY PER ZONE
                * ========================================== */
                $rows[] = [
                    'BOOK' => '--- PENALTY PER ZONE ---',
                    'NO. OF CONCESSIONAIRES' => null,
                    'DISCONNECTED' => null,
                    'TOTAL AMOUNT' => null,
                ];

                foreach ($zoneSummary as $row) {
                    $rows[] = [
                        'BOOK' => "ZONE {$row->zone}",
                        'NO. OF CONCESSIONAIRES' => $row->cnt,
                        'DISCONNECTED' => $disconnectedPerZone[$row->zone] ?? 0,
                        'TOTAL AMOUNT' => $row->total_penalty,
                    ];
                }


                $rows[] = [
                    'BOOK' => '--- TOTAL ARREARS (INACTIVE ACCOUNTS ONLY) ---',
                    'INACTIVE CONCESSIONAIRES' => null,
                    'WITH ARREARS' => null,
                    'TOTAL AMOUNT' => null,
                ];

                foreach ($totalArrearsPerZone as $row) {
                    $rows[] = [
                        'BOOK' => "ZONE {$row->zone}",
                        'INACTIVE CONCESSIONAIRES' => $inactiveCountsPerZone[$row->zone] ?? 0,
                        'WITH ARREARS' => $row->cnt,
                        'TOTAL AMOUNT' => number_format($row->total_arrears, 2),
                    ];
                }


                /* ==========================================
                * SENIOR DISCOUNT PER ZONE (DISCOUNT TABLE)
                * ========================================== */
                $rows[] = [
                    'BOOK' => '--- SENIOR DISCOUNT PER ZONE ---',
                    'NO. OF CONCESSIONAIRES' => null,
                    'TOTAL AMOUNT' => null,
                ];

                foreach ($seniorDiscountPerZone as $row) {
                    $rows[] = [
                        'BOOK' => "ZONE {$row->zone}",
                        'NO. OF CONCESSIONAIRES' => $row->cnt,
                        'TOTAL AMOUNT' => $row->total_discount,
                    ];
                }



                /* ==========================================
                * SENIOR CITIZEN PENALTY PER ZONE
                * ========================================== */
                $rows[] = [
                    'BOOK' => '--- SENIOR CITIZEN PENALTY PER ZONE ---',
                    'NO. OF CONCESSIONAIRES' => null,
                    'TOTAL AMOUNT' => null,
                ];

                foreach ($seniorPenaltyPerZone as $row) {
                    $rows[] = [
                        'BOOK' => "ZONE {$row->zone}",
                        'NO. OF CONCESSIONAIRES' => $row->cnt,
                        'TOTAL AMOUNT' => $row->total_penalty,
                    ];
                }

                /* ==========================================
                * ACTIVE PER PROPERTY TYPE
                * ========================================== */
                $rows[] = [
                    'BOOK' => '--- ACTIVE PER PROPERTY TYPE ---',
                    'NO. OF CONCESSIONAIRES' => null,
                    'CUBIC METER' => null,
                    'TOTAL AMOUNT' => null,
                ];

                foreach ($activePerProperty as $row) {
                    $rows[] = [
                        'BOOK' => $row->property_type,
                        'NO. OF CONCESSIONAIRES' => $row->cnt,
                        'CUBIC METER' => $row->cum,
                        'TOTAL AMOUNT' => $row->amt,
                    ];
                }

                /* ==========================================
                * ACTIVE PER BARANGAY
                * ========================================== */
                $rows[] = [
                    'BOOK' => '--- ACTIVE PER BARANGAY ---',
                    'NO. OF CONCESSIONAIRES' => null,
                ];

                foreach ($activePerBarangay as $brgy => $count) {
                    $rows[] = [
                        'BOOK' => $brgy,
                        'NO. OF CONCESSIONAIRES' => $count,
                    ];
                }

                /* ==========================================
                * DISCONNECTED
                * ========================================== */
                $rows[] = [
                    'BOOK' => '--- TOTAL DISCONNECTED ---',
                    'NO. OF CONCESSIONAIRES' => $disconnected,
                ];

                $result[$report] = $rows;
                break;


                default:
                    $result[$report] = [];
                    break;
            }
        }

        return $result;
    }

    protected function createFile(string $reportName, array $rows, string $format): string
{
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(substr($reportName, 0, 31));

    $headers = !empty($rows) ? array_keys($rows[0]) : [];

    // ✅ "Plain Sheet" layout
    if ($format === 'plain') {
        // Title row
        $sheet->setCellValue('A1', strtoupper($reportName));
        $sheet->mergeCells('A1:E1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');
        $sheet->getRowDimension('1')->setRowHeight(25);

        // Column headers start on row 3
        if (!empty($headers)) {
            $sheet->fromArray([$headers], null, 'A3');
            $lastHeaderCol = chr(64 + count($headers));
            $sheet->getStyle("A3:{$lastHeaderCol}3")->getFont()->setBold(true);
            $sheet->getStyle("A3:{$lastHeaderCol}3")
                ->getAlignment()->setHorizontal('center')->setVertical('center');
        } else {
            $sheet->setCellValue('A3', 'No Data Found');
        }

        // Data rows start at row 4
        if (!empty($rows)) {
            $sheet->fromArray($rows, null, 'A4');
        }

        // ✅ Clean plain layout
        $sheet->getDefaultColumnDimension()->setWidth(18);
        $sheet->getDefaultRowDimension()->setRowHeight(20);
        $sheet->getStyle('A:Z')->getAlignment()->setHorizontal('center');

        // ✅ Add thin borders for readability
        if (!empty($headers)) {
            $lastRow = count($rows) + 3;
            $lastCol = chr(64 + count($headers));
            $sheet->getStyle("A3:{$lastCol}{$lastRow}")
                ->getBorders()->getAllBorders()->setBorderStyle(
                    \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN
                );
            // ✅ Add gray background for header
            $sheet->getStyle("A3:{$lastCol}3")->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setARGB('DDDDDD');
        }
    }

    // ✅ Regular Excel or CSV export
    else {
        if (!empty($rows)) {
            $sheet->fromArray([$headers], null, 'A1');
            $sheet->fromArray($rows, null, 'A2');
        } else {
            $sheet->setCellValue('A1', 'No Data Available');
        }
    }

    // ✅ Ensure reports folder exists
    $reportsPath = storage_path('app/reports');
    if (!file_exists($reportsPath)) {
        mkdir($reportsPath, 0777, true);
    }

    // ✅ Safe filename and correct extension
    $safeName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $reportName);
    $extension = ($format === 'csv') ? 'csv' : 'xlsx';
    $fileName = "{$safeName}_" . now()->format('Ymd_His') . ".{$extension}";
    $filePath = "{$reportsPath}/{$fileName}";

    // ✅ Select proper writer
    if ($format === 'csv') {
        // CSV can only handle a single sheet; ensure combined mode didn’t break this
        if ($spreadsheet->getSheetCount() > 1) {
            $spreadsheet->setActiveSheetIndex(0);
        }
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Csv($spreadsheet);
    } else {
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    }

    // ✅ Safety: Ensure at least 1 sheet has visible data
    if ($sheet->getHighestDataRow() === 0) {
        $sheet->setCellValue('A1', 'No data available');
    }

    $writer->save($filePath);

    return $filePath;
}


    protected function sanitizeFilename($name)
    {
        return preg_replace('/[^A-Za-z0-9_\-]/', '_', strtolower($name));
    }
}
