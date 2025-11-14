<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\Conversation;

Broadcast::channel('chat.conversation.{conversationId}', function ($user, $conversationId) {
    $conversation = Conversation::find($conversationId);
    if (!$conversation) {
        return false;
    }

    return $user->role === 'clerk' || (int) $user->id === (int) $conversation->user_id;
});

// Customer <-> Clerk private channel
Broadcast::channel('chat.{customerId}', function ($user, $customerId) {
    // Customer maka-access sa iyang kaugalingon channel
    // Tanan clerk makakita ani
    return (int) $user->id === (int) $customerId || $user->role === 'clerk';
});

// Shared channel for clerks (kung gusto nimo nga tanan clerk makakita sa tanan customer chats)
Broadcast::channel('clerks.shared', function ($user) {
    return $user->role === 'clerk';
});

// Optional: Clerk private channel (kung gusto nimo i-target specific clerk)
Broadcast::channel('clerk.{clerkId}', function ($user, $clerkId) {
    return (int) $user->id === (int) $clerkId || $user->role === 'customer';
});
Broadcast::channel('notifications.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
