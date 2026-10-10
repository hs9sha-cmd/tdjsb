<?php
// includes/footer.php
?>
            </div> <!-- End content-area -->
            
            <?php 
            $current_basename = basename($_SERVER['PHP_SELF']);
            if (isset($_SESSION['roles']) && (in_array('teacher', $_SESSION['roles']) || in_array('admin', $_SESSION['roles'])) && $current_basename !== 'teacher_dashboard.php'): 
            ?>
            <!-- Quick Link to full Dashboard -->
            <div class="no-print" style="text-align: center; margin-top: 10px; margin-bottom: 20px;">
                <a href="<?= isset($base_url) ? $base_url : '' ?>/src/actions/teacher_dashboard.php" style="color: var(--text-muted); font-size: 0.82rem; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                    <span class="material-symbols-rounded" style="font-size: 18px;">dashboard</span>
                    ดูข้อมูลเชิงลึก (Dashboard เดิม)
                </a>
            </div>
            <?php endif; ?>

            <?php include __DIR__ . '/bottom_nav.php'; ?>

            <!-- Global Footer -->
            <footer class="site-footer" style="padding: 20px 15px 30px; text-align: center; color: var(--text-sidebar-muted); font-size: 0.75rem; border-top: 1px dashed var(--border-sidebar); background-color: var(--bg-sidebar); margin-top: auto; line-height: 1.6;">
                <div style="font-weight: 500; color: var(--text-main); display: flex; align-items: center; justify-content: center; gap: 4px;">
                    2026 &copy; HS9SHA 
                    <span class="material-symbols-rounded" style="font-size: 14px; color: #ef4444; font-variation-settings: 'FILL' 1;">favorite</span> 
                    Wednesday LLC.
                </div>
                <div>M&M Service - All Rights Reserved.</div>
                <div style="opacity: 0.8;">Tel. 0817381914</div>
            </footer>
            
        </main> <!-- End main-content -->
    </div> <!-- End app-wrapper -->
    
    <script src="<?= $base_url ?>/assets/js/main.js?v=<?= time() ?>"></script>
</body>
</html>
