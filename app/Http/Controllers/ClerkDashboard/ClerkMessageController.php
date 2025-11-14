<?php

namespace App\Http\Controllers\ClerkDashboard;

use App\Http\Controllers\Controller;    
use Illuminate\Http\Request;
use App\Models\Message;
use App\Models\Conversation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use App\Events\MessageSent;

class ClerkMessageController extends Controller
{
    // GET /clerk/messages?customer_id={id}
    public function index(Request $request)
    {
        $customerId = $request->query('customer_id');
        if (!$customerId) return response()->json(['error'=>'customer_id required'],400);

        $conversation = Conversation::firstOrCreate(
            ['user_id' => $customerId],
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

        $conversation->load(['activeClerk', 'user']);

        return response()->json([
            'conversation' => $conversation,
            'messages' => $messages,
        ]);
    }

    // POST /clerk/messages/send
    public function sendMessage(Request $request)
    {
        $validated = $request->validate([
            'receiver_id' => 'required|exists:users,id',
            'conversation_id' => 'nullable|exists:conversations,id',
            'message' => 'nullable|string',
            'images' => 'nullable|array',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:10240',
        ]);

        // Handle image uploads
        $uploadedImages = [];
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $path = $image->store('chat-images', 'public');
                $uploadedImages[] = Storage::url($path);
            }
        }

        $conversation = null;
        if (!empty($validated['conversation_id'])) {
            $conversation = Conversation::where('id', $validated['conversation_id'])
                ->where('user_id', $request->receiver_id)
                ->first();
        }

        if (!$conversation) {
            $conversation = Conversation::firstOrCreate(
                ['user_id' => $request->receiver_id],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }

        $messageData = [
            'conversation_id' => $conversation->id,
            'sender_id' => Auth::id(),
            'receiver_id' => $conversation->user_id,
            'message' => $request->message,
        ];

        // Add images to message if any were uploaded
        if (!empty($uploadedImages)) {
            $messageData['images'] = json_encode($uploadedImages);
        }

        $message = Message::create($messageData);

        // Load relationships
        $message->load(['sender', 'receiver']);

        // Parse message data for response
        $messageData = $this->parseMessageData($message);

        $conversation->forceFill([
            'active_clerk_id' => Auth::id(),
            'last_message_at' => now(),
            'updated_at' => now(),
        ])->save();

        // Broadcast the event
        broadcast(new MessageSent($message));

        return response()->json($messageData, 201);
    }

    // POST /clerk/messages/send-product
    public function sendProduct(Request $request)
    {
        $validated = $request->validate([
            'receiver_id' => 'required|exists:users,id',
            'conversation_id' => 'nullable|exists:conversations,id',
            'product' => 'required|array',
            'images' => 'nullable|array',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:10240',
        ]);

        // Handle image uploads for product
        $uploadedImages = [];
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $path = $image->store('chat-images', 'public');
                $uploadedImages[] = Storage::url($path);
            }
        }

        // Merge uploaded images with product images if any
        $productData = $request->product;
        if (!empty($uploadedImages)) {
            $existingImages = $productData['images'] ?? [];
            $productData['images'] = array_merge($existingImages, $uploadedImages);
        }

        $conversation = null;
        if (!empty($validated['conversation_id'])) {
            $conversation = Conversation::where('id', $validated['conversation_id'])
                ->where('user_id', $request->receiver_id)
                ->first();
        }

        if (!$conversation) {
            $conversation = Conversation::firstOrCreate(
                ['user_id' => $request->receiver_id],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => Auth::id(),
            'receiver_id' => $conversation->user_id,
            'message' => $productData['note'] ?? 'Here\'s a product suggestion for you!',
            'product' => json_encode($productData),
        ]);

        // Load relationships
        $message->load(['sender', 'receiver']);

        // Parse message data for response
        $messageData = $this->parseMessageData($message);
        $messageData['isProductReference'] = true;

        $conversation->forceFill([
            'active_clerk_id' => Auth::id(),
            'last_message_at' => now(),
            'updated_at' => now(),
        ])->save();

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