<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DealStage extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id', 'name', 'order', 'color', 'checklist', 'type', 'is_won', 'is_active',
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

    /**
     * У каждой компании (BAIA/ASU) СВОЯ воронка: этапы с company_id видны
     * только своей фирме; этапы без company_id — общие (легаси/тесты).
     * Спец-этапы ищутся ПО НАЗВАНИЮ (не по позиции): пользователь может
     * перемещать/добавлять этапы в настройках, порядок не гарантирован.
     */

    /** Активная воронка компании (+ общие этапы без компании). */
    public static function funnel(?int $companyId = null): \Illuminate\Support\Collection
    {
        return static::where('is_active', true)
            ->when($companyId, fn ($q, $c) => $q->where(fn ($w) => $w->where('company_id', $c)->orWhereNull('company_id')))
            ->orderBy('order')->get();
    }

    /** «Акт утверждение» — с него сделку ведёт бухгалтер. */
    public static function actStage(?int $companyId = null): ?self
    {
        $active = self::funnel($companyId);

        return $active->first(fn ($s) => mb_stripos($s->name, 'акт') !== false)
            ?? $active->slice(-2, 1)->first();
    }

    /** «ЭСФ» — после акта, галочка со сроком 30 дней. Может отсутствовать. */
    public static function esfStage(?int $companyId = null): ?self
    {
        return self::funnel($companyId)->first(fn ($s) => mb_stripos($s->name, 'эсф') !== false);
    }

    /** «Оплата успешно» — is_won. */
    public static function wonStage(?int $companyId = null): ?self
    {
        $active = self::funnel($companyId);

        return $active->firstWhere('is_won', true) ?? $active->last();
    }

    /** «Логистика» — сюда цех возвращает сделку после производства. */
    public static function logisticsStage(?int $companyId = null): ?self
    {
        return self::funnel($companyId)->first(fn ($s) => mb_stripos($s->name, 'логист') !== false);
    }

    /** «Закуп ЛДСП,МДФ» — на этом этапе доступна кнопка «В цех». */
    public static function workshopGateStage(?int $companyId = null): ?self
    {
        $active = self::funnel($companyId);

        return $active->first(fn ($s) => mb_stripos($s->name, 'закуп') !== false)
            ?? $active->slice(-3, 1)->first();
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
