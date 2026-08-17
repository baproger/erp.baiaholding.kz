<?php

namespace App\Support;

use App\Models\Company;

/**
 * Терминология фирм: у ASU вид работ «Сборка» называется «Работа»
 * (этап воронки переименован в данных; здесь — подписи вида расхода).
 * BAIA и режим «Все» показывают «Сборка».
 */
class CompanyTerms
{
    /** @var array<int, string> кэш названий фирм на время запроса */
    private static array $names = [];

    public static function assembly(?int $companyId = null): string
    {
        $companyId ??= CurrentCompany::id();
        if (! $companyId) {
            return 'Сборка';
        }
        self::$names[$companyId] ??= (string) Company::find($companyId)?->name;

        return self::isAsu(self::$names[$companyId]) ? 'Работа' : 'Сборка';
    }

    public static function isAsu(?string $name): bool
    {
        return str_contains(mb_strtoupper($name ?? ''), 'ASU');
    }
}
