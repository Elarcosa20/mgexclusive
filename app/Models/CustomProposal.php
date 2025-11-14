<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomProposal extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'customer_id',
        'name',
        'customization_request',
        'product_type',
        'category',
        'customer_name',
        'customer_email',
        'quantity',
        'total_price',
        'designer_message',
        'material',
        'features',
        'images',
        'size_options',
    ];

    protected $casts = [
        'features' => 'array',
        'images' => 'array',
        'size_options' => 'array',
        'quantity' => 'integer',
        'total_price' => 'decimal:2'
    ];

    // Relationship to user (clerk/designer who created the proposal)
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Relationship to customer (user who requested the customization)
    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    // Relationship to cart items
    public function cartItems()
    {
        return $this->hasMany(Cart::class, 'custom_proposal_id');
    }

    // Get display price
    public function getDisplayPriceAttribute()
    {
        $price = $this->total_price ? $this->total_price : 0;
        $price = (float) $price;
        return '₱' . number_format($price, 2);
    }

    // Alternative method for getting price as float
    public function getPriceFloatAttribute()
    {
        return (float) ($this->total_price ? $this->total_price : 0);
    }
}