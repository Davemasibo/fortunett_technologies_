    </div> <!-- End content-wrapper -->

    <footer class="text-center py-4 text-muted small mt-auto border-top">
        <div class="container">
            <p class="mb-1">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($tenant_branding['company_name'] ?? 'ISP System'); ?>. All rights reserved.</p>
            <?php if (!empty($tenant_branding['support_number']) || !empty($tenant_branding['support_email'])): ?>
                <p class="mb-0">
                    <i class="fas fa-headset me-1"></i> Support: 
                    <?php if (!empty($tenant_branding['support_number'])): ?>
                        <span class="me-3"><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($tenant_branding['support_number']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($tenant_branding['support_email'])): ?>
                        <span><i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($tenant_branding['support_email']); ?></span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
    </footer>
    </main>
    </div> <!-- End portal-wrapper -->
    
    <script>
        // Close sidebar on mobile when clicking outside
        document.addEventListener('click', (e) => {
            const sidebar = document.getElementById('sidebar');
            const menuToggle = document.querySelector('.menu-toggle');
            
            if (window.innerWidth <= 768 && sidebar.classList.contains('active') && !sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
                sidebar.classList.remove('active');
            }
        });
    </script>
</body>
</html>
