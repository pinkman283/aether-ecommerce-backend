<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PosCashMovement;
use App\Models\PosRegister;
use App\Models\PosRegisterSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPosRegisterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'pos.access');

        $registers = PosRegister::with(['activeSession.user'])->get();
        return response()->json($registers);
    }

    public function currentSession(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'pos.access');

        $session = PosRegisterSession::with(['register', 'user', 'cashMovements.user'])
            ->where('user_id', $request->user()->id)
            ->where('status', 'open')
            ->latest()
            ->first();

        if ($session) {
            $session->recalculateExpectedCash();
        }

        return response()->json(['session' => $session]);
    }

    public function openSession(Request $request, int $registerId): JsonResponse
    {
        $this->checkPermission($request, 'pos.register_manage');

        $register = PosRegister::findOrFail($registerId);

        // Check if there is already an active session on this register
        $existing = PosRegisterSession::where('pos_register_id', $register->id)
            ->where('status', 'open')
            ->first();

        if ($existing) {
            return response()->json([
                'message' => "Register '{$register->name}' already has an open session by cashier {$existing->user?->name}.",
                'session' => $existing,
            ], 422);
        }

        // Check if current user already has an open session anywhere
        $userExisting = PosRegisterSession::where('user_id', $request->user()->id)
            ->where('status', 'open')
            ->first();

        if ($userExisting) {
            return response()->json([
                'message' => "You already have an open session on register {$userExisting->register?->name}. Please close it before opening a new one.",
                'session' => $userExisting,
            ], 422);
        }

        $validated = $request->validate([
            'opening_balance' => 'required|numeric|min:0',
        ]);

        $session = PosRegisterSession::create([
            'pos_register_id' => $register->id,
            'user_id' => $request->user()->id,
            'opened_at' => now(),
            'opening_balance' => $validated['opening_balance'],
            'expected_cash_balance' => $validated['opening_balance'],
            'status' => 'open',
        ]);

        $register->update(['status' => 'open']);

        AuditLog::log(
            $request->user(),
            'pos.session_opened',
            'PosRegisterSession',
            $session->id,
            "Cashier {$request->user()->name} opened register '{$register->name}' with opening cash float of \${$session->opening_balance}."
        );

        return response()->json([
            'message' => "Register '{$register->name}' opened successfully.",
            'session' => $session->load(['register', 'user']),
        ], 201);
    }

    public function closeSession(Request $request, int $sessionId): JsonResponse
    {
        $this->checkPermission($request, 'pos.register_manage');

        $session = PosRegisterSession::with('register')->findOrFail($sessionId);

        if ($session->status === 'closed') {
            return response()->json(['message' => 'This register session is already closed.'], 422);
        }

        $validated = $request->validate([
            'actual_closing_cash' => 'required|numeric|min:0',
            'closing_notes' => 'nullable|string|max:2000',
        ]);

        $expectedCash = $session->recalculateExpectedCash();
        $actualCash = (float) $validated['actual_closing_cash'];
        $difference = $actualCash - $expectedCash;

        $session->update([
            'closed_at' => now(),
            'actual_closing_cash' => $actualCash,
            'cash_difference' => $difference,
            'closing_notes' => $validated['closing_notes'] ?? null,
            'status' => 'closed',
        ]);

        $session->register->update(['status' => 'closed']);

        AuditLog::log(
            $request->user(),
            'pos.session_closed',
            'PosRegisterSession',
            $session->id,
            "Closed register session #{$session->id}. Expected: \${$expectedCash}, Actual: \${$actualCash}, Difference: \${$difference}."
        );

        return response()->json([
            'message' => 'Register session closed and balanced successfully.',
            'session' => $session->fresh(['register', 'user']),
        ]);
    }

    public function cashMovement(Request $request, int $sessionId): JsonResponse
    {
        $this->checkPermission($request, 'pos.register_manage');

        $session = PosRegisterSession::findOrFail($sessionId);
        if ($session->status !== 'open') {
            return response()->json(['message' => 'Cannot add cash movement to a closed session.'], 422);
        }

        $validated = $request->validate([
            'type' => 'required|in:cash_in,cash_out,drop',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:255',
        ]);

        $movement = PosCashMovement::create([
            'pos_register_session_id' => $session->id,
            'type' => $validated['type'],
            'amount' => $validated['amount'],
            'reason' => $validated['reason'],
            'user_id' => $request->user()->id,
        ]);

        if ($validated['type'] === 'cash_in') {
            $session->increment('cash_in_amount', $validated['amount']);
        } else {
            $session->increment('cash_out_amount', $validated['amount']);
        }

        $session->recalculateExpectedCash();
        $session->save();

        AuditLog::log(
            $request->user(),
            'pos.cash_movement',
            'PosCashMovement',
            $movement->id,
            "Recorded POS {$validated['type']} of \${$validated['amount']}. Reason: {$validated['reason']}."
        );

        return response()->json([
            'message' => 'Cash movement recorded.',
            'cash_movement' => $movement,
            'expected_cash_balance' => $session->expected_cash_balance,
        ]);
    }
}
