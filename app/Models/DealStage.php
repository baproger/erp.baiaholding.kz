<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DealStage extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'order', 'color', 'checklist', 'type', 'is_won', 'is_active',
    ];

    protected $casts = [
        'checklist' => 'array',
        'is_won' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(DealStageTranslation::class);
    }

    /**
     * Localised name for the given (or current) locale, falling back to base name.
     */
    public function translatedName(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $this->translations
            ->firstWhere('locale', $locale)?->name ?? $this->name;
    }
}
