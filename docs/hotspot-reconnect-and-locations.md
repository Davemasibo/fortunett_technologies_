# Hotspot reconnect and location reporting

The changes are local until the PHP application and generated hotspot pages are deployed. Use the existing router portal deployment flow to upload the updated `hotspot/login.html`; changing the server file alone does not replace a page already stored on a router.

## Reconnect

- Receipt and password reconnect check active device slots before provisioning. A full package reports `session_limit` and asks the customer to disconnect another device. It does not clear all other devices to make room.
- An expired receipt reports `time_exhausted`. Reconnecting cannot renew purchased time.
- An API-confirmed active device redirects without submitting a second router login. Otherwise the page says “Connecting” until RouterOS processes the login.
- Router queue-name collisions report `queue_conflict`, independently of session exhaustion. They do not justify taking another payment.
- If a collision persists, inspect the affected router's Hotspot active users, simple queues (name, dynamic flag and target), and the customer's profile login/logout scripts. This patch does not delete arbitrary queues. Existing static queues or custom router scripts can still require operator repair. The reported “6” is not evidence that six sessions were purchased or consumed.

## Credentials and package limits

New generated hotspot credentials use the full normalized phone number (`254712345678`) and a random four-digit PIN, including leading zeros. Existing credentials and explicitly supplied passwords remain unchanged. TV purchases retain separate device usernames. PPPoE password generation remains longer.

The public password endpoint limits attempts by tenant/account and source IP over five-minute windows. Direct RouterOS authentication does not pass through this PHP limiter; router-side login protection is still separate. Table `hotspot_login_attempts` is created on first use.

Packages retain their existing **wall-clock** policy: time starts at activation and runs while a customer is offline. A 30-minute purchase is 1,800 seconds, not 30 minutes per login. The router's individual deadline, login guard and five-second watchdog enforce expiry independently of browser activity. Shared-device aggregate uptime allows every purchased device to remain connected until that deadline. The scheduler is the precise cutoff; the watchdog is a backstop for missed events. Live RouterOS validation is required to confirm scheduling and throughput on the target hardware.

Profile speeds are router receive/transmit (customer upload/download). Legacy hotspot deployment now uses the canonical verified package profile and individual deadline instead of creating an uncapped, indefinitely active account with reversed rates.

## Traffic and sales

Open **Router Management → View hotspot location traffic**. Add readable location names in MikroTik interface comments. The live view reports interfaces, connected customers/sessions, interface traffic deltas, and active-session download totals. These are live samples, not historical bandwidth totals.

The sales view sums canonical completed hotspot payments for the selected day in Africa/Nairobi time. Checkout location is captured from the router bridge's observed device MAC/interface before sending the payment prompt. `hotspot_purchase_locations` stores the snapshot by tenant and checkout ID. Retries cannot replace it. Duplicate gateway transaction rows cannot multiply payment totals. The report also shows an explicitly labeled ISP-wide unattributed total for manual, historical and unlocated payments. Router failures do not block payment or invent a location.

If multiple APs share a switch uplink, the interface identifies that whole uplink, not an individual AP. Use separate ports/VLANs or add CAPsMAN/AP-specific attribution for distinct AP reporting. This implementation uses bridge/interface attribution; it does not claim CAPsMAN integration. Hardware-offloaded traffic may not all appear in CPU interface counters.

MikroTik references: [HotSpot profiles and limits](https://help.mikrotik.com/docs/spaces/ROS/pages/56459266/HotSpot%2B-%2BCaptive%2Bportal), [bridge host interface attribution](https://help.mikrotik.com/docs/spaces/ROS/pages/328068/Bridging%2Band%2BSwitching), [CAPsMAN](https://help.mikrotik.com/docs/spaces/ROS/pages/7962638/CAPsMAN).

## Verification

- `php tools/test_hotspot_onboarding.php`: simulated payment, provisioning, expiry and router handoff.
- `php tools/test_hotspot_reconnect.php`: device slots, PIN format, shared-device duration and interface attribution.
- `node tools/test_hotspot_handoff.js` and `node tools/test_hotspot_portal.js`: browser script execution and polling.
- `php tools/test_hotspot_sales_mysql.php`: creates and drops an isolated test schema; verifies sales deduplication, date/tenant boundaries, immutable attribution and PIN attempt windows. Optional `HOTSPOT_TEST_DSN`, `HOTSPOT_TEST_USER` and `HOTSPOT_TEST_PASSWORD` select a disposable database server.

These checks do not establish live router connectivity, real package throughput, or the absence of an existing conflicting queue.
