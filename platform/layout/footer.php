      </div>
    </main>
  </div>
</div>

<!-- Toast Container -->
<div id="toast" class="toast" aria-live="polite" aria-atomic="true"></div>

<!-- App JavaScript -->
<script src="<?= base_url('layout/app.js') ?>"></script>

<!-- Initialize Lucide Icons -->
<script>
  document.addEventListener('DOMContentLoaded', function() {
    if (typeof lucide !== 'undefined') {
      lucide.createIcons();
    }
  });
  // Also run immediately in case DOM is already ready
  if (document.readyState !== 'loading') {
    if (typeof lucide !== 'undefined') {
      lucide.createIcons();
    }
  }
</script>
</body>
</html>
