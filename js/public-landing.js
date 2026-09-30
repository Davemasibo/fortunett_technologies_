(() => {
    'use strict';
    const themeButton = document.querySelector('.theme-toggle');
    function setTheme(theme) {
        document.documentElement.dataset.theme = theme;
        themeButton.setAttribute('aria-label', theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme');
    }
    if (themeButton) {
        themeButton.hidden = false;
        try { setTheme(localStorage.getItem('fortunett-public-theme') === 'dark' ? 'dark' : 'light'); } catch (_) { setTheme('light'); }
        themeButton.addEventListener('click', () => {
            const theme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
            setTheme(theme);
            try { localStorage.setItem('fortunett-public-theme', theme); } catch (_) { /* Storage may be disabled. */ }
        });
    }
    const toggle = document.getElementById('show-password');
    if (toggle) {
        toggle.hidden = false;
        toggle.addEventListener('click', () => {
            const input = document.getElementById('password');
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            toggle.textContent = show ? 'Hide' : 'Show';
            toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            toggle.setAttribute('aria-pressed', String(show));
        });
    }
    document.getElementById('login-form')?.addEventListener('submit', e => {
        const submit = e.currentTarget.querySelector('[type=submit]');
        submit.disabled = true;
        submit.textContent = 'Signing in…';
    });
    window.addEventListener('pageshow', () => {
        const submit = document.querySelector('#login-form [type=submit]');
        if (submit) { submit.disabled = false; submit.textContent = 'Sign in to workspace →'; }
    });
})();
