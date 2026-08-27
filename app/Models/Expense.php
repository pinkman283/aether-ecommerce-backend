<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    use HasFactory;

    protected $fillable = [
        'expense_number',
        'expense_category_id',
        'title',
        'amount',
        'expense_date',
        'payee_vendor_id',
        'payee_name',
        'payment_method',
        'reference_number',
        'receipt_attachment_url',
        'notes',
        'status',
        'created_by_user_id',
    ];

    protected $casts = [
        'amount' => 'float',
        'expense_date' => 'date',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function payeeVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'payee_vendor_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
