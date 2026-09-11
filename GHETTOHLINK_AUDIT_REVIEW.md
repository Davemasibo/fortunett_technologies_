# Ghettohlink customer audit review

Snapshot: 2026-09-11T08:02:04+03:00; tenant 9, router 19. All 67 customer records reviewed. This is a historical snapshot, not a fresh live verification.

The receipt reconnect endpoint was found to extend expiry on every submission and return success despite router failure. The local fix preserves expiry and requires verified provisioning. The snapshot alone does not establish which receipt submissions caused each extended deadline.

| Customer | ID | Status at audit | Active sessions | Findings |
|---|---:|---|---:|---|
| G066 | 443 | inactive | 0 | NO_RECORDED_CONNECTION |
| G065 | 441 | inactive | 0 |  |
| G064 | 440 | active | 0 | PAID_ACCESS_NO_ACTIVE_SESSION, NO_RECENT_DEVICE_FOR_AUTOMATIC_LOGIN |
| G063 | 439 | active | 0 | PAID_ACCESS_NO_ACTIVE_SESSION, ROUTER_USAGE_WITHOUT_LAST_SEEN_TIMESTAMP, NO_RECENT_DEVICE_FOR_AUTOMATIC_LOGIN |
| G062 | 438 | inactive | 0 | NO_RECORDED_CONNECTION |
| G061 | 437 | inactive | 0 | ROUTER_PROFILE_DIFFERS_FROM_CURRENT_PACKAGE |
| G060 | 436 | inactive | 0 | ROUTER_USAGE_WITHOUT_LAST_SEEN_TIMESTAMP |
| G059 | 435 | pending | 0 | NO_RECORDED_CONNECTION |
| G058 | 434 | inactive | 0 | ROUTER_USAGE_WITHOUT_LAST_SEEN_TIMESTAMP |
| G057 | 433 | pending | 0 | NO_PACKAGE, NO_RECORDED_CONNECTION |
| G056 | 432 | inactive | 0 | NO_RECORDED_CONNECTION |
| G055 | 431 | inactive | 0 | NO_RECORDED_CONNECTION |
| G054 | 430 | inactive | 0 | ROUTER_USAGE_WITHOUT_LAST_SEEN_TIMESTAMP |
| G053 | 429 | pending | 0 | NO_RECORDED_CONNECTION |
| G052 | 428 | inactive | 0 | NO_RECORDED_CONNECTION |
| G051 | 427 | inactive | 0 | ROUTER_USAGE_WITHOUT_LAST_SEEN_TIMESTAMP |
| G050 | 426 | inactive | 0 | ROUTER_USAGE_WITHOUT_LAST_SEEN_TIMESTAMP |
| G049 | 425 | inactive | 0 | NO_RECORDED_CONNECTION |
| G048 | 424 | inactive | 0 | ROUTER_USAGE_WITHOUT_LAST_SEEN_TIMESTAMP |
| G047 | 423 | inactive | 0 |  |
| G046 | 421 | inactive | 0 | ROUTER_USAGE_WITHOUT_LAST_SEEN_TIMESTAMP, ROUTER_PROFILE_DIFFERS_FROM_CURRENT_PACKAGE |
| G045 | 420 | inactive | 0 | NO_RECORDED_CONNECTION |
| G044 | 419 | inactive | 0 |  |
| G043 | 418 | pending | 0 | NO_RECORDED_CONNECTION |
| G042 | 417 | inactive | 0 |  |
| G041 | 416 | active | 0 | PAID_ACCESS_NO_ACTIVE_SESSION, NO_RECENT_DEVICE_FOR_AUTOMATIC_LOGIN |
| G040 | 415 | inactive | 0 | NO_RECORDED_CONNECTION |
| G039 | 414 | pending | 0 | NO_RECORDED_CONNECTION |
| G038 | 413 | inactive | 0 |  |
| G037 | 412 | pending | 0 | NO_RECORDED_CONNECTION |
| G036 | 409 | inactive | 0 |  |
| G035 | 408 | inactive | 0 | ROUTER_PROFILE_DIFFERS_FROM_CURRENT_PACKAGE |
| G034 | 407 | inactive | 0 | NO_RECORDED_CONNECTION |
| G033 | 406 | inactive | 0 | ROUTER_USAGE_WITHOUT_LAST_SEEN_TIMESTAMP |
| G032 | 405 | inactive | 0 | ROUTER_PROFILE_DIFFERS_FROM_CURRENT_PACKAGE |
| G031 | 404 | pending | 0 | NO_RECORDED_CONNECTION |
| G030 | 403 | inactive | 0 |  |
| G029 | 402 | inactive | 0 | ROUTER_USAGE_WITHOUT_LAST_SEEN_TIMESTAMP |
| G028 | 401 | inactive | 0 | ROUTER_PROFILE_DIFFERS_FROM_CURRENT_PACKAGE |
| G027 | 400 | active | 0 | PAID_ACCESS_NO_ACTIVE_SESSION, NO_RECENT_DEVICE_FOR_AUTOMATIC_LOGIN |
| G026 | 399 | active | 1 | NO_RECENT_DEVICE_FOR_AUTOMATIC_LOGIN |
| G025 | 398 | inactive | 0 |  |
| G024 | 397 | inactive | 0 |  |
| G023 | 396 | inactive | 0 | NO_RECORDED_CONNECTION, ROUTER_PROFILE_DIFFERS_FROM_CURRENT_PACKAGE |
| G022 | 395 | inactive | 0 | ROUTER_PROFILE_DIFFERS_FROM_CURRENT_PACKAGE |
| G021 | 394 | pending | 0 | NO_RECORDED_CONNECTION |
| G020 | 393 | active | 0 | NO_RECENT_DEVICE_FOR_AUTOMATIC_LOGIN, ACTIVE_ACCESS_WITHOUT_COMPLETED_PAYMENT_RECORD |
| G019 | 392 | inactive | 0 | NO_RECORDED_CONNECTION |
| G018 | 391 | inactive | 0 | ROUTER_PROFILE_DIFFERS_FROM_CURRENT_PACKAGE |
| G017 | 390 | pending | 0 | NO_RECORDED_CONNECTION |
| G016 | 389 | active | 0 | PAID_ACCESS_NO_ACTIVE_SESSION, NO_RECENT_DEVICE_FOR_AUTOMATIC_LOGIN |
| G015 | 388 | pending | 0 | NO_RECORDED_CONNECTION |
| G014 | 387 | inactive | 0 | NO_RECORDED_CONNECTION |
| G013 | 386 | active | 0 | PAID_ACCESS_NO_ACTIVE_SESSION, NO_RECENT_DEVICE_FOR_AUTOMATIC_LOGIN |
| G012 | 385 | inactive | 0 |  |
| G011 | 384 | pending | 0 | NO_RECORDED_CONNECTION |
| G010 | 383 | inactive | 0 |  |
| G009 | 382 | pending | 0 | NO_RECORDED_CONNECTION |
| (missing) | 371 | inactive | 0 | NO_RECORDED_CONNECTION |
| G008 | 190 | inactive | 0 | ROUTER_PROFILE_DIFFERS_FROM_CURRENT_PACKAGE |
| G007 | 189 | inactive | 0 |  |
| G006 | 187 | inactive | 0 |  |
| G005 | 176 | suspended | 0 | ROUTER_PROFILE_DIFFERS_FROM_CURRENT_PACKAGE |
| G004 | 160 | suspended | 0 | ROUTER_PROFILE_DIFFERS_FROM_CURRENT_PACKAGE |
| G003 | 159 | inactive | 0 | NO_RECORDED_CONNECTION |
| G002 | 158 | inactive | 0 | ROUTER_USAGE_WITHOUT_LAST_SEEN_TIMESTAMP, ROUTER_PROFILE_DIFFERS_FROM_CURRENT_PACKAGE |
| G001 | 157 | inactive | 0 | ROUTER_PROFILE_DIFFERS_FROM_CURRENT_PACKAGE |

No expired-but-online account was reported in this snapshot. No recent device context was recorded for any customer. Router uptime with a null last_seen establishes historical usage, not its timestamp.

Historical payment and expiry corrections require exact receipt/checkout linkage and original terms. The supplied export omits those reference links and most purchases predate stored terms. Do not replay old receipts from today or infer historical duration from current package names.

Deployment and live device Internet-traffic verification remain outstanding.
