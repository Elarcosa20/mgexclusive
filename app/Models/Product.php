<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model {
    use HasFactory;

    protected $fillable = [
        'name','price','description','category_id','status',
        'material','note','dimensions','weight','compartments',
        'features','available_sizes','prices','image','images'
    ];

    protected $casts = [
        'features' => 'array',
        'available_sizes' => 'array',
        'images' => 'array',
        'prices' => 'array', // new cast
    ];

    public function category() {
        return $this->belongsTo(Category::class);
    }

    public function getImageUrlAttribute() {
        return $this->image ? url('storage/' . $this->image) : null;
    }

    public function getImageUrlsAttribute() {
        return $this->images ? collect($this->images)->map(fn($i) => url('storage/' . $i)) : [];
    }
}
