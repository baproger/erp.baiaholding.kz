<?php

namespace App\Http\Controllers;

use App\Http\Requests\CommentRequest;
use App\Models\Comment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function store(CommentRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['user_id'] = $request->user()->id;

        Comment::create($data);

        return back()->with('success', 'Комментарий добавлен.');
    }

    public function update(Request $request, Comment $comment): RedirectResponse
    {
        abort_unless($comment->user_id === $request->user()->id || $request->user()->hasRole('admin'), 403);

        $validated = $request->validate(['body' => ['required', 'string']]);
        $comment->update(['body' => $validated['body'], 'edited_at' => now()]);

        return back()->with('success', 'Комментарий обновлён.');
    }

    public function destroy(Request $request, Comment $comment): RedirectResponse
    {
        abort_unless($comment->user_id === $request->user()->id || $request->user()->hasRole('admin'), 403);

        $comment->delete();

        return back()->with('success', 'Комментарий удалён.');
    }
}
