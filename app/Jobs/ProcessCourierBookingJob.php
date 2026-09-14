<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Courier\CourierManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessCourierBookingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        public Order $order,
        public array $bookingParams = []
    ) {}

    public function handle(CourierManager $courierManager): void
    {
        Log::info("Processing async courier booking for Order #{$this->order->order_number}");

        try {
            $courierManager->bookShipment($this->order, $this->bookingParams);
        } catch (\Throwable $e) {
            Log::error("Async courier booking failed for Order #{$this->order->order_number}: " . $e->getMessage(), [
                'exception' => $e,
                'order_id' => $this->order->id,
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical("Courier booking job permanently failed for Order #{$this->order->order_number}: " . $exception->getMessage());
    }
}
