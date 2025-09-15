<?php
// File: dashboard/student/footer.php
?>
      </main>

      <footer class="bg-secondary text-white text-center py-4 shadow-inner">
        &copy; <?= date('Y') ?> <strong>Artovue</strong> &middot; Powered by Rart Works
      </footer>
    </div>
  </div>

  <!-- Bootstrap JS Bundle -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Mobile menu toggle
    document.getElementById('mobileMenuToggle')
      .addEventListener('click', () => {
        document.getElementById('mobileMenu').classList.toggle('hidden');
      });

    // (Optional) Simple toast helper
    function showToast(title, msg) {
      const container = document.getElementById('toast-container');
      const el = document.createElement('div');
      el.className = 'toast align-items-center text-white bg-primary border-0 mb-2';
      el.setAttribute('role','alert');
      el.setAttribute('aria-live','assertive');
      el.setAttribute('aria-atomic','true');
      el.innerHTML = `
        <div class="d-flex">
          <div class="toast-body"><strong>${title}</strong><br>${msg}</div>
          <button type="button" class="btn-close btn-close-white me-2 m-auto"
                  data-bs-dismiss="toast"></button>
        </div>`;
      container.appendChild(el);
      new bootstrap.Toast(el,{ delay:5000 }).show();
    }
  </script>
</body>
</html>
