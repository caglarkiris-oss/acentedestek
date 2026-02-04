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
    // Close notifications if open
    document.getElementById('notifDropdown')?.classList.remove('open');
  }
  
  function toggleNotifications() {
    const dropdown = document.getElementById('notifDropdown');
    dropdown.classList.toggle('open');
    // Close user menu if open
    document.getElementById('userMenu')?.classList.remove('open');
  }
  
  function clearAllNotifs() {
    document.querySelectorAll('.notif-item').forEach(item => {
      item.classList.remove('unread');
    });
    const badge = document.getElementById('notifBadge');
    if (badge) badge.style.display = 'none';
  }
  
  function toggleDarkMode() {
    document.body.classList.toggle('dark-mode');
    const isDark = document.body.classList.contains('dark-mode');
    localStorage.setItem('dark-mode', isDark);
    const icon = document.getElementById('darkModeIcon');
    if (icon) {
      icon.setAttribute('data-lucide', isDark ? 'sun' : 'moon');
      lucide.createIcons();
    }
  }
  
  // Load saved states
  document.addEventListener('DOMContentLoaded', function() {
    if (localStorage.getItem('sidebar-collapsed') === 'true') {
      document.getElementById('sidebar')?.classList.add('collapsed');
    }
    
    if (localStorage.getItem('dark-mode') === 'true') {
      document.body.classList.add('dark-mode');
      const icon = document.getElementById('darkModeIcon');
      if (icon) {
        icon.setAttribute('data-lucide', 'sun');
        lucide.createIcons();
      }
    }
  });
  
  // Close dropdowns on outside click
  document.addEventListener('click', function(e) {
    if (!e.target.closest('.user-menu')) {
      document.getElementById('userMenu')?.classList.remove('open');
    }
    if (!e.target.closest('.notification-wrapper')) {
      document.getElementById('notifDropdown')?.classList.remove('open');
    }
  });
</script>
</body>
</html>
