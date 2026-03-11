import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true;
window.axios.defaults.withXSRFToken = true;

window.axios.interceptors.response.use(
    (response) => response,
    async (error) => {
        const originalRequest = error?.config;
        const status = error?.response?.status;

        if (!originalRequest || status !== 419 || originalRequest.__csrfRetry) {
            return Promise.reject(error);
        }

        originalRequest.__csrfRetry = true;

        try {
            await window.axios.get('/sanctum/csrf-cookie');
            if (originalRequest.headers && originalRequest.headers['X-CSRF-TOKEN']) {
                delete originalRequest.headers['X-CSRF-TOKEN'];
            }
            return window.axios(originalRequest);
        } catch (refreshError) {
            return Promise.reject(error);
        }
    },
);
