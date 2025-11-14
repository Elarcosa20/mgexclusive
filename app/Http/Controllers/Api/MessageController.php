<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use App\Events\MessageSent;

class MessageController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'Unauthorized'], 401);

        $conversation = Conversation::firstOrCreate(
            ['user_id' => $user->id],
            ['created_at' => now(), 'updated_at' => now()]
        );

        $messagesQuery = $conversation->messages()
            ->with(['sender', 'receiver'])
            ->orderBy('created_at', 'asc');

        $beforeId = $request->query('before_id');
        if ($beforeId) {
            $messagesQuery->where('id', '<', $beforeId);
        }

        $messages = $messagesQuery->get();

        // Parse message data for each message
        $messages->transform(function($message) {
            return $this->parseMessageData($message);
        });

        $conversation->load('activeClerk');

        return response()->json([
            'conversation' => $conversation,
            'messages' => $messages,
        ]);
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'Unauthorized'], 401);

        $validated = $request->validate([
            'conversation_id' => 'nullable|exists:conversations,id',
            'receiver_id' => 'nullable|exists:users,id',
            'message' => 'nullable|string',
            'product_data' => 'nullable|array',
            'is_quick_option' => 'nullable|boolean',
            'images' => 'nullable|array',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:10240',
        ]);

        $conversation = null;
        if (!empty($validated['conversation_id'])) {
            $conversation = Conversation::where('id', $validated['conversation_id'])
                ->where('user_id', $user->id)
                ->first();

            if (!$conversation) {
                return response()->json(['message' => 'Conversation not found'], 404);
            }
        } else {
            $conversation = Conversation::firstOrCreate(
                ['user_id' => $user->id],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }

        // Handle image uploads
        $uploadedImages = [];
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $path = $image->store('chat-images', 'public');
                $uploadedImages[] = Storage::url($path);
            }
        }

        // Prepare product data for storage
        $productData = null;
        if ($request->has('product_data') && $request->product_data) {
            $productData = [
                'id' => $request->product_data['id'] ?? null,
                'name' => $request->product_data['name'] ?? null,
                'price' => $request->product_data['price'] ?? null,
                'material' => $request->product_data['material'] ?? null,
                'description' => $request->product_data['description'] ?? null,
                'images' => $request->product_data['images'] ?? [],
                'current_image_index' => $request->product_data['current_image_index'] ?? 0,
                'timestamp' => $request->product_data['timestamp'] ?? now()->toISOString()
            ];
        }

        // Prepare message data
        $messageData = [
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            // Route all messages to the conversation owner channel
            'receiver_id' => $conversation->user_id,
            'message' => $request->message ?? null,
            'product' => $productData ? json_encode($productData) : null,
            'is_quick_option' => $request->is_quick_option ?? false,
        ];

        // Add images to message if any were uploaded
        if (!empty($uploadedImages)) {
            $messageData['images'] = json_encode($uploadedImages);
        }

        $message = Message::create($messageData);

        // Load the message with relationships
        $message->load(['sender', 'receiver']);
        
        // Prepare message data for response and broadcasting
        $messageData = $this->parseMessageData($message);

        $conversation->forceFill([
            'last_message_at' => now(),
            'updated_at' => now(),
        ]);

        if (!empty($validated['receiver_id'])) {
            $conversation->active_clerk_id = $validated['receiver_id'];
        }

        $conversation->save();

        // Broadcast the event
        broadcast(new MessageSent($message));

        return response()->json($messageData, 201);
    }

    /**
     * Parse message data for consistent response format
     */
    private function parseMessageData($message)
    {
        // Parse product data
        if ($message->product && is_string($message->product)) {
            try {
                $message->product = json_decode($message->product, true);
            } catch (\Exception $e) {
                $message->product = null;
            }
        }

        // Parse images data
        if ($message->images && is_string($message->images)) {
            try {
                $message->images = json_decode($message->images, true);
            } catch (\Exception $e) {
                $message->images = [];
            }
        }

        $message->isProductReference = !!$message->product;
        $message->hasImages = !empty($message->images);

        return $message;
    }
}