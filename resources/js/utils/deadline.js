// Returns a CSS class when a deadline is overdue or due within 1 hour.
export function deadlineClass(value, done = false) {
    if (!value || done) return '';
    const d = new Date(value);
    if (isNaN(d)) return '';
    const threshold = Date.now() + 60 * 60 * 1000; // within 1 hour
    if (d.getTime() <= threshold) return 'text-red-600 font-semibold';
    return '';
}

export function isOverdue(value, done = false) {
    if (!value || done) return false;
    const d = new Date(value);
    if (isNaN(d)) return false;
    return d.getTime() <= Date.now() + 60 * 60 * 1000;
}
