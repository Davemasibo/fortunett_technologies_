<?php if (isLoggedIn()): ?>
    <style>
        .main-layout {
            display: flex;
            flex-direction: column;
            min-height: calc(100vh - var(--navbar-height, 60px));
        }
        .main-content-wrapper {
            flex: 1;
        }
        footer {
            width: 100%;
            background: #f8f9fa;
            border-top: 1px solid #dee2e6;
            margin-top: auto;
        }
    </style>
    <footer class="text-center py-4 text-muted small">
        <div class="container">
            <p class="mb-1">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($tSettings['company_name'] ?? $tenant['company_name'] ?? 'FN Tech'); ?>. All rights reserved.</p>
            <?php if (!empty($tSettings['support_number']) || !empty($tSettings['support_email'])): ?>
                <p class="mb-0">
                    <i class="fas fa-headset me-1"></i> Support: 
                    <?php if (!empty($tSettings['support_number'])): ?>
                        <span class="me-3"><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($tSettings['support_number']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($tSettings['support_email'])): ?>
                        <span><i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($tSettings['support_email']); ?></span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
    </footer>
</div> <!-- End main-layout -->
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>