<?php

namespace App\Services;

use App\Models\Setting;
use InvalidArgumentException;

class ShippingZoneResolver
{
    /**
     * Known districts and aliases in Bangladesh categorized by shipping zones.
     */
    protected static array $dhakaMetroKeywords = [
        'dhaka', 'dhk', 'dhaka metro', 'dhaka city', 'gulshan', 'banani', 'dhanmondi',
        'uttara', 'mirpur', 'mohammadpur', 'motijheel', 'badda', 'baridhara', 'khilgaon',
        'rampura', 'bashundhara', 'lalbagh', 'tejgaon', 'shahbagh', 'paltan', 'malibagh',
        'maghbazar', 'jatrabari', 'wari', 'adabor', 'shyamoli', 'farmgate', 'cantonment',
    ];

    protected static array $dhakaSuburbsKeywords = [
        'gazipur', 'narayanganj', 'savar', 'keraniganj', 'tongie', 'tongi', 'ashulia',
        'dhamrai', 'rupganj', 'araihazar', 'sonargaon', 'bandar', 'kaliganj', 'sreepur',
        'kapasia', 'munshiganj', 'manikganj', 'narsingdi',
    ];

    /**
     * All other standard 64 districts in Bangladesh mapping to outside_dhaka
     */
    protected static array $outsideDhakaKeywords = [
        'chittagong', 'chattogram', 'ctg', 'sylhet', 'rajshahi', 'khulna', 'barisal',
        'barishal', 'rangpur', 'mymensingh', 'cox\'s bazar', 'coxs bazar', 'coxsbazar',
        'cumilla', 'comilla', 'feni', 'brahmanbaria', 'chandpur', 'lakshmipur', 'noakhali',
        'khagrachhari', 'rangamati', 'bandarban', 'bogura', 'bogra', 'joypurhat', 'naogaon',
        'natore', 'chapai nawabganj', 'nawabganj', 'pabna', 'sirajganj', 'dinajpur',
        'gaibandha', 'kurigram', 'lalmonirhat', 'nilphamari', 'panchagarh', 'thakurgaon',
        'jashore', 'jessore', 'satkhira', 'meherpur', 'narail', 'chuadanga', 'kushtia',
        'magura', 'bagerhat', 'jhenaidah', 'barguna', 'bhola', 'jhalokati', 'patuakhali',
        'pirojpur', 'jamalpur', 'netrokona', 'sherpur', 'habiganj', 'moulvibazar',
        'sunamganj', 'faridpur', 'gopalganj', 'madaripur', 'rajbari', 'shariatpur',
    ];

    /**
     * Normalize destination string (city, state, or address string).
     */
    public static function normalizeDestination(string $destination): string
    {
        $normalized = strtolower(trim($destination));
        // Remove special punctuation, normalize multiple spaces
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        return trim($normalized);
    }

    /**
     * Authoritatively resolve the shipping zone ID from the destination address.
     *
     * @param array $address Array containing 'city', 'address_line1', 'state', etc.
     * @return string Zone ID ('inside_dhaka', 'dhaka_suburbs', or 'outside_dhaka')
     * @throws InvalidArgumentException When the destination cannot be identified as a valid location.
     */
    public static function resolveZoneFromAddress(array $address): string
    {
        $city = self::normalizeDestination($address['city'] ?? '');
        $line1 = self::normalizeDestination($address['address_line1'] ?? '');
        $state = self::normalizeDestination($address['state'] ?? '');

        $combined = trim("{$city} {$line1} {$state}");

        if (empty($city) && empty($combined)) {
            throw new InvalidArgumentException('Shipping destination city is required.');
        }

        // 0. Check Explicit Outside Dhaka keywords before Dhaka Metro (so 'Outside Dhaka' does not trigger 'dhaka')
        if (
            str_contains($city, 'outside dhaka') ||
            str_contains($city, 'outside_dhaka') ||
            str_contains($combined, 'outside dhaka') ||
            str_contains($combined, 'outside_dhaka')
        ) {
            return 'outside_dhaka';
        }

        // 1. Check Suburbs first (so Gazipur/Savar inside "Dhaka District" resolves correctly to suburbs if present)
        foreach (self::$dhakaSuburbsKeywords as $keyword) {
            if (self::containsWord($city, $keyword) || self::containsWord($combined, $keyword)) {
                return 'dhaka_suburbs';
            }
        }

        // 2. Check Dhaka Metro
        foreach (self::$dhakaMetroKeywords as $keyword) {
            if (self::containsWord($city, $keyword) || self::containsWord($combined, $keyword)) {
                return 'inside_dhaka';
            }
        }

        // 3. Check Known Outside Dhaka Districts
        foreach (self::$outsideDhakaKeywords as $keyword) {
            if (self::containsWord($city, $keyword) || self::containsWord($combined, $keyword)) {
                return 'outside_dhaka';
            }
        }

        // If city contains common unrecognized gibberish, throw validation error
        // A valid city string must have at least 2 alpha characters
        if (strlen($city) < 2 || preg_match('/^[0-9\s]+$/', $city) || in_array($city, ['invalid', 'test', 'unknown', 'na', 'none', 'xyz', 'asdf'])) {
            throw new InvalidArgumentException("Unable to determine valid shipping zone for destination '{$city}'. Please specify a valid city or district in Bangladesh.");
        }

        // Default nationwide fallback for unlisted but valid town/district names in Bangladesh
        return 'outside_dhaka';
    }

