// Red highlight when overdue or due within 1 hour.
export function deadlineClass(value, done = false) {
    if (!value || done) return '';
    const d = new Date(value);
    if (isNaN(d)) return '';
    if (d.getTime() <= Date.now() + 60 * 60 * 1000) return 'text-red-600 font-semibold';
    return '';
}

// Within 1 hour of the deadline (or past it).
export function isOverdue(value, done = false) {
    if (!value || done) return false;
    const d = new Date(value);
    return !isNaN(d) && d.getTime() <= Date.now() + 60 * 60 * 1000;
}

// Strictly past the deadline.
export function isPastDue(value, done = false) {
    if (!value || done) return false;
    const d = new Date(value);
    return !isNaN(d) && d.getTime() < Date.now();
}
