      </div>
    </main>
  </div>
</div>

<script>
  // Initialize Lucide icons after DOM is ready
  document.addEventListener('DOMContentLoaded', function() {
    lucide.createIcons();
  });
  
  function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    sidebar.classList.toggle('collapsed');
    localStorage.setItem('sidebar-collapsed', sidebar.classList.contains('collapsed'));
  }
  
  function toggleMobileSidebar() {
    const sidebar = document.getElementById('sidebar');
    sidebar.classList.toggle('mobile-open');
  }
  
  function toggleUserMenu() {
    const menu = document.getElementById('userMenu');
    menu.classList.toggle('open');
  }
  
  // Load saved state
  if (localStorage.getItem('sidebar-collapsed') === 'true') {
    document.getElementById('sidebar').classList.add('collapsed');
  }
  
  // Close dropdown on outside click
  document.addEventListener('click', function(e) {
    if (!e.target.closest('.user-menu')) {
      document.getElementById('userMenu').classList.remove('open');
    }
  });
</script>
</body>
</html>
