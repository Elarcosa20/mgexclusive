<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\Message;
use Illuminate\Support\Arr;

class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message;

    public function __construct(Message $message)
    {
        $this->message = $message;
        $this->message->loadMissing(['sender', 'receiver']);
        
        // Ensure all data is properly parsed
        if ($this->message->product && is_string($this->message->product)) {
            try {
                $this->message->product = json_decode($this->message->product, true);
            } catch (\Exception $e) {
                $this->message->product = null;
            }
        }
        
        if ($this->message->images && is_string($this->message->images)) {
            try {
                $this->message->images = json_decode($this->message->images, true);
            } catch (\Exception $e) {
                $this->message->images = [];
            }
        }
        
        $this->message->isProductReference = !!$this->message->product;
        $this->message->hasImages = !empty($this->message->images);
    }

    public function broadcastOn(): array
    {
        $channels = [];

        if ($this->message->conversation_id) {
            $channels[] = new PrivateChannel('chat.conversation.' . $this->message->conversation_id);
        }

        if ($this->message->receiver_id) {
            $channels[] = new PrivateChannel('chat.' . $this->message->receiver_id);
        }

        if ($this->message->sender_id && $this->message->sender_id !== $this->message->receiver_id) {
            $channels[] = new PrivateChannel('chat.' . $this->message->sender_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'message' => [
                'id' => $this->message->id,
                'sender_id' => $this->message->sender_id,
                'receiver_id' => $this->message->receiver_id,
                'conversation_id' => $this->message->conversation_id,
                'message' => $this->message->message,
                'product' => $this->message->product,
                'images' => $this->message->images,
                'isProductReference' => $this->message->isProductReference,
                'hasImages' => $this->message->hasImages,
                'is_quick_option' => $this->message->is_quick_option,
                'created_at' => $this->message->created_at,
                'updated_at' => $this->message->updated_at,
                'sender' => $this->message->sender
                    ? Arr::only($this->message->sender->toArray(), [
                        'id',
                        'first_name',
                        'last_name',
                        'email',
                        'role',
                        'display_name',
                    ])
                    : null,
            ]
        ];
    }
}