<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectStage extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id', 'name', 'order', 'color', 'checklist', 'type', 'is_completed', 'is_active',
    ];

    protected $casts = [
        'checklist' => 'array',
        'is_completed' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Цех у каждой компании СВОЙ (BAIA — мебельный, ASU — швейный): этапы с
     * company_id видны только своей фирме; без company_id — общие (легаси/тесты).
     */
    public static function funnel(?int $companyId = null): \Illuminate\Support\Collection
    {
        return static::where('is_active', true)
            ->when($companyId, fn ($q, $c) => $q->where(fn ($w) => $w->where('company_id', $c)->orWhereNull('company_id')))
            ->orderBy('order')->orderBy('id')->get();
    }

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
