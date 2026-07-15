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
     * Цех у каждой компании СВОЙ (BAIA — мебельный, ASU — швейный). Если у
     * фирмы есть СВОИ этапы — показываем только их; «общие» (company_id=null,
     * легаси/тесты) — ТОЛЬКО как фолбэк, иначе одинаковые названия двоятся
     * (Кесу+Кесу…) в степпере заказа.
     */
    public static function companyQuery(?int $companyId): \Illuminate\Database\Eloquent\Builder
    {
        $q = static::where('is_active', true)->orderBy('order')->orderBy('id');

        if ($companyId) {
            static::where('is_active', true)->where('company_id', $companyId)->exists()
                ? $q->where('company_id', $companyId)
                : $q->whereNull('company_id');
        }

        return $q;
    }

    public static function funnel(?int $companyId = null): \Illuminate\Support\Collection
    {
        return static::companyQuery($companyId)->get();
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
