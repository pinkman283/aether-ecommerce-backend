<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vendor extends Model
{
    use HasFactory;

    protected $fillable = [
        'vendor_code',
        'name',
        'company_name',
        'contact_person',
        'phone',
        'email',
        'address',
        'city',
        'tax_number',
        'payment_terms',
        'notes',
        'status',
    ];

    public function vendorProducts(): HasMany
    {
        return $this->hasMany(VendorProduct::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function priceHistories(): HasMany
    {
        return $this->hasMany(VendorPriceHistory::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'payee_vendor_id');
    }
}
