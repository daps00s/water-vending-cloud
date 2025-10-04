<?php
// footer.php
?>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Mobile menu toggle
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });
        
        <?php if(isset($chartData)): ?>
        // Chart initialization if chartData exists
        const ctx = document.getElementById('waterChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: <?php echo json_encode($chartData); ?>,
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
        <?php endif; ?>
        
        // Add button functionality
        document.querySelectorAll('.btn-primary').forEach(btn => {
            if(btn.textContent.includes('Add Machine')) {
                btn.addEventListener('click', () => {
                    window.location.href = 'add_machine.php';
                });
            }
        });
        
        document.querySelectorAll('.btn-secondary').forEach(btn => {
            if(btn.textContent.includes('Export')) {
                btn.addEventListener('click', () => {
                    // Implement export functionality
                    alert('Export functionality will be implemented here');
                });
            }
        });
    </script>
</body>
</html>