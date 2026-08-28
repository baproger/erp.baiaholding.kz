<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageLayout from '@/Layouts/PageLayout.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { useStickyFilters } from '@/composables/useStickyFilters';
import Avatar from '@/Components/Avatar.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    users: Array,
    departments: Array,
    roles: Array,
    companies: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({ manage: false }) },
    workshopOptions: { type: Array, default: () => [] },
});

const roleLabels = { admin: 'Супер-админ', ceo: 'СЕО', director: 'Директор', financist: 'Финансист-Бухгалтер', manager: 'Менеджер', employee: 'Сотрудник (цех)', lawyer: 'Юрист', cook: 'Повар', designer: 'Дизайнер', supplier: 'Снабженец' };
const roleColors = {
    admin: 'bg-purple-50 text-purple-700 ring-purple-200',
    director: 'bg-indigo-50 text-indigo-700 ring-indigo-200',
    financist: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    manager: 'bg-blue-50 text-blue-700 ring-blue-200',
    designer: 'bg-pink-50 text-pink-700 ring-pink-200',
    supplier: 'bg-amber-50 text-amber-700 ring-amber-200',
    lawyer: 'bg-cyan-50 text-cyan-700 ring-cyan-200',
    cook: 'bg-orange-50 text-orange-700 ring-orange-200',
    employee: 'bg-slate-100 text-slate-600 ring-slate-200',
};
const companyNames = computed(() => Object.fromEntries(props.companies.map((c) => [c.id, c.name])));
// Руководители отделов — ⭐ на карточке.
const headIds = computed(() => new Set(props.departments.map((d) => d.head_user_id).filter(Boolean)));

// 🎂: сколько дней до ближайшего дня рождения (0 = сегодня, null = не указан).
const daysToBirthday = (u) => {
    if (!u.birth_date) return null;
    const bd = new Date(u.birth_date);
    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    let next = new Date(now.getFullYear(), bd.getMonth(), bd.getDate());
    if (next < today) next = new Date(now.getFullYear() + 1, bd.getMonth(), bd.getDate());
    return Math.round((next - today) / 86400000);
};

// Стаж: «в компании с 03.2024 · 1 г. 4 мес.»
const tenure = (u) => {
    if (!u.hired_at) return null;
    const from = new Date(u.hired_at);
    const now = new Date();
    let months = (now.getFullYear() - from.getFullYear()) * 12 + now.getMonth() - from.getMonth();
    if (now.getDate() < from.getDate()) months--;
    months = Math.max(0, months);
    const y = Math.floor(months / 12);
    const m = months % 12;
    const parts = [];
    if (y) parts.push(`${y} г.`);
    if (m || !y) parts.push(`${m} мес.`);
    const since = `${String(from.getMonth() + 1).padStart(2, '0')}.${from.getFullYear()}`;
    return `с ${since} · ${parts.join(' ')}`;
};

// --- Фильтры (всё на клиенте — мгновенно, без запросов) ---
const search = ref('');
// Фильтр по отделу — по code, а не по id: «Отдел продаж» есть в каждой фирме
// отдельным отделом, но чип на них один. '' = «Без отдела».
const deptFilter = ref('all');
const companyFilter = ref('all'); // 'all' | id компании
const showInactive = ref(false);
// Фильтр страницы запоминается: ушёл и вернулся — те же фирма/отдел/поиск.
useStickyFilters('users', { search, deptFilter, companyFilter, showInactive });

const inactiveCount = computed(() => props.users.filter((u) => !u.is_active).length);

const visibleUsers = computed(() => {
    const q = search.value.trim().toLowerCase();
    return props.users.filter((u) => {
        if (!showInactive.value && !u.is_active) return false;
        if (deptFilter.value !== 'all' && (u.department_code ?? '') !== deptFilter.value) return false;
        if (!q) return true;
        return [u.name, u.email, u.phone, u.department?.name, roleLabels[u.role]]
            .some((v) => (v ?? '').toLowerCase().includes(q));
    });
});

// Отдел фирмы по общему коду: «Отдел продаж» в BAIA и в ASU — разные записи.
const deptByCompanyCode = computed(() => {
    const map = new Map();
    props.departments.forEach((d) => map.set(`${d.company_id}|${d.code}`, d));
    return map;
});

