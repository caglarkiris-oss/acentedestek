(function(){
  'use strict';

  // ===== SIDEBAR COLLAPSE TOGGLE =====
  function initSidebar() {
    const appShell = document.getElementById('appShell') || document.querySelector('.app-shell');
    
    if (!appShell) {
      console.warn('App shell not found');
      return;
    }
    
    // Check for saved preference
    const savedState = localStorage.getItem('sidebar-collapsed');
    if (savedState === 'true') {
      appShell.classList.add('sidebar-collapsed');
    }

    // Sidebar toggle handler - use event delegation
    document.body.addEventListener('click', function(e) {
      const toggle = e.target.closest('[data-sidebar-toggle]');
      if (!toggle) return;
      
      // Check if mobile (sidebar slides in/out) or desktop (collapse/expand)
      const isMobile = window.innerWidth <= 1024;
      
      if (isMobile) {
        appShell.classList.toggle('sidebar-open');
      } else {
        appShell.classList.toggle('sidebar-collapsed');
        // Save preference
        localStorage.setItem('sidebar-collapsed', appShell.classList.contains('sidebar-collapsed'));
      }
      
      e.preventDefault();
      e.stopPropagation();
    });

    // Close sidebar on mobile when clicking overlay
    document.body.addEventListener('click', function(e) {
      if (e.target.classList && e.target.classList.contains('sidebar-overlay')) {
        appShell.classList.remove('sidebar-open');
      }
    });

    // Add overlay element if not exists
    if (!document.querySelector('.sidebar-overlay')) {
      const overlay = document.createElement('div');
      overlay.className = 'sidebar-overlay';
      document.body.appendChild(overlay);
    }
  }

  // ===== TOAST NOTIFICATIONS =====
  window.showToast = function(msg, type, duration) {
    let el = document.getElementById('toast');
    if (!el) {
      el = document.createElement('div');
      el.id = 'toast';
      el.className = 'toast';
      document.body.appendChild(el);
    }
    
    el.textContent = msg || '';
    el.className = 'toast show';
    if (type) {
      el.classList.add(type);
    }
    
    clearTimeout(el._t);
    el._t = setTimeout(function() {
      el.classList.remove('show');
    }, duration || 3000);
  };

  // ===== DROPDOWN MENUS =====
  document.addEventListener('click', function(e) {
    const ddToggle = e.target.closest('[data-dd-toggle]');
    
    if (ddToggle) {
      const dd = ddToggle.closest('.dd');
      if (dd) {
        // Close all other dropdowns
        document.querySelectorAll('.dd.is-open').forEach(function(n) {
          if (n !== dd) n.classList.remove('is-open');
        });
        dd.classList.toggle('is-open');
      }
      e.preventDefault();
      e.stopPropagation();
      return;
    }
    
    // Close all dropdowns when clicking outside
    if (!e.target.closest('.dd')) {
      document.querySelectorAll('.dd.is-open').forEach(function(n) {
        n.classList.remove('is-open');
      });
    }
  });

  // ===== COPYABLE TABLE COLUMNS =====
  (function() {
    function fallbackCopy(text) {
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.left = '-9999px';
      document.body.appendChild(ta);
      ta.select();
      try {
        document.execCommand('copy');
      } catch (e) {
        console.error('Copy failed:', e);
      }
      document.body.removeChild(ta);
    }

    function copyText(text) {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).catch(function() {
          fallbackCopy(text);
        });
      } else {
        fallbackCopy(text);
      }
    }

    document.addEventListener('click', function(e) {
      const th = e.target.closest('th');
      if (!th) return;
      
      const table = th.closest('table.copyable-table');
      if (!table) return;
      
      const tr = th.parentElement;
      const ths = Array.prototype.slice.call(tr.children);
      const colIndex = ths.indexOf(th);
      
      if (colIndex < 0) return;
      
      const rows = table.querySelectorAll('tbody tr');
      const values = [];
      
      rows.forEach(function(r) {
        const cells = r.children;
        if (!cells || colIndex >= cells.length) return;
        
        const cell = cells[colIndex];
        const inp = cell.querySelector('input, textarea, select');
        let v = '';
        
        if (inp) {
          v = (inp.value || '').trim();
        } else {
          v = (cell.innerText || '').trim();
        }
        
        if (v !== '') {
          values.push(v);
        }
      });
      
      if (values.length === 0) {
        showToast('Kopyalanacak veri yok.', 'warning');
        return;
      }
      
      copyText(values.join('\n'));
      showToast('"' + ((th.innerText || 'Kolon').trim()) + '" sütunu kopyalandı (' + values.length + ' satır).', 'success');
    });
  })();

  // ===== RESPONSIVE HELPERS =====
  function handleResize() {
    const appShell = document.getElementById('appShell') || document.querySelector('.app-shell');
    const isMobile = window.innerWidth <= 1024;
    
    if (isMobile && appShell) {
      // Ensure sidebar is closed on mobile when resizing down
      appShell.classList.remove('sidebar-collapsed');
    }
  }

  window.addEventListener('resize', debounce(handleResize, 150));

  // ===== UTILITY: DEBOUNCE =====
  function debounce(func, wait) {
    let timeout;
    return function executedFunction() {
      const context = this;
      const args = arguments;
      const later = function() {
        timeout = null;
        func.apply(context, args);
      };
      clearTimeout(timeout);
      timeout = setTimeout(later, wait);
    };
  }

  // ===== FORM ENHANCEMENTS =====
  // Auto-focus first input in modals
  document.addEventListener('click', function(e) {
    const modal = e.target.closest('.modal');
    if (modal) {
      const firstInput = modal.querySelector('input:not([type="hidden"]), textarea, select');
      if (firstInput) {
        setTimeout(function() {
          firstInput.focus();
        }, 100);
      }
    }
  });

  // ===== ESCAPE KEY HANDLER =====
  document.addEventListener('keydown', function(e) {
    const appShell = document.getElementById('appShell') || document.querySelector('.app-shell');
    if (e.key === 'Escape') {
      // Close dropdowns
      document.querySelectorAll('.dd.is-open').forEach(function(n) {
        n.classList.remove('is-open');
      });
      
      // Close mobile sidebar
      if (appShell) {
        appShell.classList.remove('sidebar-open');
      }
      
      // Close modals
      document.querySelectorAll('.modal-overlay.show, .modal-backdrop.show').forEach(function(m) {
        m.classList.remove('show');
      });
    }
  });

  // ===== SMOOTH SCROLL FOR ANCHOR LINKS =====
  document.addEventListener('click', function(e) {
    const link = e.target.closest('a[href^="#"]');
    if (!link) return;
    
    const targetId = link.getAttribute('href').slice(1);
    const target = document.getElementById(targetId);
    
    if (target) {
      e.preventDefault();
      target.scrollIntoView({
        behavior: 'smooth',
        block: 'start'
      });
    }
  });

  // ===== TOOLTIP INITIALIZATION =====
  // Add tooltips to nav items for collapsed sidebar
  function initTooltips() {
    document.querySelectorAll('.nav-item').forEach(function(item) {
      const text = item.querySelector('span:not(.nav-icon)');
      if (text) {
        item.setAttribute('data-tooltip', text.textContent.trim());
      }
    });
  }

  // ===== INITIALIZE ON DOM READY =====
  function init() {
    initSidebar();
    initTooltips();
    handleResize();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