    /**
     * Check if a text contains a keyword with word boundary awareness.
     */
    protected static function containsWord(string $text, string $keyword): bool
    {
        if ($text === $keyword) {
            return true;
        }

        $pattern = '/\b' . preg_quote($keyword, '/') . '\b/i';
        return (bool) preg_match($pattern, $text);
    }

    /**
     * Retrieve all configured shipping zones from system settings.
     */
    public static function getConfiguredZones(): array
    {
        $zonesRaw = Setting::get('shipping_zones');
        $zones = is_string($zonesRaw) ? json_decode($zonesRaw, true) : (is_array($zonesRaw) ? $zonesRaw : []);

        if (empty($zones) || !is_array($zones)) {
            $zones = [
                ['id' => 'inside_dhaka', 'name' => 'Inside Dhaka Metro', 'rate' => 60, 'duration' => '24-48 Hours', 'free_threshold' => 3000, 'is_active' => true],
                ['id' => 'dhaka_suburbs', 'name' => 'Dhaka Suburbs (Gazipur, Savar, Narayanganj)', 'rate' => 100, 'duration' => '48-72 Hours', 'free_threshold' => 5000, 'is_active' => true],
                ['id' => 'outside_dhaka', 'name' => 'Outside Dhaka (Nationwide)', 'rate' => 130, 'duration' => '3-5 Business Days', 'free_threshold' => 6000, 'is_active' => true],
                ['id' => 'express_sameday', 'name' => 'Express Same-Day Dispatch', 'rate' => 200, 'duration' => 'Same Day (Before 2 PM)', 'free_threshold' => 0, 'is_active' => true],
            ];
            Setting::set('shipping_zones', json_encode($zones));
        }

        return $zones;
    }

    /**
     * Calculate authoritative shipping fee based on resolved zone, subtotal, and optional method preference.
     *
     * @param array $address Customer shipping address
     * @param float $subtotal Order subtotal before discounts
     * @param string|null $submittedMethod The client submitted shipping method
     * @return array{
     *     resolved_zone: string,
     *     effective_method: string,
     *     shipping_fee: float,
     *     is_free: bool,
     *     free_threshold: float,
     *     base_rate: float
     * }
     * @throws InvalidArgumentException If submitted method is incompatible or destination is invalid.
     */
    public static function resolveAndCalculate(array $address, float $subtotal, ?string $submittedMethod = null): array
    {
        $resolvedZone = self::resolveZoneFromAddress($address);
        $configuredZones = self::getConfiguredZones();

        // Zone definitions map
        $zoneMap = [];
        foreach ($configuredZones as $zone) {
            $zoneMap[$zone['id']] = $zone;
        }

        // Validate client submitted method
        $effectiveMethod = $resolvedZone;

        if (!empty($submittedMethod)) {
            $cleanSubmitted = strtolower(trim($submittedMethod));

            // Express same-day dispatch is only allowable inside Dhaka metro
            if ($cleanSubmitted === 'express_sameday') {
                if ($resolvedZone !== 'inside_dhaka') {
                    throw new InvalidArgumentException("Express same-day delivery is only available inside Dhaka Metro. Your destination resolves to outside Dhaka.");
                }
                $effectiveMethod = 'express_sameday';
            } elseif ($cleanSubmitted !== $resolvedZone) {
                // Reject tampered or mismatched zone selection (e.g. client chose inside_dhaka for a Chittagong address)
                if (
                    ($resolvedZone === 'outside_dhaka' && in_array($cleanSubmitted, ['inside_dhaka', 'dhaka_suburbs'])) ||
                    ($resolvedZone === 'dhaka_suburbs' && $cleanSubmitted === 'inside_dhaka')
                ) {
                    throw new InvalidArgumentException("Shipping method '{$submittedMethod}' does not match destination ({$address['city']}). Correct shipping zone is '{$resolvedZone}'.");
                }

                $effectiveMethod = $resolvedZone;
            }
        }

        $zoneConfig = $zoneMap[$effectiveMethod] ?? ($zoneMap[$resolvedZone] ?? null);
        $baseRate = (float) ($zoneConfig['rate'] ?? ($resolvedZone === 'outside_dhaka' ? 130.00 : 60.00));
        $threshold = (float) ($zoneConfig['free_threshold'] ?? ($resolvedZone === 'outside_dhaka' ? 6000.00 : 3000.00));

        $isFree = ($threshold > 0 && $subtotal >= $threshold);
        $shippingFee = $isFree ? 0.00 : $baseRate;

        return [
            'resolved_zone' => $resolvedZone,
            'effective_method' => $effectiveMethod,
            'shipping_fee' => $shippingFee,
            'is_free' => $isFree,
            'free_threshold' => $threshold,
            'base_rate' => $baseRate,
        ];
    }
}
