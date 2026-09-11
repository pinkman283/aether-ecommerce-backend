<?php

namespace App\Contracts;

use App\DTOs\Courier\ShipmentBookingDTO;
use App\DTOs\Courier\ShipmentBookingResultDTO;
use App\DTOs\Courier\ShipmentTrackingDTO;
use App\DTOs\Courier\WebhookEventDTO;
use Illuminate\Http\Request;

interface CourierProviderInterface
{
    /**
     * Provider slug: 'steadfast', 'pathao', 'redx'
     */
    public function getProviderName(): string;

    /**
     * Check connection and credentials against the live API gateway
     */
    public function testConnection(): array;

    /**
     * Create parcel consignment with courier
     */
    public function createShipment(ShipmentBookingDTO $dto): ShipmentBookingResultDTO;

    /**
     * Track live status from courier
     */
    public function trackShipment(string $consignmentIdOrTracking): ShipmentTrackingDTO;

    /**
     * Cancel an active parcel booking with courier
     */
    public function cancelShipment(string $consignmentId): bool;

    /**
     * Verify incoming webhook request signature / authentication
     */
    public function verifyWebhookSignature(Request $request): bool;

    /**
     * Parse inbound webhook request into standard WebhookEventDTO
     */
    public function parseWebhook(Request $request): ?WebhookEventDTO;

    /**
     * Get available merchant stores / pickup hubs
     */
    public function getStores(): array;
}
