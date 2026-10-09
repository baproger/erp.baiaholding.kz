import { ref, watch, onMounted, onUnmounted } from 'vue';

/**
 * Часы и таймеры ТВ-табло по ВРЕМЕНИ СЕРВЕРА (правило от 09.10.2026).
 *
 * У телевизоров часто сбиты дата/время, а их старые браузеры не разбирают
 * часть форматов дат — «в цехе / на этапе» показывали 0м. Поэтому сервер
 * присылает «сейчас» (serverNow, мс) и готовые секунды таймеров, а экран лишь
 * досчитывает время, прошедшее с загрузки, по монотонному счётчику
 * performance.now() — от часов и даты устройства ничего не зависит.
 *
 * Только базовый JS (без Intl/timeZone) — работает в любом браузере ТВ.
 */
const mono = () => (typeof performance !== 'undefined' && performance.now ? performance.now() : Date.now());
const pad = (n) => (n < 10 ? '0' : '') + n;

export function useServerClock(getServerNow, getTzOffsetSec) {
    let base = getServerNow() || Date.now();
    let baseMono = mono();
    const sinceLoad = ref(0); // секунд с последней загрузки данных (реактивно, раз в секунду)
    const clock = ref('');
    const nowMs = () => base + (mono() - baseMono);

    const render = () => {
        sinceLoad.value = Math.max(0, (mono() - baseMono) / 1000);
        // Местное время сервера: UTC + смещение часового пояса (Asia/Almaty), без Intl.
        const d = new Date(nowMs() + (getTzOffsetSec() || 0) * 1000);
        clock.value = pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes()) + ':' + pad(d.getUTCSeconds());
    };

    // Пришли свежие данные (автообновление табло) — новая точка отсчёта.
    watch(getServerNow, (v) => { if (v) { base = v; baseMono = mono(); render(); } });

    let timer = null;
    onMounted(() => { render(); timer = setInterval(render, 1000); });
    onUnmounted(() => clearInterval(timer));

    /** Таймер: секунды на момент загрузки (с сервера) + прошедшее с загрузки. */
    const elapsed = (seconds) => (seconds === null || seconds === undefined ? null : seconds + sinceLoad.value);

    /** Текущий месяц по серверу, 'YYYY-MM'. */
    const serverMonth = () => {
        const d = new Date(nowMs() + (getTzOffsetSec() || 0) * 1000);
        return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1);
    };

    return { clock, elapsed, serverMonth, sinceLoad };
}
