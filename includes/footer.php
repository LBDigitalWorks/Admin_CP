</div> <!-- Closes the main container from header.php -->

<footer class="bg-gray-900 text-gray-300 mt-10">
  <div class="max-w-7xl mx-auto px-4 py-6 flex flex-col sm:flex-row items-center justify-between gap-4">
    
    <!-- Left: Copyright -->
    <span class="text-sm">
      &copy; <?= date('Y') ?> LB Admin Panel. All rights reserved.
    </span>

    <!-- Right: Footer nav -->
    <nav class="flex gap-4 text-sm">
      <a href="<?= url_to('reports.php') ?>" class="hover:text-white transition">Reports</a>
      <a href="<?= url_to('settings.php') ?>" class="hover:text-white transition">Settings</a>
      <a href="<?= url_to('help.php') ?>" class="hover:text-white transition">Help</a>
    </nav>

  </div>
</footer>

</body>
</html>
