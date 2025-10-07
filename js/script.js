// Theme management
document.addEventListener('DOMContentLoaded', function() {
    // Theme selector
    const themeSelectors = document.querySelectorAll('.theme-selector');
    themeSelectors.forEach(selector => {
        selector.addEventListener('click', function(e) {
            e.preventDefault();
            const theme = this.getAttribute('data-theme');
            setTheme(theme);
        });
    });
    
    // Set theme
    function setTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        document.cookie = `theme=${theme}; path=/; max-age=31536000`; // 1 year
    }
    
    // Auto-refresh data every 30 seconds
    setInterval(() => {
        if (window.location.pathname.includes('dashboard.php')) {
            window.location.reload();
        }
    }, 30000);
    
    // Format phone numbers
    const phoneInputs = document.querySelectorAll('input[type="tel"]');
    phoneInputs.forEach(input => {
        input.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.startsWith('0')) {
                value = '+254' + value.substring(1);
            }
            e.target.value = value;
        });
    });
    
    // Data limit converter
    const dataLimitInput = document.getElementById('data_limit');
    if (dataLimitInput) {
        dataLimitInput.addEventListener('input', function(e) {
            const value = e.target.value;
            const gbValue = (value / 1024).toFixed(2);
            const tbValue = (value / 1048576).toFixed(2);
            
            let helperText = '';
            if (value >= 1048576) {
                helperText = `(${tbValue} TB)`;
            } else if (value >= 1024) {
                helperText = `(${gbValue} GB)`;
            }
            
            let helper = document.getElementById('data_limit_helper');
            if (!helper) {
                helper = document.createElement('div');
                helper.id = 'data_limit_helper';
                helper.className = 'form-text';
                dataLimitInput.parentNode.appendChild(helper);
            }
            helper.textContent = helperText;
        });
    }
});