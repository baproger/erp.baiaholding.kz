export function formatDate(v) {
    if (!v) return '—';
    const d = new Date(v);
    if (isNaN(d)) return v;
    return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' });
}

export function formatDateTime(v) {
    if (!v) return '—';
    const d = new Date(v);
    if (isNaN(d)) return v;
    return d.toLocaleString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

export function money(v) {
    return new Intl.NumberFormat('ru-RU').format(Math.round(v ?? 0)) + ' ₸';
}
