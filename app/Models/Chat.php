<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chat extends Model
{
    protected $fillable = ['type', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_participants')->withPivot('joined_at');
    }
}
