const normalizeBasePath = (value) => {
    const raw = String(value ?? '').trim();

    if (raw === '' || raw === '/') {
        return '';
    }

    const trimmed = raw.replace(/^\/+|\/+$/g, '');

    return trimmed === '' ? '' : `/${trimmed}`;
};

const readMetaContent = (name) =>
    document.querySelector(`meta[name="${name}"]`)?.getAttribute('content') ?? '';

export const appBasePath = normalizeBasePath(readMetaContent('app-base-path') || import.meta.env.VITE_APP_BASE_PATH || '');

export const toAppUrl = (path) => {
    const raw = String(path ?? '').trim();

    if (raw === '') {
        return appBasePath || '/';
    }

    if (/^https?:\/\//i.test(raw)) {
        return raw;
    }

    const normalizedPath = raw.startsWith('/') ? raw : `/${raw}`;

    return `${appBasePath}${normalizedPath}` || normalizedPath;
};