// Отделы, сгруппированные по фирме — для селекта в форме сотрудника.
const departmentsByCompany = computed(() => {
    const map = {};
    props.departments.forEach((d) => (map[d.company_id] ??= []).push(d));
    return map;
});

// Чипы отделов с количеством (учитывают переключатель «отключённые», но не поиск).
const deptChips = computed(() => {
    const pool = props.users.filter((u) => showInactive.value || u.is_active);
    const counts = {};
    pool.forEach((u) => { const k = u.department_code ?? ''; counts[k] = (counts[k] ?? 0) + 1; });
    // Одноимённые отделы разных фирм схлопываются в один чип (общий code).
    const byCode = new Map();
    props.departments.forEach((d) => { if (!byCode.has(d.code)) byCode.set(d.code, d.name); });
    const chips = [...byCode.entries()]
        .map(([code, name]) => ({ code, name, count: counts[code] ?? 0 }))
        .filter((c) => c.count > 0)
        .sort((a, b) => a.name.localeCompare(b.name, 'ru'));
    if (counts['']) chips.push({ code: '', name: 'Без отдела', count: counts[''] });
    return chips;
});

// Секции: компания → отделы. Сотрудник обеих фирм показывается в обеих
// секциях — в одноимённом отделе своей фирмы (по общему code).
const companySections = computed(() => {
    const sections = props.companies
        .filter((c) => companyFilter.value === 'all' || companyFilter.value === c.id)
        .map((c) => ({ id: c.id, name: c.name, groups: [], count: 0 }));
    const orphans = { id: 0, name: 'Без компании', groups: [], count: 0 };

    const push = (section, u) => {
        // В секции фирмы показываем отдел ЭТОЙ фирмы (у «двойного» сотрудника
        // department_id указывает на отдел только одной из них).
        const own = u.department_code ? deptByCompanyCode.value.get(`${section.id}|${u.department_code}`) : null;
        const key = own?.id ?? u.department_code ?? 0;
        const name = own?.name ?? u.department?.name ?? 'Без отдела';
        let group = section.groups.find((g) => g.key === key);
        if (!group) {
            group = { key, name, head_user_id: own?.head_user_id ?? null, users: [] };
            section.groups.push(group);
        }
        group.users.push(u);
        section.count++;
    };

    visibleUsers.value.forEach((u) => {
        const ids = u.company_ids ?? [];
        if (!ids.length) {
            if (companyFilter.value === 'all') push(orphans, u);
            return;
        }
        sections.filter((s) => ids.includes(s.id)).forEach((s) => push(s, u));
    });

    const sorted = [...sections, orphans]
        .filter((s) => s.count > 0)
        .map((s) => ({
            ...s,
            groups: [...s.groups].sort((a, b) => (a.key === 0) - (b.key === 0) || a.name.localeCompare(b.name, 'ru')),
        }));
    return sorted;
});

const stats = computed(() => ({
    total: props.users.length,
    active: props.users.length - inactiveCount.value,
    // Отделы считаем по коду: «Отдел продаж» BAIA+ASU — один отдел холдинга.
    departments: new Set(props.users.filter((u) => u.is_active && u.department_code).map((u) => u.department_code)).size,
}));

// --- Модалка (создание/правка) ---
const show = ref(false);
const editing = ref(null);

const form = useForm({
    name: '', email: '', password: '', password_confirmation: '',
    department_id: '', workshops: [], phone: '', birth_date: '', hired_at: '', salary: 0, contract: null, role: 'employee', is_active: true,
    company_ids: props.companies.map((c) => c.id),
});
const toggleWorkshop = (w) => {
    form.workshops = form.workshops.includes(w)
        ? form.workshops.filter((x) => x !== w)
        : [...form.workshops, w];
};

