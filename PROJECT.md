# BAIA ERP / CRM — Документация проекта

> Единый файл-справочник: что построено, как работает, доступы, бизнес-правила.

## 1. Технологии
- **Backend:** Laravel 13 (PHP 8.5), MySQL `baia_erp`
- **Frontend:** Inertia.js + Vue 3 + Tailwind + Vite (Laravel Breeze)
- **Права:** spatie/laravel-permission (RBAC)
- **Таймзона:** Asia/Almaty (+05:00); **Локализация:** ru / kk
- **Тесты:** PHPUnit — 91, все зелёные

## 2. Роли и доступы
| Роль | Что видит |
|------|-----------|
| **admin** | всё |
| **director** | всё (наблюдатель), создаёт группы в чате |
| **financist** | финансы, аналитика, зарплата (сделки/цех — просмотр) |
| **manager** | ТОЛЬКО свои сделки/цех/финансы/бонус/дашборд; общей аналитики НЕ видит |
| **employee (цех)** | ВСЕ карточки цеха (наблюдатель, двигает этапы), свои задачи; сделки/суммы НЕ видит |

Учётки (пароль `password`): admin@baia.kz, director@baia.kz, finance@baia.kz, manager@baia.kz, cex@baia.kz

## 3. Модули
- **Сотрудники / Отделы:** CRUD, роли, деактивация.
- **Контрагенты:** в коде, скрыты из меню.
- **Сделки:** канбан+список; карточка (вкладки: Информация, Задачи, Финансы, Документы, Комментарии, Доп.поля, История, Чат сделки). Поля: Название компании*, Имя клиента*, Номер лота, Сумма*, Срок, Заметка. Кнопки: Далее, Отправить в цех, Изменить, смена ответственного.
- **Цех (Projects):** общий канбан; этапы Кесу…Оплата (редактируемые); финансы/история из исходной сделки; суммы скрыты от цеха.
- **Задачи:** доска; синхрон с сделок/цеха; срок дата+время, метка ПРОСРОЧЕНА; заметки; уведомление о просрочке (tasks:notify-overdue).
- **Финансы:** счета/платежи/расходы; маржа план/факт; менеджер — только свои.
- **Зарплата:** бонус = % (10) от чистой прибыли; только успешные+оплаченные; менеджер видит своё.
- **Аналитика:** воронка, доход/расход по месяцам, ABC (по факт. оплате), топ клиентов, конверсия; менеджеру недоступна.
- **Аудит / Уведомления / Доп.поля / Настройки / Чат** (общий/личный/групповой + чат сделки).

## 4. Бизнес-правила
- Сделка → Цех: кнопка на последнем этапе; заказ в цехе с этапа «Кесу», сделка закрывается.
- Успешная (won) = won-этап ИЛИ в цехе (есть проект) ИЛИ status=closed; отменённые — нет.
- Деньги как факт: в зарплате/аналитике/дашборде только успешные+оплаченные.
- Бонус: 10% (настраивается) от чистой прибыли; остальное компании.
- Дедлайны: красный=просрочено, оранжевый=≤2ч.
- Финансы в цехе = финансы сделки.

## 5. Запуск
```
php artisan serve            # http://localhost:8000
npm run dev                  # или npm run build
php artisan schedule:work    # уведомления о просрочке
```
Вход: admin@baia.kz / password

## 6. Changelog (git)
- 861e600 fix: stage ordering — remove Квалификация, re-index on delete
- 77d3596 fix: renamed/created stages now reflected on deal & Цех cards
- a28dc2b security: close authorization gaps found in audit
- c27c9fe feat: count money as fact only for won (successful) + paid deals
- cc73843 feat: Payroll/bonus page — 10% of net profit per manager
- e9c212d fix: Цех card shows the deal's finance & history (unified lifecycle)
- 573e769 change: ABC analysis by actual paid income, not planned budget
- f6f4fc2 feat: per-deal chat + manager sees only own data (no general analytics)
- 31dd239 chore: set timezone to Asia/Almaty
- 1d43e3c feat: shared Цех for staff, manager-scoped finance, company-first cards
- 3228929 feat: task sync to board, notes on deals/tasks, deal fields (company/lot)
- da0a7a0 feat: remove Номенклатура, restrict цех staff, readable history, compact card
- 0764c27 feat: roles (director/financist), Цех visibility, budget margin, responsive UI
- f166037 feat: card redesign, human dates, deadline colors, overdue alerts
- 8552002 feat: task editing (was missing) + assignee/creator can edit
- 3cadf25 feat: overdue markers + responsible can edit + reassign responsible
- 4ad383e feat: detailed margin/profit/benefit breakdown (этап 3)
- 69b76dc feat: ABC analysis in analytics dashboard (этап 2)
- c7af126 feat: history tab in deal/цех cards (этап 1)
- 20941f1 feat: deal workflow polish per feedback round 2
- c4262a5 feat: Цех workflow, editable stages, deadlines, custom-field visibility
- 7c3b7ef feat: User management module (Сотрудники) — ТЗ этап 3
- ae7ed85 feat: Chat module — DB-backed with polling (ТЗ этап 11)
- 77cb387 feat: Analytics dashboards (ТЗ этап 12)
- 97ae6d4 feat: Localization ru/kk + language switch (ТЗ этап 14)
- f8f5900 feat: System settings (table + cached model + UI)
- 999d135 feat: Custom fields (ТЗ этап 13)
- 133d8ff feat: Audit log + Notifications (ТЗ этап 10)
- 9578798 feat: Documents (versioned) + Comments (ТЗ этап 9)
- b798113 feat: Finance module — invoices, payments, expenses, margin (ТЗ этап 8)
- 2ca7ecb feat: Tasks module (ТЗ этап 7)
- 1fb2d40 feat: reference-data CRUD + Deals & Projects modules
- cee611c chore: scaffold BAIA ERP foundation (Laravel 13 + Inertia/Vue + RBAC)
