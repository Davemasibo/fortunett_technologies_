<?php
/** Legacy schedule retained as a harmless no-op. Time alone is not proof of a payout. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
echo "Age-based release is disabled. Record completed transfers in Super Admin > Collections > Held for ISPs, or use confirmed B2C payouts.\n";