const openCreate = () => {
    editing.value = null; form.reset(); form.role = 'employee'; form.is_active = true;
    form.company_ids = companyFilter.value === 'all' ? props.companies.map((c) => c.id) : [companyFilter.value];
    // Если открыт фильтр по отделу — подставляем отдел выбранной фирмы
    // (при «Все фирмы» отдел не угадать: одноимённых столько же, сколько фирм).
    const pickedCompany = companyFilter.value === 'all' ? null : companyFilter.value;
    form.department_id = deptFilter.value !== 'all' && deptFilter.value && pickedCompany
        ? (deptByCompanyCode.value.get(`${pickedCompany}|${deptFilter.value}`)?.id ?? '')
        : '';
    show.value = true;
};
const openEdit = (u) => {
    if (!props.can.manage) return;
    editing.value = u;
    Object.assign(form, {
        name: u.name, email: u.email, password: '', password_confirmation: '',
        department_id: u.department_id ?? '', workshops: [...(u.workshops ?? [])], phone: u.phone ?? '',
        birth_date: u.birth_date ?? '', hired_at: u.hired_at ?? '',
        salary: u.salary ?? 0, contract: null,
        role: u.role ?? 'employee', is_active: u.is_active,
        company_ids: [...(u.company_ids ?? [])],
    });
    show.value = true;
};
const toggleCompany = (id) => {
    form.company_ids = form.company_ids.includes(id)
        ? form.company_ids.filter((c) => c !== id)
        : [...form.company_ids, id];
};
const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => (show.value = false) };
    // Файл договора требует multipart — обновление идёт POST-ом с _method=put.
    if (editing.value) form.transform((d) => ({ ...d, _method: 'put' })).post(route('users.update', editing.value.id), opts);
    else form.post(route('users.store'), opts);
};
const deactivate = async (u) => {
    if (await confirmDialog({ title: 'Деактивировать сотрудника', message: `Сотрудник «${u.name}» потеряет доступ к системе.`, confirmText: 'Деактивировать', danger: true })) {
        router.delete(route('users.destroy', u.id), { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Сотрудники" />
    <AppLayout>
        <template #header>{{ $t('page.users', 'Сотрудники') }}</template>

        <PageLayout title="Сотрудники" subtitle="список по компаниям и отделам">
            <template #actions>
                <TextInput v-model="search" placeholder="Поиск: имя, email, телефон, отдел, роль…" class="w-full text-sm sm:w-72" />
                <a :href="route('users.export')">
                    <SecondaryButton>⬇ Excel</SecondaryButton>
                </a>
                <Link v-if="can.manage" :href="route('departments.index')">
                    <SecondaryButton>⚙ Отделы</SecondaryButton>
                </Link>
                <PrimaryButton v-if="can.manage" @click="openCreate">+ Добавить сотрудника</PrimaryButton>
            </template>

            <!-- Мини-статистика — плитки §5 -->
            <div class="grid grid-cols-1 gap-3 sm:max-w-md sm:grid-cols-3">
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="text-[11px] uppercase tracking-wide text-slate-400">Всего</div>
                    <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums text-slate-800">{{ stats.total }}</div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="text-[11px] uppercase tracking-wide text-slate-400">Активных</div>
                    <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums text-emerald-600">{{ stats.active }}</div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="text-[11px] uppercase tracking-wide text-slate-400">Отделов</div>
                    <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums text-indigo-600">{{ stats.departments }}</div>
                </div>
            </div>

            <!-- Фильтр по компаниям — чипы-фильтры §10 -->
            <div class="mt-4 flex flex-wrap items-center gap-2">
                <button type="button" @click="companyFilter = 'all'"
                    class="rounded-full border px-3 py-1 text-xs font-medium transition-colors duration-150"
                    :class="companyFilter === 'all' ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-50'">
                    Все фирмы
                </button>
                <button v-for="c in companies" :key="c.id" type="button" @click="companyFilter = companyFilter === c.id ? 'all' : c.id"
                    class="rounded-full border px-3 py-1 text-xs font-medium transition-colors duration-150"
                    :class="companyFilter === c.id ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-50'">
                    {{ c.name }}
                </button>
            </div>

            <!-- Фильтр по отделам — чипы-фильтры §10 -->
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <button type="button" @click="deptFilter = 'all'"
                    class="rounded-full border px-3 py-1 text-xs font-medium transition-colors duration-150"
                    :class="deptFilter === 'all' ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-50'">
                    Все
                </button>
                <button v-for="c in deptChips" :key="c.code" type="button" @click="deptFilter = deptFilter === c.code ? 'all' : c.code"
                    class="rounded-full border px-3 py-1 text-xs font-medium transition-colors duration-150"
                    :class="deptFilter === c.code ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-50'">
                    {{ c.name }} <span class="tabular-nums" :class="deptFilter === c.code ? 'text-indigo-400' : 'text-slate-400'">{{ c.count }}</span>
                </button>
                <label v-if="inactiveCount" class="ml-auto flex cursor-pointer items-center gap-1.5 text-xs text-slate-500">
                    <input type="checkbox" v-model="showInactive" class="rounded border-slate-300 text-indigo-600" />
                    Отключённые ({{ inactiveCount }})
                </label>
            </div>

            <!-- Секции §6: компания → отделы -->
            <section v-for="s in companySections" :key="s.id" class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
                    <h3 class="text-sm font-semibold text-slate-900">{{ s.name }}</h3>
                    <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium tabular-nums text-slate-500">{{ s.count }}</span>
                </div>

                <div v-for="g in s.groups" :key="g.key">
                    <div class="flex items-center gap-2 border-b border-slate-100 bg-slate-50 px-6 py-2.5">
                        <h4 class="text-xs uppercase tracking-wide text-slate-400">{{ g.name }}</h4>
                        <span class="text-[11px] font-medium tabular-nums text-slate-400">{{ g.users.length }}</span>
                    </div>
                    <!-- Список, а не карточки: справочник сканируют глазами сверху вниз,
                         и одна строка на человека читается быстрее сетки плиток. -->
                    <div v-for="u in g.users" :key="u.id"
                        class="group flex cursor-pointer items-center gap-3 border-b border-slate-100 px-4 py-2.5 transition-colors duration-150 last:border-b-0 hover:bg-slate-50/60 sm:px-6"
                        :class="{ 'opacity-50': !u.is_active }"
                        @click="router.visit(route('users.show', u.id))">
                        <Avatar :name="u.name" :src="u.avatar" :size="34" class="shrink-0" />

                        <!-- Имя и роль: главное, что ищут в списке -->
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-1.5">
                                <span v-if="headIds.has(u.id)" title="Руководитель отдела">⭐</span>
                                <span class="truncate text-sm font-medium text-slate-900" :title="tenure(u) ? 'В компании ' + tenure(u) : ''">{{ u.name }}</span>
                                <span v-if="daysToBirthday(u) === 0" class="shrink-0 text-pink-500" title="День рождения сегодня">🎂</span>
                                <span v-else-if="daysToBirthday(u) !== null && daysToBirthday(u) <= 7" class="shrink-0 text-pink-400" :title="'День рождения через ' + daysToBirthday(u) + ' дн.'">🎂</span>
                                <span v-if="!u.is_active" class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500">Отключён</span>
                            </div>
                            <!-- Контакты и цеха — второй строкой, приглушённо -->
                            <div class="mt-0.5 flex flex-wrap items-center gap-x-3 text-[11px] text-slate-400">
                                <a :href="`mailto:${u.email}`" class="truncate transition-colors hover:text-indigo-600" @click.stop>{{ u.email }}</a>
                                <a v-if="u.phone" :href="`tel:${u.phone}`" class="whitespace-nowrap transition-colors hover:text-indigo-600" @click.stop>{{ u.phone }}</a>
                                <span v-if="u.workshops?.length" class="hidden truncate sm:inline">🏭 {{ u.workshops.join(' + ') }}</span>
                            </div>
                        </div>

                        <span class="hidden shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium sm:inline-block"
                            :class="roleColors[u.role] ?? roleColors.employee">
                            {{ roleLabels[u.role] ?? u.role ?? '—' }}
                        </span>

                        <div class="hidden shrink-0 gap-1 lg:flex">
                            <span v-for="cid in u.company_ids" :key="cid" class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500">
                                {{ companyNames[cid] }}
                            </span>
                        </div>

                        <!-- Действия проявляются на строке, не занимая место постоянно -->
                        <div v-if="can.manage" class="flex shrink-0 items-center gap-1 opacity-0 transition-opacity group-hover:opacity-100">
                            <button class="rounded p-1 text-slate-300 transition-colors hover:text-indigo-600" title="Изменить" @click.stop="openEdit(u)">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                            </button>
                            <button v-if="u.is_active" class="rounded p-1 text-slate-300 transition-colors hover:text-rose-600" title="Отключить" @click.stop="deactivate(u)">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18.36 6.64A9 9 0 1 1 5.64 6.64M12 2v10"/></svg>
                            </button>
                        </div>
                    </div>
                </div>
            </section>
            <div v-if="!companySections.length" class="mt-6 rounded-xl border border-dashed border-slate-300 bg-white px-6 py-8 text-center text-sm text-slate-400 shadow-sm">
                Никого не нашли — измените поиск или фильтр
            </div>
        </PageLayout>

        <Modal :show="show" @close="show = false" max-width="2xl">
            <div class="p-6">
                <h2 class="mb-4 text-lg font-semibold text-slate-900">{{ editing ? 'Изменить сотрудника' : 'Новый сотрудник' }}</h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <InputLabel value="Имя" />
                        <TextInput v-model="form.name" class="mt-1 w-full" />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Email" />
                        <TextInput v-model="form.email" type="email" class="mt-1 w-full" />
                        <InputError :message="form.errors.email" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="editing ? 'Новый пароль (если менять)' : 'Пароль'" />
                        <TextInput v-model="form.password" type="password" class="mt-1 w-full" />
                        <InputError :message="form.errors.password" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Повтор пароля" />
                        <TextInput v-model="form.password_confirmation" type="password" class="mt-1 w-full" />
                    </div>
                    <div>
                        <InputLabel value="Отдел" />
                        <select v-model="form.department_id" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                            <option value="">—</option>
                            <!-- Отделы свои у каждой фирмы — группируем, иначе одноимённые не различить. -->
                            <optgroup v-for="c in companies" :key="c.id" :label="c.name">
                                <option v-for="d in departmentsByCompany[c.id] ?? []" :key="d.id" :value="d.id">{{ d.name }}</option>
                            </optgroup>
                        </select>
                    </div>
                    <div>
                        <InputLabel value="Роль" />
                        <select v-model="form.role" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                            <option v-for="r in roles" :key="r" :value="r">{{ roleLabels[r] ?? r }}</option>
                        </select>
                    </div>
                    <div v-if="workshopOptions.length" class="sm:col-span-2">
                        <InputLabel value="Доступ к цехам (пусто = все цеха; можно выбрать оба)" />
                        <div class="mt-1 flex flex-wrap gap-2">
                            <button v-for="w in workshopOptions" :key="w" type="button" @click="toggleWorkshop(w)"
                                class="rounded-full border px-3 py-1 text-xs font-medium transition-colors duration-150"
                                :class="form.workshops.includes(w) ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-50'">
                                🏭 {{ w }}
                            </button>
                        </div>
                        <p class="mt-1 text-[11px] text-slate-400">Сотрудник увидит и сможет двигать только заказы своих цехов</p>
                    </div>
                    <div><InputLabel value="Телефон" /><TextInput v-model="form.phone" class="mt-1 w-full" /></div>
                    <div>
                        <InputLabel value="День рождения (🎂 напомним руководству)" />
                        <TextInput v-model="form.birth_date" type="date" class="mt-1 w-full" />
                        <InputError :message="form.errors.birth_date" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Дата приёма на работу (стаж)" />
                        <TextInput v-model="form.hired_at" type="date" class="mt-1 w-full" />
                        <InputError :message="form.errors.hired_at" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Оклад, ₸ (ЗП = оклад + бонус)" />
                        <TextInput v-model="form.salary" type="number" step="0.01" min="0" class="mt-1 w-full" />
                        <InputError :message="form.errors.salary" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Договор (файл, необязательно)" />
                        <input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" class="mt-1 w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-slate-600 hover:file:bg-slate-200"
                            @change="form.contract = $event.target.files[0] ?? null" />
                        <InputError :message="form.errors.contract" class="mt-1" />
                        <a v-if="editing?.has_contract" :href="route('users.contract', editing.id)" class="mt-1 inline-block text-xs font-medium text-indigo-600 hover:underline">📄 Скачать текущий договор</a>
                    </div>
                    <div class="sm:col-span-2">
                        <InputLabel value="Компании (может работать в обеих)" />
                        <div class="mt-1 flex gap-2">
                            <button v-for="c in companies" :key="c.id" type="button" @click="toggleCompany(c.id)"
                                class="rounded-full border px-3 py-1 text-xs font-medium transition-colors duration-150"
                                :class="form.company_ids.includes(c.id) ? 'border-emerald-500 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-50'">
                                {{ c.name }}
                            </button>
                        </div>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-slate-600 sm:col-span-2">
                        <input type="checkbox" v-model="form.is_active" class="rounded border-slate-300 text-indigo-600" /> Активен
                    </label>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="show = false">Отмена</SecondaryButton>
                    <PrimaryButton :disabled="form.processing" @click="submit">Сохранить</PrimaryButton>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
