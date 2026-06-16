<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'name',
        'phone',
        'email',
        'address',
        'city',
        'gst_number',
        'due_amount',
        'total_purchases',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'due_amount' => 'decimal:2',
        'total_purchases' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
