<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'name',
        'phone',
        'email',
        'type',
        'address',
        'village',
        'district',
        'state',
        'pincode',
        'gst_number',
        'credit_limit',
        'due_amount',
        'total_sales',
        'total_invoices',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'due_amount' => 'decimal:2',
        'total_sales' => 'decimal:2',
        'total_invoices' => 'integer',
        'is_active' => 'boolean',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function getStatusAttribute()
    {
        if ($this->due_amount <= 0) return 'active';
        if ($this->due_amount > $this->credit_limit) return 'overdue';
        return 'pending';
    }
}
