import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
// Ensure cookies are sent with requests (needed for Sanctum session auth)
window.axios.defaults.withCredentials = true;
