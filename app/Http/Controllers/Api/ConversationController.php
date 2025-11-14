<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Conversation;
use App\Models\Message;
use App\Events\MessageSent;

class ConversationController extends Controller
{
    // Customer: get their own conversations
    public function userConversations() {
        $userId = auth()->user()->id;
        $conversations = Conversation::with('clerk')->where('user_id',$userId)->orderBy('updated_at','desc')->get();
        return response()->json($conversations);
    }

    // Clerk: get conversations assigned to them
    public function clerkConversations() {
        $clerkId = auth()->user()->id;
        $conversations = Conversation::with('user')->where('clerk_id', $clerkId)->orderBy('updated_at','desc')->get();
        return response()->json($conversations);
    }

    // Create or return existing conversation
    public function store(Request $r) {
        $r->validate(['user_id'=>'required|exists:users,id','clerk_id'=>'nullable|exists:users,id']);
        $conv = Conversation::firstOrCreate([
            'user_id' => $r->user_id,
            'clerk_id' => $r->clerk_id ?? null,
        ]);
        return response()->json($conv);
    }

    // Get messages for a conversation
    public function messages(Conversation $conversation) {
        // ensure auth: user must be part of conversation
        $this->authorizeConversation($conversation);
        $messages = Message::where('conversation_id', $conversation->id)->with('sender')->orderBy('created_at','asc')->get();
        return response()->json($messages);
    }

    // Send message
    public function sendMessage(Request $r, Conversation $conversation) {
        $this->authorizeConversation($conversation);
        $r->validate(['message'=>'required|string']);
        $message = Message::create([
            'conversation_id'=>$conversation->id,
            'sender_id'=>auth()->user()->id,
            'message'=>$r->message
        ]);
        // fire event
        broadcast(new MessageSent($message))->toOthers();
        // update conversation updated_at so lists sort
        $conversation->touch();
        return response()->json($message);
    }

    protected function authorizeConversation(Conversation $conversation) {
        $user = auth()->user();
        if (!($user->id === $conversation->user_id || $user->id === $conversation->clerk_id)) {
            abort(403, 'Not authorized to access this conversation');
        }
    }

    // Extra: list all customers (for clerk to start conversations)
    public function customersList() {
        $customers = \App\Models\User::where('role','user')->select('id','name','email')->get();
        return response()->json($customers);
    }
}
