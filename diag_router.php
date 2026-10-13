<?php
// Keep the old diagnostic URL connected to the supported diagnostic interface.
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();
header('Location: hotspot_diagnostics.php', true, 302);
exit;
