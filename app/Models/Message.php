<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Events\MessageSent;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'sender_id', 
        'receiver_id', 
        'message', 
        'product', 
        'is_quick_option',
        'images'
    ];

    protected $touches = [
        'conversation',
    ];

    protected $casts = [
        'is_quick_option' => 'boolean',
    ];

    protected $dispatchesEvents = [
        'created' => MessageSent::class,
    ];

    /**
     * Sender relationship
     */
    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * Receiver relationship
     */
    public function receiver()
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    /**
     * Conversation relationship
     */
    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Accessor for parsed product data
     */
    public function getProductAttribute($value)
    {
        if (is_string($value)) {
            try {
                return json_decode($value, true);
            } catch (\Exception $e) {
                return null;
            }
        }
        return $value;
    }

    /**
     * Mutator for product data
     */
    public function setProductAttribute($value)
    {
        if (is_array($value)) {
            $this->attributes['product'] = json_encode($value);
        } else {
            $this->attributes['product'] = $value;
        }
    }

    /**
     * Accessor for parsed images data
     */
    public function getImagesAttribute($value)
    {
        if (is_string($value)) {
            try {
                return json_decode($value, true);
            } catch (\Exception $e) {
                return [];
            }
        }
        return $value ?? [];
    }

    /**
     * Mutator for images data
     */
    public function setImagesAttribute($value)
    {
        if (is_array($value)) {
            $this->attributes['images'] = json_encode($value);
        } else {
            $this->attributes['images'] = $value;
        }
    }

    /**
     * Check if message has images
     */
    public function getHasImagesAttribute()
    {
        return !empty($this->images);
    }
}