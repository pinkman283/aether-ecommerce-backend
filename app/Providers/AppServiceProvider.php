<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 1. Customer Authentication Rate Limiter (3-Tier Defense: Composite + Target Account + IP Spray Ceiling)
        RateLimiter::for('auth-customer-login', function (Request $request) {
            $email = Str::transliterate(Str::lower(trim((string) $request->input('email', ''))));
            $ip = $request->ip();

            return [
                Limit::perMinute(5)
                    ->by('cust_login:' . $email . '|' . $ip)
                    ->response(function (Request $request, array $headers) {
                        return response()->json([
                            'message' => 'Too many login attempts for this account from your IP. Please slow down.',
                            'retry_after' => (int) ($headers['Retry-After'] ?? 60),
                        ], 429, $headers);
                    }),
                Limit::perMinute(10)
                    ->by('cust_login_account:' . $email)
                    ->response(function (Request $request, array $headers) {
                        return response()->json([
                            'message' => 'Too many login attempts for this account. Please wait before trying again.',
                            'retry_after' => (int) ($headers['Retry-After'] ?? 60),
                        ], 429, $headers);
                    }),
                Limit::perMinute(20)
                    ->by('cust_login_ip:' . $ip)
                    ->response(function (Request $request, array $headers) {
                        return response()->json([
                            'message' => 'Too many login attempts originating from this network.',
                            'retry_after' => (int) ($headers['Retry-After'] ?? 60),
                        ], 429, $headers);
                    }),
            ];
        });

        // 2. Admin Authentication Rate Limiter (Strict 3-Tier Defense)
        RateLimiter::for('auth-admin-login', function (Request $request) {
            $email = Str::transliterate(Str::lower(trim((string) $request->input('email', ''))));
            $ip = $request->ip();

            return [
                Limit::perMinute(5)
                    ->by('admin_login:' . $email . '|' . $ip)
                    ->response(function (Request $request, array $headers) {
                        return response()->json([
                            'message' => 'Too many administrative login attempts. Access temporarily restricted.',
                            'retry_after' => (int) ($headers['Retry-After'] ?? 60),
                        ], 429, $headers);
                    }),
                Limit::perMinute(8)
                    ->by('admin_login_account:' . $email)
                    ->response(function (Request $request, array $headers) {
                        return response()->json([
                            'message' => 'Too many attempts for this administrative account. Access temporarily paused.',
                            'retry_after' => (int) ($headers['Retry-After'] ?? 60),
                        ], 429, $headers);
                    }),
                Limit::perMinute(15)
                    ->by('admin_login_ip:' . $ip)
                    ->response(function (Request $request, array $headers) {
                        return response()->json([
                            'message' => 'Too many administrative login attempts from this network.',
                            'retry_after' => (int) ($headers['Retry-After'] ?? 60),
                        ], 429, $headers);
                    }),
            ];
        });

        // 3. Customer Registration Rate Limiter (IP Bucket + Composite)
        RateLimiter::for('auth-register', function (Request $request) {
            $email = Str::transliterate(Str::lower(trim((string) $request->input('email', ''))));
            $ip = $request->ip();

            return [
                Limit::perMinute(5)
                    ->by('register_ip:' . $ip)
                    ->response(function (Request $request, array $headers) {
                        return response()->json([
                            'message' => 'Too many account registrations originating from this network.',
                            'retry_after' => (int) ($headers['Retry-After'] ?? 60),
                        ], 429, $headers);
                    }),
                Limit::perMinute(3)
                    ->by('register_target:' . $email . '|' . $ip)
                    ->response(function (Request $request, array $headers) {
                        return response()->json([
                            'message' => 'Too many registration attempts for this email address.',
                            'retry_after' => (int) ($headers['Retry-After'] ?? 60),
                        ], 429, $headers);
                    }),
            ];
        });

        // 4. Order Creation / Checkout Rate Limiter (User ID / Guest IP + Email)
        RateLimiter::for('order-checkout', function (Request $request) {
            $user = $request->user();
            $ip = $request->ip();

            if ($user) {
                return Limit::perMinute(10)
                    ->by('order_user:' . $user->id . '|' . $ip)
                    ->response(function (Request $request, array $headers) {
                        return response()->json([
                            'message' => 'Order creation rate limit reached. Please wait before placing another order.',
                            'retry_after' => (int) ($headers['Retry-After'] ?? 60),
                        ], 429, $headers);
                    });
            }

            $email = Str::transliterate(Str::lower(trim((string) $request->input('customer_email', 'guest'))));
            return Limit::perMinute(10)
                ->by('order_guest:' . $email . '|' . $ip)
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'message' => 'Checkout rate limit reached. Please wait before placing another order.',
                        'retry_after' => (int) ($headers['Retry-After'] ?? 60),
                    ], 429, $headers);
                });
        });

        // 5. Coupon Validation Rate Limiter (Probe Prevention + Dictionary Code Lock)
        RateLimiter::for('coupon-validation', function (Request $request) {
            $user = $request->user();
            $ip = $request->ip();
            $identifier = $user ? ('user:' . $user->id) : ('ip:' . $ip);
            $code = Str::upper(trim((string) $request->input('code', '')));

            return [
                Limit::perMinute(10)
                    ->by('coupon_probe:' . $identifier)
                    ->response(function (Request $request, array $headers) {
                        return response()->json([
                            'message' => 'Too many coupon validation requests. Please wait a moment.',
                            'retry_after' => (int) ($headers['Retry-After'] ?? 60),
                        ], 429, $headers);
                    }),
                Limit::perMinute(5)
                    ->by('coupon_target:' . $identifier . '|' . $code)
                    ->response(function (Request $request, array $headers) {
                        return response()->json([
                            'message' => 'Too many validation attempts for this coupon code.',
                            'retry_after' => (int) ($headers['Retry-After'] ?? 60),
                        ], 429, $headers);
                    }),
            ];
        });

        // 6. Customer Review Submission Rate Limiter
        RateLimiter::for('customer-reviews', function (Request $request) {
            $user = $request->user();
            $identifier = $user ? ('user:' . $user->id) : ('ip:' . $request->ip());

            return Limit::perMinute(5)
                ->by('review:' . $identifier)
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'message' => 'Review submission rate limit reached. Please wait before submitting another review.',
                        'retry_after' => (int) ($headers['Retry-After'] ?? 60),
                    ], 429, $headers);
                });
        });

        // 7. Storefront Lead Capture Rate Limiter
        RateLimiter::for('leads-capture', function (Request $request) {
            $email = Str::transliterate(Str::lower(trim((string) $request->input('email', ''))));
            return Limit::perMinute(15)
                ->by('lead:' . $email . '|' . $request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'message' => 'Too many lead submissions.',
                        'retry_after' => (int) ($headers['Retry-After'] ?? 60),
                    ], 429, $headers);
                });
        });

        // 8. Sensitive Admin Action Rate Limiter (Staff promotion, refunds, audit purging)
        RateLimiter::for('sensitive-admin-action', function (Request $request) {
            $user = $request->user();
            $userId = $user ? $user->id : 'anon';
            return Limit::perMinute(30)
                ->by('admin_action:' . $userId . '|' . $request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'message' => 'Administrative request threshold reached.',
                        'retry_after' => (int) ($headers['Retry-After'] ?? 60),
                    ], 429, $headers);
                });
        });
    }
}
