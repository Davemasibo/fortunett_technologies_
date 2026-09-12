# Shared tenant theme and customer UI

Published 2026-09-11. Router 19 reported CURRENT at 20:18:56 Africa/Nairobi, build `0b944725a972`. The VPS backup is `/root/fortunett-customer-theme-20260911-171844/backup/`; its staged manifest records SHA-256 hashes for the 21 deployed production files.

The dashboard's Hotspot appearance settings now supply the same normalized accent, background, wallpaper, light/dark card palette and radius to the captive portal and customer pages. An explicit hotspot accent takes precedence; general `brand_color` is the fallback when no hotspot accent was saved. Theme assets remain embedded for captive browser access. Customer pages apply changes on navigation; routers use their existing sync schedule or Save & Push.

The captive portal retains the simplified two-column layout, reconnect control, phone dialog, draft recovery and payment protections. Customer pages now use tenant-colored controls and surfaces, mobile navigation, readable forms, keyboard navigation controls, restrained shadows and responsive spacing. The dashboard labels subscription status accurately and shows full package duration and exact expiry date/time.

Validation:

- Eight production customer PHP templates rendered with synthetic data in Chrome, each with light and dark themes at 320, 390 and 1280 pixels: no horizontal overflow or JavaScript errors. Surface colors and tenant accent checked; mobile navigation opens/closes and identifies the active page.
- Sixteen PHP checks cover tenant isolation, general-brand fallback, matching captive/customer palettes, light/dark mode, CSS input normalization and readable accent button text.
- Five captive-layout browser scenarios and five payment/refresh browser scenarios passed. Existing simulated onboarding/expiry regressions and customer connection-status tests passed. Changed PHP files linted successfully; whitespace validation passed.
- Live server rendering confirmed tenant 9's customer/captive palette and background match, accent `#15f930`, and draft recovery remains present.
- Live HTTPS login, registration and renewal pages returned HTTP 200 and displayed the saved accent at 390 pixels without overflow or browser script errors. These were read-only checks; no purchase, customer-account mutation or payment prompt was initiated.

`artifacts/customer-login-live.png` is a live public-page capture. Other customer screenshots use synthetic data. Real-phone payment delivery and Internet packet cutoff remain outside these UI checks, as recorded in `HOTSPOT_PORTAL_VERIFICATION.md`.
