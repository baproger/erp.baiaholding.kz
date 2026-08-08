<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['company_id', 'name', 'code', 'description', 'head_user_id', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    /** Руководитель отдела: ⭐ на карточке сотрудника + уведомления по отделу. */
    public function head(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_user_id');
    }

    /** Фирма отдела: «Отдел продаж» BAIA и ASU — разные отделы. */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Отделы одной фирмы. null = «Все компании» (сводный режим) — не сужаем.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Department>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Department>
     */
    public function scopeForCompany($query, ?int $companyId)
    {
        return $query->when($companyId, fn ($q) => $q->where('company_id', $companyId));
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function members(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
