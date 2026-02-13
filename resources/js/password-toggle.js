/**
 * Adds show/hide toggle to all password inputs on the page.
 */
function initPasswordToggle() {
    document.querySelectorAll('input[type="password"]').forEach((input) => {
        if (input.dataset.passwordToggle === 'init') return;
        if (input.closest('.input-group') || input.closest('.password-input-wrap')) return;
        input.dataset.passwordToggle = 'init';

        const wrapper = document.createElement('div');
        wrapper.className = 'password-input-wrap';
        input.parentNode.insertBefore(wrapper, input);
        wrapper.appendChild(input);
        input.style.paddingRight = '2.5rem';

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'password-toggle-btn';
        btn.setAttribute('aria-label', 'Toggle password visibility');
        btn.innerHTML = '<i class="bx bx-show"></i>';
        btn.addEventListener('click', function() {
            const isPass = input.type === 'password';
            input.type = isPass ? 'text' : 'password';
            btn.querySelector('i').className = isPass ? 'bx bx-hide' : 'bx bx-show';
        });
        wrapper.appendChild(btn);
    });
}
function runInit() {
    initPasswordToggle();
    setTimeout(initPasswordToggle, 500);
    setTimeout(initPasswordToggle, 1500);
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', runInit);
} else {
    runInit();
}
