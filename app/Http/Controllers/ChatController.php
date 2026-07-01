<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
    /**
     * Ensure a single global chat exists and the user participates in it.
     */
    private function ensureGlobalChat(User $user): Chat
    {
        $global = Chat::firstOrCreate(['type' => 'global'], ['name' => 'Общий чат', 'is_active' => true]);
        $global->participants()->syncWithoutDetaching([$user->id => ['joined_at' => now()]]);

        return $global;
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $this->ensureGlobalChat($user);

        $chats = Chat::query()
            ->where(fn ($q) => $q->where('type', 'global')
                ->orWhereHas('participants', fn ($p) => $p->where('users.id', $user->id)))
            ->withCount('messages')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'type' => $c->type,
                'name' => $c->name ?: ($c->type === 'global' ? 'Общий чат' : 'Чат #'.$c->id),
                'messages_count' => $c->messages_count,
            ]);

        return Inertia::render('Chat/Index', [
            'chats' => $chats,
            'users' => User::where('is_active', true)->where('id', '!=', $user->id)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function messages(Request $request, Chat $chat): JsonResponse
    {
        $this->authorizeParticipant($request, $chat);

        $messages = $chat->messages()
            ->with('user:id,name')
            ->when($request->integer('after'), fn ($q, $after) => $q->where('id', '>', $after))
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'user_id' => $m->user_id,
                'user_name' => $m->user->name,
                'message' => $m->message,
                'created_at' => $m->created_at->toIso8601String(),
            ]);

        return response()->json(['messages' => $messages]);
    }

    public function sendMessage(Request $request, Chat $chat): RedirectResponse
    {
        $this->authorizeParticipant($request, $chat);

        $validated = $request->validate(['message' => ['required', 'string', 'max:5000']]);

        $chat->messages()->create([
            'user_id' => $request->user()->id,
            'message' => $validated['message'],
        ]);
        $chat->touch();

        return back();
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'in:personal,group'],
            'participants' => ['array'],
            'participants.*' => ['exists:users,id'],
        ]);

        $chat = Chat::create([
            'type' => $validated['type'],
            'name' => $validated['name'] ?? null,
            'is_active' => true,
        ]);

        $ids = collect($validated['participants'] ?? [])->push($request->user()->id)->unique();
        $chat->participants()->syncWithoutDetaching(
            $ids->mapWithKeys(fn ($id) => [$id => ['joined_at' => now()]])->all()
        );

        return back()->with('success', 'Чат создан.');
    }

    private function authorizeParticipant(Request $request, Chat $chat): void
    {
        if ($chat->type === 'global') {
            return;
        }
        abort_unless($chat->participants()->where('users.id', $request->user()->id)->exists(), 403);
    }
}
