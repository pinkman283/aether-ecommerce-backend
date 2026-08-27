<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\InventoryCostingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminGoodsReceiptController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'goods_receipts.manage');

        $query = GoodsReceipt::with(['purchaseOrder', 'vendor', 'receivedByUser'])
            ->withCount('items')
            ->latest();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('receipt_number', 'like', "%{$search}%")
                  ->orWhereHas('purchaseOrder', function ($pq) use ($search) {
                      $pq->where('po_number', 'like', "%{$search}%");
                  })
                  ->orWhereHas('vendor', function ($vq) use ($search) {
                      $vq->where('name', 'like', "%{$search}%")
                         ->orWhere('company_name', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('received_date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('received_date', '<=', $request->input('date_to'));
        }

        $perPage = (int) $request->input('per_page', 20);
        $receipts = $query->paginate($perPage);

        return response()->json($receipts);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'goods_receipts.manage');

        $receipt = GoodsReceipt::with([
            'purchaseOrder.items',
            'vendor',
            'receivedByUser',
            'items.product.primaryImage',
            'items.variant',
            'items.purchaseOrderItem',
        ])->findOrFail($id);

        return response()->json($receipt);
    }

    public function store(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'goods_receipts.manage');

        $validated = $request->validate([
            'purchase_order_id' => 'required|exists:purchase_orders,id',
            'received_date' => 'required|date',
            'notes' => 'nullable|string|max:5000',
            'items' => 'required|array|min:1',
            'items.*.purchase_order_item_id' => 'required|exists:purchase_order_items,id',
            'items.*.quantity_received' => 'required|integer|min:0',
            'items.*.quantity_damaged' => 'nullable|integer|min:0',
            'items.*.quantity_rejected' => 'nullable|integer|min:0',
        ]);

        $po = PurchaseOrder::with('items')->findOrFail($validated['purchase_order_id']);

        if (!in_array($po->status, ['approved', 'partially_received'])) {
            return response()->json([
                'message' => "Goods can only be received against an 'approved' or 'partially_received' PO. Current status: {$po->status}",
            ], 422);
        }

        return DB::transaction(function () use ($request, $validated, $po) {
            $receiptNumber = 'GRN-' . date('Y') . '-' . strtoupper(Str::random(6));

            $receipt = GoodsReceipt::create([
                'receipt_number' => $receiptNumber,
                'purchase_order_id' => $po->id,
                'vendor_id' => $po->vendor_id,
                'received_by_user_id' => $request->user()->id,
                'received_date' => $validated['received_date'],
                'notes' => $validated['notes'] ?? null,
            ]);

            $totalReceivedInBatch = 0;

            foreach ($validated['items'] as $itemInput) {
                $poItem = PurchaseOrderItem::where('id', $itemInput['purchase_order_item_id'])
                    ->where('purchase_order_id', $po->id)
                    ->firstOrFail();

                $qtyReceived = (int) $itemInput['quantity_received'];
                $qtyDamaged = (int) ($itemInput['quantity_damaged'] ?? 0);
                $qtyRejected = (int) ($itemInput['quantity_rejected'] ?? 0);

                if ($qtyReceived <= 0 && $qtyDamaged <= 0 && $qtyRejected <= 0) {
                    continue;
                }

                $totalCost = $qtyReceived * (float) $poItem->unit_cost;

                $grItem = GoodsReceiptItem::create([
                    'goods_receipt_id' => $receipt->id,
                    'purchase_order_item_id' => $poItem->id,
                    'product_id' => $poItem->product_id,
                    'variant_id' => $poItem->variant_id,
                    'quantity_received' => $qtyReceived,
                    'quantity_damaged' => $qtyDamaged,
                    'quantity_rejected' => $qtyRejected,
                    'unit_cost' => $poItem->unit_cost,
                    'total_cost' => $totalCost,
                ]);

                // Update PO Item cumulative quantities
                $poItem->increment('quantity_received', $qtyReceived);
                if ($qtyDamaged > 0) $poItem->increment('quantity_damaged', $qtyDamaged);
                if ($qtyRejected > 0) $poItem->increment('quantity_rejected', $qtyRejected);

                // Call FIFO Costing service to create cost layers, update physical stock & log auditable ledger movement
                if ($qtyReceived > 0) {
                    InventoryCostingService::receiveGoods(
                        $grItem,
                        $request->user(),
                        "Received shipment via GRN #{$receiptNumber} for PO #{$po->po_number}"
                    );
                    $totalReceivedInBatch += $qtyReceived;
                }
            }

            // Check if PO is completely received or partially received
            $po->refresh();
            $allReceived = true;
            foreach ($po->items as $item) {
                if ($item->quantity_received < $item->quantity_ordered) {
                    $allReceived = false;
                    break;
                }
            }

            $newPoStatus = $allReceived ? 'received' : 'partially_received';
            $po->update(['status' => $newPoStatus]);

            AuditLog::log(
                $request->user(),
                'goods_receipt.created',
                'GoodsReceipt',
                $receipt->id,
                "Received shipment GRN #{$receipt->receipt_number} for PO #{$po->po_number} ({$totalReceivedInBatch} units accepted into FIFO inventory layers). PO Status is now {$newPoStatus}."
            );

            return response()->json([
                'message' => "Goods receipt note {$receipt->receipt_number} processed successfully.",
                'goods_receipt' => $receipt->load(['items.product', 'vendor', 'purchaseOrder']),
                'purchase_order_status' => $newPoStatus,
            ], 201);
        });
    }
}
