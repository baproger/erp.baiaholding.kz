<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectStage extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'order', 'color', 'checklist', 'type', 'is_completed', 'is_active',
    ];

    protected $casts = [
        'checklist' => 'array',
        'is_completed' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ProjectStageTranslation::class);
    }

    public function translatedName(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $this->translations
            ->firstWhere('locale', $locale)?->name ?? $this->name;
    }
}
