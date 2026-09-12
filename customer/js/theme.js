/* Static entrypoints use the same tenant palette as PHP pages. */
(function () {
    fetch('../api/tenant/branding.php', {cache:'no-cache'})
        .then(function (response) { if (!response.ok) throw new Error('Theme unavailable'); return response.json(); })
        .then(function (branding) {
            if (!branding.theme) return;
            var root = document.documentElement;
            branding.theme.vars.split(';').forEach(function (declaration) {
                var colon = declaration.indexOf(':');
                if (colon < 0) return;
                var name = declaration.slice(0, colon).trim();
                if (/^--[a-z-]+$/.test(name)) root.style.setProperty(name, declaration.slice(colon + 1).trim());
            });
            root.style.setProperty('--customer-background', branding.theme.background);
        })
        .catch(function () { /* Keep readable defaults when offline. */ });
}());
