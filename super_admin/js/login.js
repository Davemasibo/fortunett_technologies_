document.getElementById('sa-show-password').addEventListener('click', function () {
    const field = document.getElementById('su-pass');
    const show = field.type === 'password';
    field.type = show ? 'text' : 'password';
    this.textContent = show ? 'Hide' : 'Show';
    this.setAttribute('aria-pressed', String(show));
});
document.getElementById('sa-login-form').addEventListener('submit', function () {
    const button = document.getElementById('sa-login-submit');
    button.disabled = true;
    button.textContent = 'Signing in…';
});
