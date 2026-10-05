FortuNett email delivery and design
=================================

Tenant onboarding, welcome, verification, password reset, billing and account
status emails use the shared design in includes/email_brand.php. Customer messages
and reports sent by EmailHelper receive the same frame while retaining their
content, report styles and configured sender identity. Layout uses tables, inline
styles, a text wordmark, readable summaries and a blue action button. Links also
appear in the plain-text MIME part. No new database migration is needed.

Generate previews without a database or sending mail:

    php tools/preview_emails.php

Open artifacts/email-preview/onboarding.html or customer.html. Preview names,
addresses and tokens are synthetic. Browser previews are not Gmail/Outlook tests.

SMTP behavior
-------------

All mail now uses authenticated SMTP. Port 465 uses implicit TLS; port 587 uses
STARTTLS. UTF-8 preserves currency, names and punctuation. Failures no longer
silently fall back to PHP mail() or simulated localhost success. Billing outbox
status reflects SMTP acceptance rather than always claiming success. SMTP
acceptance does not guarantee inbox placement.

Platform configuration precedence: a complete MAIL_HOST + MAIL_USERNAME pair in
.env, then active platform_email_config, then legacy email_settings. Customer
mail retains active tenant email_configurations, falling back to platform config.
An environment-selected SMTP configuration needs its own MAIL_PASSWORD; it does
not borrow a password from a different database configuration.

Supported environment keys:

    MAIL_HOST=smtp.gmail.com
    MAIL_PORT=587
    MAIL_USERNAME=your-existing-gmail-account@gmail.com
    MAIL_PASSWORD=your-app-password
    MAIL_FROM_ADDRESS=your-existing-gmail-account@gmail.com
    MAIL_FROM_NAME="FortuNett Technologies"
    MAIL_REPLY_TO=support@fortunetttech.site

Use a real working support address. Do not change the configured From to a
FortuNett-domain address until your sending service authorizes that identity.
For Google Workspace or a transactional provider, use its SMTP settings and
verified domain sender instead. Never commit real passwords.

Diagnosing the reported Gmail message
------------------------------------

The reported From is fortunettech1@gmail.com, mailed-by gmail.com, signed-by
gmail.com, with TLS. These details do not state why Gmail classified it as spam.
Open Gmail > More > Show original and inspect SPF, DKIM, DMARC and the spam
banner's explanation. Do not share verification/reset tokens or credentials.
Google manages authentication DNS for gmail.com. FortuNett-domain SPF/DMARC
changes do not authenticate this Gmail From address. Ask consenting recipients
to mark legitimate messages as Not spam; do not repeatedly resend the same
reminder while investigating. Monitor bounces, complaints and sender reputation.

For a branded domain sender, verify fortunetttech.site with the actual outbound
provider, publish its DKIM records and add its authorized sender to the ONE SPF
record. Retain other required includes. Add a monitoring DMARC record such as
v=DMARC1; p=none initially; only add rua for a mailbox you actually monitor.
Move to enforcement after you have verified all legitimate senders align. DKIM
selector values and SPF includes must come from the chosen provider, not guesses.
Cloudflare routing MX records describe inbound routing, not the app's SMTP sender.
Direct VPS delivery additionally requires valid forward/reverse DNS and reputation.

On the production server, this read-only command prints no credentials and sends
no messages:

    php tools/email_delivery_diagnostics.php

For a custom-domain sender with a provider-supplied DKIM selector:

    php tools/email_delivery_diagnostics.php selector

Official guidance:
https://support.google.com/mail/answer/81126
https://developers.cloudflare.com/dns/manage-dns-records/how-to/email-records/

Deploy, then use the existing Super Admin SMTP test once to a mailbox you own.
Inspect the message's authentication and placement. Existing successful reminder
stages remain recorded: upgrading templates does not resend those messages.
