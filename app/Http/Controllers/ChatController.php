<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
            ->with(['participants:id,name,avatar', 'lastMessage.user:id,name'])
            ->withCount('messages')
            ->orderByDesc('updated_at')
            ->get()
            ->map(function ($c) use ($user) {
                // For a personal chat with no explicit name, show the other participant.
                $name = $c->name;
                if (! $name && $c->type === 'personal') {
                    $name = $c->participants->firstWhere('id', '!=', $user->id)?->name;
                }
                $name = $name ?: ($c->type === 'global' ? 'Общий чат' : 'Чат #'.$c->id);
                $last = $c->lastMessage;

                // Chat avatar: the group photo, or (for personal chats) the other person's photo.
                $avatar = $c->avatar ? route('chat.avatar', $c->id).'?v='.$c->updated_at->timestamp : null;
                if (! $avatar && $c->type === 'personal') {
                    $avatar = $c->participants->firstWhere('id', '!=', $user->id)?->avatar;
                }

                return [
                    'id' => $c->id,
                    'type' => $c->type,
                    'name' => $name,
                    'description' => $c->description,
                    'avatar' => $avatar,
                    'deal_id' => $c->deal_id,
                    'messages_count' => $c->messages_count,
                    'participants' => $c->participants->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'avatar' => $p->avatar])->values(),
                    'last' => $last ? [
                        'id' => $last->id,
                        'text' => \Illuminate\Support\Str::limit((string) $last->message, 42),
                        'author' => $last->user?->name,
                        'author_id' => $last->user_id,
                        'time' => $last->created_at->toIso8601String(),
                    ] : null,
                ];
            });

        return Inertia::render('Chat/Index', [
            'chats' => $chats,
            'users' => User::where('is_active', true)->where('id', '!=', $user->id)->orderBy('name')->get(['id', 'name', 'avatar']),
            'canCreateGroup' => $user->hasAnyRole(['admin', 'director']),
        ]);
    }

    public function messages(Request $request, Chat $chat): JsonResponse
    {
        $this->authorizeParticipant($request, $chat);

        $messages = $chat->messages()
            ->with(['user:id,name,avatar', 'replyTo:id,user_id,message', 'replyTo.user:id,name'])
            ->when($request->integer('after'), fn ($q, $after) => $q->where('id', '>', $after))
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'user_id' => $m->user_id,
                'user_name' => $m->user->name,
                'user_avatar' => $m->user->avatar,
                'message' => $m->message,
                'attachments' => collect($m->attachments ?? [])->values()->map(fn ($a, $i) => [
                    'name' => $a['name'] ?? 'файл',
                    'size' => (int) ($a['size'] ?? 0),
                    'mime' => $a['mime'] ?? '',
                    'is_image' => Str::startsWith($a['mime'] ?? '', 'image/'),
                    'url' => route('chat.attachment', [$m->id, $i]),
                ]),
                // Цитата: на какое сообщение отвечают (автор + короткий текст).
                'reply_to' => $m->replyTo ? [
                    'id' => $m->replyTo->id,
                    'user_name' => $m->replyTo->user?->name,
                    'message' => Str::limit((string) $m->replyTo->message, 120),
                ] : null,
                'edited' => $m->edited_at !== null,
                'can_delete' => $this->canDeleteMessage($request->user(), $m),
                'can_edit' => $m->user_id === $request->user()->id,
                'created_at' => $m->created_at->toIso8601String(),
            ]);

        return response()->json(['messages' => $messages]);
    }

    public function sendMessage(Request $request, Chat $chat): RedirectResponse
    {
        $this->authorizeParticipant($request, $chat);

        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:5000'],
            'reply_to_id' => ['nullable', 'integer', 'exists:chat_messages,id'],
            'file' => ['nullable', 'file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,png,jpg,jpeg,gif,webp,zip,rar,txt,csv'],
        ]);

        if (blank($validated['message'] ?? null) && ! $request->hasFile('file')) {
            throw ValidationException::withMessages(['message' => 'Введите сообщение или прикрепите файл.']);
        }

        // Отвечать можно только на сообщение ЭТОГО чата.
        $replyToId = null;
        if (! empty($validated['reply_to_id'])) {
            $replyToId = ChatMessage::where('id', $validated['reply_to_id'])->where('chat_id', $chat->id)->value('id');
        }

        $attachments = [];
        if ($file = $request->file('file')) {
            $attachments[] = [
                'path' => $file->store('chat', 'local'),
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime' => $file->getMimeType(),
            ];
        }

        $chat->messages()->create([
            'user_id' => $request->user()->id,
            'reply_to_id' => $replyToId,
            'message' => $validated['message'] ?? '',
            'attachments' => $attachments ?: null,
        ]);
        $chat->touch();

        return back();
    }

    /** Редактирование сообщения — ТОЛЬКО автор своего. Ставит метку «изменено». */
    public function updateMessage(Request $request, ChatMessage $message): JsonResponse
    {
        $this->authorizeParticipant($request, $message->chat);
        abort_unless($message->user_id === $request->user()->id, 403, 'Редактировать можно только свои сообщения.');

        $data = $request->validate(['message' => ['required', 'string', 'max:5000']]);
        $message->update(['message' => $data['message'], 'edited_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /** Удаление сообщения: любое — ТОЛЬКО админ; автор — своё. */
    public function destroyMessage(Request $request, ChatMessage $message): JsonResponse
    {
        $this->authorizeParticipant($request, $message->chat);
        abort_unless($this->canDeleteMessage($request->user(), $message), 403);

        foreach (($message->attachments ?? []) as $a) {
            if (! empty($a['path'])) {
                Storage::disk('local')->delete($a['path']);
            }
        }
        $message->delete();

        return response()->json(['ok' => true]);
    }

    public function downloadAttachment(Request $request, ChatMessage $message, int $index)
    {
        $this->authorizeParticipant($request, $message->chat);
        $a = ($message->attachments ?? [])[$index] ?? abort(404);
        abort_unless(! empty($a['path']) && Storage::disk('local')->exists($a['path']), 404);

        return Storage::disk('local')->response($a['path'], $a['name'] ?? 'file');
    }

    /** All files ever sent in a chat — for the «Вложения» tab. */
    public function attachments(Request $request, Chat $chat): JsonResponse
    {
        $this->authorizeParticipant($request, $chat);

        $files = [];
        $chat->messages()->whereNotNull('attachments')->with('user:id,name')->orderByDesc('id')->get()
            ->each(function ($m) use (&$files) {
                foreach (($m->attachments ?? []) as $i => $a) {
                    $files[] = [
                        'name' => $a['name'] ?? 'файл',
                        'size' => (int) ($a['size'] ?? 0),
                        'mime' => $a['mime'] ?? '',
                        'is_image' => Str::startsWith($a['mime'] ?? '', 'image/'),
                        'url' => route('chat.attachment', [$m->id, $i]),
                        'author' => $m->user?->name,
                        'time' => $m->created_at->toIso8601String(),
                    ];
                }
            });

        return response()->json(['attachments' => $files]);
    }

    public function avatar(Request $request, Chat $chat)
    {
        $this->authorizeParticipant($request, $chat);
        abort_unless($chat->avatar && Storage::disk('local')->exists($chat->avatar), 404);

        return Storage::disk('local')->response($chat->avatar);
    }

    private function canDeleteMessage(User $user, ChatMessage $message): bool
    {
        // Любое чужое сообщение удаляет ТОЛЬКО админ; своё — автор.
        return $user->hasRole('admin') || $message->user_id === $user->id;
    }

    public function store(Request $request): RedirectResponse
    {
        if ($request->input('type') === 'group') {
            abort_unless($request->user()->hasAnyRole(['admin', 'director']), 403, 'Группы создаёт только администратор или директор.');
        }
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'type' => ['required', 'in:personal,group'],
            'participants' => ['array'],
            'participants.*' => ['exists:users,id'],
        ]);

        $chat = Chat::create([
            'type' => $validated['type'],
            'name' => $validated['name'] ?? null,
            'description' => $validated['description'] ?? null,
            'is_active' => true,
        ]);

        $ids = collect($validated['participants'] ?? [])->push($request->user()->id)->unique();
        $chat->participants()->syncWithoutDetaching(
            $ids->mapWithKeys(fn ($id) => [$id => ['joined_at' => now()]])->all()
        );

        return back()->with('success', 'Чат создан.');
    }

    /** Rename a group and re-sync its participants (admin/director only). */
    public function update(Request $request, Chat $chat): RedirectResponse
    {
        abort_if($chat->type === 'global', 403, 'Общий чат нельзя изменить.');
        abort_unless($request->user()->hasAnyRole(['admin', 'director']), 403);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'participants' => ['array'],
            'participants.*' => ['exists:users,id'],
            'photo' => ['nullable', 'image', 'max:5120'],
        ]);

        $data = [
            'name' => $validated['name'] ?? $chat->name,
            'description' => $validated['description'] ?? $chat->description,
        ];
        if ($photo = $request->file('photo')) {
            if ($chat->avatar) {
                Storage::disk('local')->delete($chat->avatar);
            }
            $data['avatar'] = $photo->store('chat-avatars', 'local');
        }
        $chat->update($data);

        if ($request->has('participants')) {
            $ids = collect($validated['participants'])->push($request->user()->id)->unique();
            $chat->participants()->sync($ids->mapWithKeys(fn ($id) => [$id => ['joined_at' => now()]])->all());
        }

        return back()->with('success', 'Чат обновлён.');
    }

    /** Delete a chat with its messages (admin/director only; the global chat is protected). */
    public function destroy(Request $request, Chat $chat): RedirectResponse
    {
        abort_if($chat->type === 'global', 403, 'Общий чат нельзя удалить.');
        abort_unless($request->user()->hasAnyRole(['admin', 'director']), 403);

        $chat->messages()->delete();
        $chat->participants()->detach();
        $chat->delete();

        return back()->with('success', 'Чат удалён.');
    }

    private function authorizeParticipant(Request $request, Chat $chat): void
    {
        if ($chat->type === 'global') {
            return;
        }
        if ($chat->deal_id) {
            $deal = \App\Models\Deal::find($chat->deal_id);
            abort_unless($deal && $request->user()->can('view', $deal), 403);
            return;
        }
        abort_unless($chat->participants()->where('users.id', $request->user()->id)->exists(), 403);
    }
}
