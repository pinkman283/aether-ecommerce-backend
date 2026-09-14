<?php

namespace App\Services;

use App\Models\InventoryCostLayer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\OrderReturnItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\StoreCreditAccount;
use App\Models\StoreCreditTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OrderReturnService
{
    /**
     * Create a new Return or RTO record.
     */
    public function createReturn(Order $order, array $data, ?User $actor = null): OrderReturn
    {
        return DB::transaction(function () use ($order, $data, $actor) {
            $returnType = $data['return_type'] ?? 'customer_return'; // rto, customer_return, failed_delivery, partial_rejection
            $shipment = $order->latestShipment;

            // Generate unique return number: RET-YYYYMMDD-XXXX
            $datePrefix = 'RET-' . now()->format('Ymd');
            $todayCount = OrderReturn::where('return_number', 'LIKE', "{$datePrefix}-%")->count();
            $returnNumber = sprintf('%s-%04d', $datePrefix, $todayCount + 1);

            $status = in_array($returnType, ['rto', 'failed_delivery']) ? 'in_transit' : 'initiated';
            $orderReturnStatus = in_array($returnType, ['rto', 'failed_delivery']) ? 'rto_in_transit' : 'return_requested';

            $amountCollectedCourier = isset($data['amount_collected_courier']) 
                ? (float) $data['amount_collected_courier'] 
                : (float) ($order->amount_collected_courier ?? 0.00);

            $courierDeliveryFee = isset($data['courier_delivery_fee'])
                ? (float) $data['courier_delivery_fee']
                : (float) ($shipment?->courier_charge ?? 0.00);

            $courierRtoFee = isset($data['courier_rto_fee'])
                ? (float) $data['courier_rto_fee']
                : (float) ($shipment?->rto_charge ?? 0.00);

            $orderReturn = OrderReturn::create([
                'return_number' => $returnNumber,
                'order_id' => $order->id,
                'shipment_id' => $shipment?->id,
                'customer_id' => $order->user_id,
                'return_type' => $returnType,
                'status' => $status,
                'return_reason' => $data['return_reason'] ?? ($returnType === 'rto' ? 'Courier Return To Origin (Customer Refusal/Unreachable)' : 'Customer requested return'),
                'order_total_snapshot' => (float) $order->total_amount,
                'product_subtotal_snapshot' => (float) $order->subtotal,
                'shipping_charge_snapshot' => (float) $order->shipping_amount,
                'amount_collected_courier' => $amountCollectedCourier,
                'amount_expected_from_customer' => (float) ($data['amount_expected_from_customer'] ?? 0.00),
                'refund_amount' => 0.00,
                'refund_method' => $data['refund_method'] ?? 'none',
                'refund_status' => 'none',
                'courier_delivery_fee' => $courierDeliveryFee,
                'courier_rto_fee' => $courierRtoFee,
                'courier_tracking_code' => $data['courier_tracking_code'] ?? $shipment?->tracking_code,
                'inspection_status' => 'pending',
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => $actor?->id,
            ]);

            // Add Return Line Items
            $itemsData = $data['items'] ?? [];

            if (empty($itemsData)) {
                // If items are not explicitly passed (e.g. for full parcel RTO), populate all order items
                foreach ($order->items as $orderItem) {
                    $orderReturn->items()->create([
                        'order_item_id' => $orderItem->id,
                        'product_id' => $orderItem->product_id,
                        'variant_id' => $orderItem->variant_id,
                        'quantity_returned' => $orderItem->quantity,
                        'return_reason' => $orderReturn->return_reason,
                        'condition' => 'unopened',
                        'qc_status' => 'pending',
                        'disposition' => 'pending',
                        'unit_price' => (float) $orderItem->unit_price,
                        'refund_unit_price' => (float) $orderItem->unit_price,
                        'refund_subtotal' => 0.00,
                    ]);
                }
            } else {
                foreach ($itemsData as $itemRow) {
                    $orderItemId = $itemRow['order_item_id'] ?? null;
                    $orderItem = $orderItemId ? OrderItem::find($orderItemId) : null;
                    $qty = max(1, (int) ($itemRow['quantity_returned'] ?? 1));
                    $unitPrice = $orderItem ? (float) $orderItem->unit_price : (float) ($itemRow['unit_price'] ?? 0);

                    $orderReturn->items()->create([
                        'order_item_id' => $orderItem?->id,
                        'product_id' => $orderItem?->product_id ?? ($itemRow['product_id'] ?? null),
                        'variant_id' => $orderItem?->variant_id ?? ($itemRow['variant_id'] ?? null),
                        'quantity_returned' => $qty,
                        'return_reason' => $itemRow['return_reason'] ?? $orderReturn->return_reason,
                        'condition' => $itemRow['condition'] ?? 'unopened',
                        'qc_status' => 'pending',
                        'disposition' => 'pending',
                        'unit_price' => $unitPrice,
                        'refund_unit_price' => $unitPrice,
                        'refund_subtotal' => 0.00,
                        'qc_notes' => $itemRow['qc_notes'] ?? null,
                    ]);
                }
            }

            // Update order return status and courier amount
            $order->update([
                'return_status' => $orderReturnStatus,
                'amount_collected_courier' => $amountCollectedCourier,
            ]);

            return $orderReturn->load(['items.product', 'items.orderItem', 'order', 'shipment']);
        });
    }

    /**
     * Mark return parcel physically received at warehouse.
     */
    public function receiveReturn(OrderReturn $return, array $data = [], ?User $actor = null): OrderReturn
    {
        return DB::transaction(function () use ($return, $data, $actor) {
            $return->update([
                'status' => 'received',
                'received_at' => now(),
                'inspection_status' => 'pending',
                'notes' => !empty($data['notes']) ? ($return->notes ? $return->notes . "\n" . $data['notes'] : $data['notes']) : $return->notes,
                'courier_tracking_code' => $data['courier_tracking_code'] ?? $return->courier_tracking_code,
            ]);

            $return->order->update([
                'return_status' => 'received',
            ]);

            return $return->fresh(['items.product', 'order', 'shipment']);
        });
    }

    /**
     * Perform QC Inspection and inventory disposition.
     * Restocks sellable items with new FIFO cost layer.
     * Quarantines/writes off damaged items without restocking sellable quantity.
     */
    public function inspectAndDispose(OrderReturn $return, array $itemsData, ?User $actor = null): OrderReturn
    {
        return DB::transaction(function () use ($return, $itemsData, $actor) {
            $totalRestockedCost = 0.00;
            $totalLossCost = 0.00;
            $totalRefundEligible = 0.00;

            $hasDamage = false;
            $hasPass = false;

            foreach ($itemsData as $itemRow) {
                $returnItemId = $itemRow['id'] ?? null;
                $returnItem = $return->items()->find($returnItemId);
                if (!$returnItem) {
                    continue;
                }

                $disposition = $itemRow['disposition'] ?? 'restock_sellable'; // restock_sellable, quarantine_damaged, write_off_loss, return_to_customer
                $condition = $itemRow['condition'] ?? $returnItem->condition;
                $qcStatus = $itemRow['qc_status'] ?? ($disposition === 'restock_sellable' ? 'passed' : 'damaged');
                $qcNotes = $itemRow['qc_notes'] ?? null;

                $qtyReturned = $returnItem->quantity_returned;
                $restockedQty = 0;
                $damagedQty = 0;
                $writeoffQty = 0;

                $orderItem = $returnItem->orderItem;
                $unitCost = 0.00;
                if ($orderItem) {
                    if ($orderItem->costLayers()->exists()) {
                        $unitCost = round((float) $orderItem->costLayers()->avg('unit_cost'), 2);
                    } elseif ($orderItem->cogs_unit_cost !== null && (float)$orderItem->cogs_unit_cost > 0) {
                        $unitCost = (float) $orderItem->cogs_unit_cost;
                    } elseif ($orderItem->variant && $orderItem->variant->cost_price !== null && (float)$orderItem->variant->cost_price > 0) {
                        $unitCost = (float) $orderItem->variant->cost_price;
                    } elseif ($orderItem->product && $orderItem->product->cost_price !== null && (float)$orderItem->product->cost_price > 0) {
                        $unitCost = (float) $orderItem->product->cost_price;
                    }
                }

                if ($disposition === 'restock_sellable') {
                    $restockedQty = (int) ($itemRow['restocked_quantity'] ?? $qtyReturned);
                    $hasPass = true;

                    // 1. Physically restock sellable stock
                    if ($returnItem->variant_id) {
                        ProductVariant::where('id', $returnItem->variant_id)->increment('stock_quantity', $restockedQty);
                    }
                    if ($returnItem->product_id) {
                        Product::where('id', $returnItem->product_id)->increment('stock_quantity', $restockedQty);
                    }

                    $currentBalance = 0;
                    if ($returnItem->product_id) {
                        $p = Product::find($returnItem->product_id);
                        $currentBalance = $p ? $p->stock_quantity : 0;
                    }

                    // 2. Record inventory movement
                    InventoryMovement::create([
                        'product_id' => $returnItem->product_id,
                        'variant_id' => $returnItem->variant_id,
                        'movement_type' => 'refund_restock',
                        'quantity' => $restockedQty,
                        'unit_cost' => $unitCost,
                        'total_cost' => round($restockedQty * $unitCost, 2),
                        'balance_after' => $currentBalance,
                        'reference_type' => 'OrderReturn',
                        'reference_id' => (string) $return->id,
                        'user_id' => $actor?->id,
                        'notes' => "Restocked from Return #{$return->return_number} (QC Passed)",
                    ]);

                    // 3. Create FIFO inventory cost layer
                    InventoryCostLayer::create([
                        'product_id' => $returnItem->product_id,
                        'variant_id' => $returnItem->variant_id,
                        'goods_receipt_item_id' => null,
                        'unit_cost' => $unitCost,
                        'initial_quantity' => $restockedQty,
                        'remaining_quantity' => $restockedQty,
                        'is_depleted' => false,
                    ]);

                    $totalRestockedCost += round($restockedQty * $unitCost, 2);
                    $itemRefundPrice = (float) ($itemRow['refund_unit_price'] ?? $returnItem->refund_unit_price);
                    $itemRefundSubtotal = round($restockedQty * $itemRefundPrice, 2);
                    $totalRefundEligible += $itemRefundSubtotal;

                } elseif ($disposition === 'quarantine_damaged' || $disposition === 'write_off_loss') {
                    $hasDamage = true;
                    if ($disposition === 'quarantine_damaged') {
                        $damagedQty = (int) ($itemRow['damaged_quantity'] ?? $qtyReturned);
                    } else {
                        $writeoffQty = (int) ($itemRow['writeoff_quantity'] ?? $qtyReturned);
                    }

                    $lossQty = $damagedQty ?: $writeoffQty;

                    // Do NOT increment sellable stock. Record shrinkage movement.
                    $currentBalance = 0;
                    if ($returnItem->product_id) {
                        $p = Product::find($returnItem->product_id);
                        $currentBalance = $p ? $p->stock_quantity : 0;
                    }

                    InventoryMovement::create([
                        'product_id' => $returnItem->product_id,
                        'variant_id' => $returnItem->variant_id,
                        'movement_type' => 'damage_writeoff',
                        'quantity' => -$lossQty,
                        'unit_cost' => $unitCost,
                        'total_cost' => round($lossQty * $unitCost, 2),
                        'balance_after' => $currentBalance,
                        'reference_type' => 'OrderReturn',
                        'reference_id' => (string) $return->id,
                        'user_id' => $actor?->id,
                        'notes' => "Damaged in transit / written off from Return #{$return->return_number} ({$disposition})",
                    ]);

                    $totalLossCost += round($lossQty * $unitCost, 2);
                    $itemRefundPrice = (float) ($itemRow['refund_unit_price'] ?? $returnItem->refund_unit_price);
                    $itemRefundSubtotal = round($lossQty * $itemRefundPrice, 2);
                    $totalRefundEligible += $itemRefundSubtotal;
                } else {
                    // return_to_customer or rejected
                    $itemRefundPrice = 0.00;
                    $itemRefundSubtotal = 0.00;
                }

                $returnItem->update([
                    'condition' => $condition,
                    'qc_status' => $qcStatus,
                    'disposition' => $disposition,
                    'restocked_quantity' => $restockedQty,
                    'damaged_quantity' => $damagedQty,
                    'writeoff_quantity' => $writeoffQty,
                    'refund_unit_price' => $itemRefundPrice,
                    'refund_subtotal' => $itemRefundSubtotal,
                    'qc_notes' => $qcNotes,
                ]);
            }

            $overallQcStatus = 'passed';
            if ($hasDamage && $hasPass) {
                $overallQcStatus = 'partial_damage';
            } elseif ($hasDamage && !$hasPass) {
                $overallQcStatus = 'rejected';
            }

            // Physical QC & Inventory Disposition Completed
            // Determine if Financial Settlement is also already done or not required
            $financialDone = in_array($return->refund_status, ['processed', 'store_credit_issued', 'not_required', 'accounting_adjusted']);

            // For zero-payment COD RTO where customer paid 0 and refund eligible is 0:
            if ($return->return_type === 'rto' && (float) ($return->amount_collected_courier ?? 0) <= 0 && $totalRefundEligible <= 0) {
                $financialDone = true;
                $return->refund_status = 'not_required';
                // Post RTO general ledger reversal entry if not already posted
                AccountingService::postOrderReturn($return);
            }

            $lifecycleStatus = $financialDone ? 'resolved' : 'qc_completed';
            $resolvedAt = $financialDone ? now() : null;

            $newRefundStatus = $return->refund_status;
            if ($totalRefundEligible > 0 && ($newRefundStatus === 'none' || $newRefundStatus === 'not_required')) {
                $newRefundStatus = 'pending';
            } elseif ($financialDone && $totalRefundEligible <= 0) {
                $newRefundStatus = 'not_required';
            }

            $return->update([
                'status' => $lifecycleStatus,
                'inspection_status' => $overallQcStatus,
                'inspected_at' => now(),
                'inspected_by_user_id' => $actor?->id,
                'refund_amount' => $totalRefundEligible,
                'refund_status' => $newRefundStatus,
                'resolved_at' => $resolvedAt,
            ]);

            $return->order->update([
                'return_status' => $lifecycleStatus,
            ]);

            // Post inventory QC entry in accounting
            AccountingService::postInventoryReturnQc($return, $totalRestockedCost, $totalLossCost);

            return $return->fresh(['items.product', 'items.orderItem', 'order', 'shipment']);
        });
    }

    /**
     * Issue refund (Cash, Bank, MFS, or Store Credit) and resolve the return.
     */
    public function processRefund(OrderReturn $return, array $data, ?User $actor = null): OrderReturn
    {
        return DB::transaction(function () use ($return, $data, $actor) {
            $refundAmount = round((float) ($data['refund_amount'] ?? $return->refund_amount), 2);
            $refundMethod = $data['refund_method'] ?? ($return->refund_method !== 'none' ? $return->refund_method : 'cash');
            $notes = $data['notes'] ?? $return->notes;

            if ($refundMethod === 'store_credit' && $refundAmount > 0) {
                $customer = $return->customer ?? $return->order->user;
                if ($customer) {
                    $creditAccount = StoreCreditAccount::firstOrCreate(
                        ['user_id' => $customer->id],
                        ['balance' => 0.00, 'total_credited' => 0.00, 'total_debited' => 0.00]
                    );

                    $newBalance = round((float) $creditAccount->balance + $refundAmount, 2);
                    $creditAccount->update([
                        'balance' => $newBalance,
                        'total_credited' => round((float) $creditAccount->total_credited + $refundAmount, 2),
                    ]);

                    StoreCreditTransaction::create([
                        'account_id' => $creditAccount->id,
                        'user_id' => $customer->id,
                        'type' => 'refund',
                        'amount' => $refundAmount,
                        'balance_after' => $newBalance,
                        'reason' => "Refund for Return #{$return->return_number} (Order #{$return->order->order_number})",
                        'reference_type' => 'OrderReturn',
                        'reference_id' => (string) $return->id,
                        'created_by_user_id' => $actor?->id,
                    ]);
                }
            }

            $isZeroRefund = ($refundAmount <= 0);
            $refundStatus = $isZeroRefund 
                ? 'not_required' 
                : ($refundMethod === 'store_credit' ? 'store_credit_issued' : 'processed');

            // A Return/RTO may ONLY be marked Resolved when all required actions (including QC) are complete
            $qcCompleted = ($return->inspection_status !== 'pending' && !empty($return->inspected_at));
            $lifecycleStatus = $qcCompleted ? 'resolved' : 'received';
            $resolvedAt = $qcCompleted ? now() : null;

            $return->update([
                'refund_amount' => $refundAmount,
                'refund_method' => $refundMethod,
                'refund_status' => $refundStatus,
                'status' => $lifecycleStatus,
                'resolved_at' => $resolvedAt,
                'notes' => $notes,
            ]);

            // Update order financial stats
            $order = $return->order;
            $newAmountRefunded = round((float) ($order->amount_refunded ?? 0.00) + $refundAmount, 2);

            $paymentStatus = $order->payment_status;
            if ($refundAmount > 0) {
                if ($newAmountRefunded >= (float) $order->total_amount) {
                    $paymentStatus = 'refunded';
                } else {
                    $paymentStatus = 'partially_refunded';
                }
            }

            // Order status becomes returned if full return
            $orderStatus = $order->order_status;
            if ($return->return_type === 'rto' || $newAmountRefunded >= (float) $order->total_amount) {
                $orderStatus = 'returned';
            }

            $order->update([
                'amount_refunded' => $newAmountRefunded,
                'payment_status' => $paymentStatus,
                'order_status' => $orderStatus,
                'return_status' => $lifecycleStatus,
            ]);

            // Double-entry accounting entry (posts refund entry or zero-collection RTO reversal)
            AccountingService::postOrderReturn($return);

            \App\Services\OrderTimelineService::recordEvent(
                order: $order,
                eventType: 'accounting_adjusted',
                title: $refundAmount > 0 ? "Refund Issued (৳" . number_format($refundAmount, 2) . ")" : "RTO Accounting Adjusted",
                description: $refundAmount > 0 
                    ? "Refund of ৳" . number_format($refundAmount, 2) . " processed via {$refundMethod}." 
                    : "Unearned sales revenue and A/R reversed on General Ledger for Return #{$return->return_number}.",
                actorName: $actor?->name ?? 'Accounting System',
                iconType: 'dollar',
                metadata: ['return_number' => $return->return_number, 'refund_amount' => $refundAmount, 'refund_method' => $refundMethod]
            );

            return $return->fresh(['items.product', 'items.orderItem', 'order', 'shipment']);
        });
    }

    /**
     * Handle RTO webhook event automatically when courier notifies Return To Merchant.
     */
    public function handleCourierRtoEvent(Shipment $shipment, array $webhookData): ?OrderReturn
    {
        return DB::transaction(function () use ($shipment, $webhookData) {
            $order = $shipment->order;
            if (!$order) {
                return null;
            }

            // Check if an RTO return record already exists for this order
            $existingReturn = OrderReturn::where('order_id', $order->id)
                ->where('return_type', 'rto')
                ->first();

            if ($existingReturn) {
                $existingReturn->update([
                    'status' => 'in_transit',
                    'courier_tracking_code' => $shipment->tracking_code,
                ]);
                return $existingReturn;
            }

            // Extract any collected COD from courier webhook (e.g. partial delivery fee collected)
            $collectedAmount = isset($webhookData['collected_amount']) ? (float) $webhookData['collected_amount'] : 0.00;
            $rtoFee = isset($webhookData['rto_charge']) ? (float) $webhookData['rto_charge'] : 0.00;

            if ($rtoFee > 0) {
                $shipment->update(['rto_charge' => $rtoFee]);
            }

            return $this->createReturn($order, [
                'return_type' => 'rto',
                'return_reason' => $webhookData['reason'] ?? 'Courier Return To Origin (Consignment failed/refused)',
                'amount_collected_courier' => $collectedAmount,
                'courier_delivery_fee' => (float) $shipment->courier_charge,
                'courier_rto_fee' => $rtoFee,
                'courier_tracking_code' => $shipment->tracking_code,
                'notes' => 'RTO automatically initiated by ' . strtoupper($shipment->provider) . ' courier webhook update.',
            ]);
        });
    }
}
