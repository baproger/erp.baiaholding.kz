import { usePage } from '@inertiajs/vue3';

// Терминология фирм: у ASU вид работ «Сборка» называется «Работа».
// BAIA и режим «Все» показывают «Сборка». Зеркало app/Support/CompanyTerms.php.
export const isAsuName = (name) => (name ?? '').toUpperCase().includes('ASU');

/** Подпись вида работ для фирмы (по умолчанию — текущей в переключателе). */
export function assemblyLabel(companyId = null) {
    const auth = usePage().props.auth ?? {};
    const id = companyId ?? auth.currentCompanyId;
    const name = (auth.companies ?? []).find((c) => c.id === id)?.name;
    return isAsuName(name) ? 'Работа' : 'Сборка';
}
