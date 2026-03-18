const trimSlashes = (value = '') => String(value).replace(/^\/+|\/+$/g, '');

const isAbsoluteUrl = (value = '') => /^[a-z][a-z\d+\-.]*:\/\//i.test(value) || value.startsWith('//');

const normalizeUrlBase = (value = '') => String(value).trim().replace(/\/+$/, '');

export const normalizeBasePath = (value = '') => {
    const trimmed = trimSlashes(value);
    return trimmed ? `/${trimmed}` : '';
};

const readMetaContent = (name) => {
    if (typeof document === 'undefined') {
        return '';
    }

    return document.querySelector(`meta[name="${name}"]`)?.getAttribute('content') ?? '';
};

const joinPath = (base = '', path = '') => {
    const normalizedBase = String(base ?? '').replace(/\/+$/, '');
    const normalizedPath = String(path ?? '').replace(/^\/+/, '');

    if (!normalizedBase) {
        return normalizedPath ? `/${normalizedPath}` : '';
    }

    return normalizedPath ? `${normalizedBase}/${normalizedPath}` : normalizedBase;
};

const resolveRuntimeBasePath = () => normalizeBasePath(readMetaContent('app-base-path'));

export const getAppBasePath = () => resolveRuntimeBasePath() || normalizeBasePath(import.meta.env.VITE_APP_BASE_PATH);

export const getAppOrigin = () => {
    if (typeof window !== 'undefined') {
        return window.location.origin;
    }

    return '';
};

export const appUrl = (path = '') => {
    const origin = getAppOrigin();
    const pathWithBase = joinPath(getAppBasePath(), path);

    return origin ? joinPath(origin, pathWithBase) : pathWithBase;
};

export const assetUrl = (path = '') => appUrl(path);

export const getApiBaseUrl = () => {
    const configuredBase =
        readMetaContent('app-api-base-url').trim() || String(import.meta.env.VITE_API_BASE_URL ?? '').trim();

    if (!configuredBase) {
        return appUrl();
    }

    if (isAbsoluteUrl(configuredBase)) {
        return normalizeUrlBase(configuredBase);
    }

    if (configuredBase.startsWith('/')) {
        const origin = getAppOrigin();
        return origin ? joinPath(origin, configuredBase) : configuredBase;
    }

    return appUrl(configuredBase);
};

export const apiUrl = (path = '') => {
    const baseUrl = getApiBaseUrl();

    if (!path) {
        return baseUrl;
    }

    if (isAbsoluteUrl(path)) {
        return path;
    }

    return joinPath(baseUrl, path);
};

export const resolveZiggyConfig = (ziggy = globalThis.Ziggy) => {
    if (!ziggy) {
        return ziggy;
    }

    return {
        ...ziggy,
        url: appUrl(),
        location: typeof window !== 'undefined' ? new URL(window.location.href) : ziggy.location,
    };
};
