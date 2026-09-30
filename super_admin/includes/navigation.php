<?php
$saCurrentPage = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
if ($saCurrentPage === 'disbursements.php') $saCurrentPage = 'collections.php';
$saLinks = [
    ['index.php', 'Overview', 'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z'],
    ['tenants.php', 'Tenants', 'M4 21V3h12v18 M16 9h4v12 M8 7h4 M8 11h4 M8 15h4 M2 21h20'],
    ['billing.php', 'Platform billing', 'M6 3h12v18l-3-2-3 2-3-2-3 2V3z M9 7h6 M9 11h6'],
    ['collections.php', 'Collections & payouts', 'M3 7h18v13H3z M3 7l14-4v4 M16 12h5v4h-5z'],
    ['plans.php', 'Subscription plans', 'M12 3l10 5-10 5L2 8z M2 12l10 5 10-5 M2 16l10 5 10-5'],
    ['mpesa.php', 'M-Pesa', 'M7 2h10v20H7z M10 5h4 M11 18h2'],
    ['diagnostics.php', 'Diagnostics', 'M2 12h5l3-8 4 16 3-8h5'],
    ['settings.php', 'Settings', 'M4 7h16 M4 17h16 M8 4v6 M16 14v6'],
];
?>
<a class="sa-skip" href="#sa-main-content">Skip to content</a>
<aside class="sidebar" id="sa-navigation" aria-label="Platform navigation">
    <button class="sa-drawer-close" type="button" aria-label="Close navigation">×</button>
    <a class="sidebar-brand" href="index.php">
        <span class="sa-brand-mark" aria-hidden="true">F</span>
        <span class="sa-brand-copy"><strong>FortuNett</strong><small>Platform administration</small></span>
    </a>
    <div class="sa-nav-label">WORKSPACE</div>
    <nav class="sa-nav-links" aria-label="Main navigation"><ul class="sidebar-menu">
    <?php foreach ($saLinks as [$href, $label, $path]): ?>
        <li><a href="<?= $href ?>" <?= $saCurrentPage === $href ? 'class="active" aria-current="page"' : '' ?> data-label="<?= htmlspecialchars($label) ?>" aria-label="<?= htmlspecialchars($label) ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="<?= $path ?>"/></svg><span><?= htmlspecialchars($label) ?></span>
        </a></li>
    <?php endforeach; ?>
    </ul></nav>
    <div class="sidebar-footer">
        <div class="sa-account"><span class="sa-account-avatar" aria-hidden="true"><?= htmlspecialchars(strtoupper(substr($_SESSION['username'] ?? 'A', 0, 1))) ?></span><span><strong><?= htmlspecialchars($_SESSION['username'] ?? 'Administrator') ?></strong><small>Super administrator</small></span></div>
        <a href="logout.php" class="sa-signout">Sign out <span aria-hidden="true">↗</span></a>
    </div>
</aside>
