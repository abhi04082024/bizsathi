<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'name',
        'name_hi',
        'sku',
        'category_id',
        'type',
        'description',
        'unit',
        'unit_hi',
        'purchase_price',
        'selling_price',
        'stock_quantity',
        'min_stock_level',
        'is_active',
        'image',
    ];

    protected $casts = [
        'purchase_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'stock_quantity' => 'decimal:3',
        'min_stock_level' => 'decimal:3',
        'is_active' => 'boolean',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function invoiceItems()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeLowStock($query)
    {
        return $query->whereColumn('stock_quantity', '<=', 'min_stock_level');
    }

    public function getStockStatusAttribute()
    {
        if ($this->stock_quantity <= 0) return 'out_of_stock';
        if ($this->stock_quantity <= $this->min_stock_level) return 'low_stock';
        if ($this->stock_quantity <= $this->min_stock_level * 2) return 'medium';
        return 'in_stock';
    }

    public function getStockStatusTextAttribute()
    {
        $status = $this->stock_status;
        return match($status) {
            'out_of_stock' => 'Out of Stock',
            'low_stock' => 'Low Stock',
            'medium' => 'Medium',
            'in_stock' => 'In Stock',
            default => 'Unknown',
        };
    }
}
