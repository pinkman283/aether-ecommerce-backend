<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\CourierSettlement;
use App\Models\CourierSettlementItem;
use App\Models\Shipment;
use App\Services\AccountingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AdminCourierSettlementController extends Controller
{
    /**
     * List courier settlements with filtering and summary metrics.
     */
    public function index(Request $request): JsonResponse
    {
        $query = CourierSettlement::with([
            'bankAccount:id,account_name,bank_name,account_number',
            'reconciledByUser:id,name,email',
            'journalEntry:id,entry_number,status',
        ]);

        if ($request->filled('provider')) {
            $query->where('provider', $request->provider);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('settlement_number', 'LIKE', "%{$search}%")
                    ->orWhere('notes', 'LIKE', "%{$search}%");
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('settlement_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('settlement_date', '<=', $request->date_to);
        }

        $perPage = max(10, min(100, (int) $request->query('per_page', 20)));
        $settlements = $query->orderBy('settlement_date', 'desc')->orderBy('id', 'desc')->paginate($perPage);

        $stats = [
            'total_settlements' => CourierSettlement::count(),
            'pending_count' => CourierSettlement::where('status', 'pending')->count(),
            'reconciled_count' => CourierSettlement::where('status', 'reconciled')->count(),
            'total_cod_collected' => (float) CourierSettlement::sum('total_cod_collected'),
            'total_delivery_fees' => (float) CourierSettlement::sum('delivery_fees'),
            'total_actual_payout' => (float) CourierSettlement::sum('actual_payout'),
            'total_variance' => (float) CourierSettlement::sum('variance'),
        ];

        return response()->json([
            'status' => 'success',
            'data' => $settlements->items(),
            'meta' => [
                'current_page' => $settlements->currentPage(),
                'last_page' => $settlements->lastPage(),
                'per_page' => $settlements->perPage(),
                'total' => $settlements->total(),
            ],
            'stats' => $stats,
        ]);
    }

    /**
     * Show settlement details with line items and shipment matching.
     */
    public function show(int $id): JsonResponse
    {
        $settlement = CourierSettlement::with([
            'bankAccount',
            'journalEntry.lines.account',
            'reconciledByUser:id,name,email',
            'items.shipment.order:id,order_number,customer_name,customer_phone,total_amount',
            'items.order:id,order_number,customer_name,customer_phone,total_amount',
        ])->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $settlement,
        ]);
    }

    /**
     * Create a new settlement statement batch with line items.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'provider' => 'required|string|in:steadfast,pathao,redx,paperfly,sundarban,custom',
            'settlement_date' => 'required|date',
            'settlement_number' => 'nullable|string|max:50',
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.consignment_id' => 'nullable|string',
            'items.*.tracking_code' => 'nullable|string',
            'items.*.cod_collected' => 'required|numeric|min:0',
            'items.*.delivery_fee' => 'nullable|numeric|min:0',
            'items.*.rto_fee' => 'nullable|numeric|min:0',
            'items.*.cod_fee' => 'nullable|numeric|min:0',
            'items.*.other_fee' => 'nullable|numeric|min:0',
            'items.*.actual_payout' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $settlement = DB::transaction(function () use ($request) {
            $provider = $request->provider;
            $settlementDate = Carbon::parse($request->settlement_date);

            $settlementNumber = $request->settlement_number;
            if (empty($settlementNumber)) {
                $prefix = 'SET-' . strtoupper(substr($provider, 0, 3)) . '-' . $settlementDate->format('Ymd');
                $countToday = CourierSettlement::where('settlement_number', 'LIKE', "{$prefix}-%")->count();
                $settlementNumber = sprintf('%s-%03d', $prefix, $countToday + 1);
            }

            $totalCod = 0.00;
            $totalDeliveryFees = 0.00;
            $totalRtoFees = 0.00;
            $totalOtherFees = 0.00;
            $totalActualPayout = 0.00;

            $itemsToCreate = [];

            foreach ($request->items as $row) {
                $cid = trim($row['consignment_id'] ?? '');
                $track = trim($row['tracking_code'] ?? '');

                $cod = (float) ($row['cod_collected'] ?? 0.00);
                $delFee = (float) ($row['delivery_fee'] ?? 0.00);
                $rtoFee = (float) ($row['rto_fee'] ?? 0.00);
                $codFee = (float) ($row['cod_fee'] ?? 0.00);
                $otherFee = (float) ($row['other_fee'] ?? 0.00);
                $net = round($cod - ($delFee + $rtoFee + $codFee + $otherFee), 2);

                $totalCod += $cod;
                $totalDeliveryFees += ($delFee + $codFee);
                $totalRtoFees += $rtoFee;
                $totalOtherFees += $otherFee;

                // Match shipment
                $shipment = null;
                if (!empty($cid)) {
                    $shipment = Shipment::where('provider', $provider)->where('consignment_id', $cid)->first();
                }
                if (!$shipment && !empty($track)) {
                    $shipment = Shipment::where('provider', $provider)->where('tracking_code', $track)->first();
                }

                $status = 'matched';
                if (!$shipment) {
                    $status = 'unmatched';
                } elseif (abs((float)$shipment->cod_amount - $cod) > 1.00) {
                    $status = 'variance';
                }

                $itemsToCreate[] = [
                    'shipment' => $shipment,
                    'consignment_id' => $cid ?: $shipment?->consignment_id,
                    'tracking_code' => $track ?: $shipment?->tracking_code,
                    'order_id' => $shipment?->order_id,
                    'cod_collected' => $cod,
                    'delivery_fee' => $delFee,
                    'rto_fee' => $rtoFee,
                    'cod_fee' => $codFee,
                    'other_fee' => $otherFee,
                    'net_payout' => $net,
                    'status' => $status,
                    'notes' => $row['notes'] ?? null,
                ];
            }

            $expectedPayout = round($totalCod - ($totalDeliveryFees + $totalRtoFees + $totalOtherFees), 2);
            $actualPayout = $request->filled('actual_payout') ? (float) $request->actual_payout : $expectedPayout;
            $variance = round($expectedPayout - $actualPayout, 2);

            $settlement = CourierSettlement::create([
                'settlement_number' => $settlementNumber,
                'provider' => $provider,
                'settlement_date' => $settlementDate->toDateString(),
                'total_cod_collected' => $totalCod,
                'delivery_fees' => $totalDeliveryFees,
                'return_fees' => $totalRtoFees,
                'other_deductions' => $totalOtherFees,
                'expected_payout' => $expectedPayout,
                'actual_payout' => $actualPayout,
                'variance' => $variance,
                'bank_account_id' => $request->bank_account_id,
                'status' => abs($variance) > 0.01 ? 'disputed' : 'pending',
                'notes' => $request->notes,
            ]);

            foreach ($itemsToCreate as $itemData) {
                $shipment = $itemData['shipment'];
                unset($itemData['shipment']);
                $itemData['courier_settlement_id'] = $settlement->id;
                $itemData['shipment_id'] = $shipment?->id;

                $createdItem = CourierSettlementItem::create($itemData);

                if ($shipment) {
                    $shipment->update([
                        'courier_settlement_id' => $settlement->id,
                        'collected_amount' => $createdItem->cod_collected,
                        'remitted_amount' => $createdItem->net_payout,
                    ]);
                }
            }

            return $settlement;
        });

        AuditLog::log(
            $request->user()?->id,
            'courier.settlement_created',
            'CourierSettlement',
            $settlement->id,
            "Created courier settlement batch #{$settlement->settlement_number} ({$settlement->provider}) with expected payout ৳{$settlement->expected_payout}",
            null,
            $settlement->toArray()
        );

        return response()->json([
            'status' => 'success',
            'message' => "Courier settlement batch #{$settlement->settlement_number} created successfully.",
            'data' => $settlement->load('items'),
        ], 201);
    }

    /**
     * Reconcile & finalize settlement with bank account and post journal entry.
     */
    public function reconcile(Request $request, int $id): JsonResponse
    {
        $settlement = CourierSettlement::with('items.shipment')->findOrFail($id);

        if ($settlement->status === 'reconciled') {
            return response()->json([
                'status' => 'error',
                'message' => 'This settlement statement is already reconciled.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'bank_account_id' => 'required|exists:bank_accounts,id',
            'actual_payout' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $bankAccountId = (int) $request->bank_account_id;
        $actualPayout = $request->filled('actual_payout') 
            ? (float) $request->actual_payout 
            : (float) $settlement->actual_payout;

        $variance = round((float) $settlement->expected_payout - $actualPayout, 2);

        DB::transaction(function () use ($settlement, $bankAccountId, $actualPayout, $variance, $request) {
            $settlement->update([
                'bank_account_id' => $bankAccountId,
                'actual_payout' => $actualPayout,
                'variance' => $variance,
                'status' => 'reconciled',
                'reconciled_by_user_id' => $request->user()?->id,
                'reconciled_at' => now(),
                'notes' => $request->notes ? ($settlement->notes ? $settlement->notes . "\n" . $request->notes : $request->notes) : $settlement->notes,
            ]);

            // Post double entry journal
            $journalEntry = AccountingService::postCourierSettlementBatch($settlement, $bankAccountId);

            if ($journalEntry) {
                $settlement->update(['journal_entry_id' => $journalEntry->id]);
            }

            // Mark matched shipments as settled
            foreach ($settlement->items as $item) {
                if ($item->shipment) {
                    $item->shipment->update([
                        'settlement_status' => 'settled',
                        'remitted_amount' => $item->net_payout,
                    ]);

                    if ($item->order) {
                        $item->order->update([
                            'amount_remitted_merchant' => round((float) ($item->order->amount_remitted_merchant ?? 0) + $item->net_payout, 2),
                        ]);
                    }
                }
            }
        });

        AuditLog::log(
            $request->user()?->id,
            'courier.settlement_reconciled',
            'CourierSettlement',
            $settlement->id,
            "Reconciled courier settlement #{$settlement->settlement_number} to Bank Account ID {$bankAccountId}",
            null,
            ['actual_payout' => $actualPayout, 'variance' => $variance]
        );

        return response()->json([
            'status' => 'success',
            'message' => "Settlement #{$settlement->settlement_number} reconciled and journal entry posted.",
            'data' => $settlement->fresh(['bankAccount', 'journalEntry.lines.account', 'reconciledByUser']),
        ]);
    }

    /**
     * Parse courier settlement CSV file and return structured preview.
     */
    public function importCsv(Request $request): JsonResponse
    {
        $request->validate([
            'provider' => 'required|string|in:steadfast,pathao,redx,custom',
            'file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        $file = $request->file('file');
        $handle = fopen($file->getRealPath(), 'r');
        if (!$handle) {
            return response()->json(['status' => 'error', 'message' => 'Unable to read uploaded CSV.'], 422);
        }

        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            return response()->json(['status' => 'error', 'message' => 'Uploaded CSV file is empty.'], 422);
        }

        $header = array_map(fn($h) => strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '', str_replace([' ', '-'], '_', $h)))), $header);

        $parsedRows = [];
        $totalCod = 0.0;
        $totalFees = 0.0;

        while (($row = fgetcsv($handle)) !== false) {
            if (empty(array_filter($row))) continue;
            $data = @array_combine($header, $row);
            if (!$data) continue;

            $cid = $data['consignment_id'] ?? $data['cid'] ?? $data['consignment'] ?? null;
            $track = $data['tracking_code'] ?? $data['tracking'] ?? $data['invoice'] ?? $data['order_id'] ?? null;
            $cod = (float) ($data['cod_collected'] ?? $data['collected_amount'] ?? $data['cod_amount'] ?? $data['cod'] ?? 0);
            $delFee = (float) ($data['delivery_fee'] ?? $data['delivery_charge'] ?? $data['fee'] ?? 0);
            $codFee = (float) ($data['cod_fee'] ?? $data['cod_charge'] ?? 0);
            $rtoFee = (float) ($data['rto_fee'] ?? $data['return_fee'] ?? $data['return_charge'] ?? 0);

            $net = round($cod - ($delFee + $codFee + $rtoFee), 2);
            $totalCod += $cod;
            $totalFees += ($delFee + $codFee + $rtoFee);

            $parsedRows[] = [
                'consignment_id' => $cid,
                'tracking_code' => $track,
                'cod_collected' => $cod,
                'delivery_fee' => $delFee,
                'cod_fee' => $codFee,
                'rto_fee' => $rtoFee,
                'other_fee' => 0.00,
                'net_payout' => $net,
            ];
        }
        fclose($handle);

        return response()->json([
            'status' => 'success',
            'summary' => [
                'row_count' => count($parsedRows),
                'total_cod' => round($totalCod, 2),
                'total_fees' => round($totalFees, 2),
                'net_payout' => round($totalCod - $totalFees, 2),
            ],
            'items' => $parsedRows,
        ]);
    }
}
