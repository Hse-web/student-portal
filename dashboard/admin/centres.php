<?php
// File: dashboard/admin/centres.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../../config/db.php';

// Handle add/edit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $id = (int)($_POST['id'] ?? 0);
  $name = trim($_POST['name'] ?? '');

  if ($name === '') {
    set_flash('Centre name cannot be empty.', 'danger');
  } else {
    if ($id > 0) {
      $stmt = $conn->prepare("UPDATE centres SET name=? WHERE id=?");
      $stmt->bind_param('si', $name, $id);
      $stmt->execute();
      $stmt->close();
      set_flash('Centre updated.', 'success');
    } else {
      $stmt = $conn->prepare("INSERT INTO centres (name) VALUES (?)");
      $stmt->bind_param('s', $name);
      $stmt->execute();
      $stmt->close();
      set_flash('New centre added.', 'success');
    }
  }
  header('Location: ?page=centres');
  exit;
}

// Handle delete
if (isset($_GET['delete'])) {
  $deleteId = (int)$_GET['delete'];
  $stmt = $conn->prepare("DELETE FROM centres WHERE id=?");
  $stmt->bind_param('i', $deleteId);
  $stmt->execute();
  $stmt->close();
  set_flash('Centre deleted.', 'success');
  header('Location: ?page=centres');
  exit;
}

$flash = get_flash();
$centres = $conn->query("SELECT * FROM centres ORDER BY name")->fetch_all(MYSQLI_ASSOC);
?>
<div class="max-w-4xl mx-auto p-6 bg-white rounded-lg shadow space-y-6">
  <h2 class="text-2xl font-bold">🏫 Manage Centres</h2>

  <?php if ($flash): ?>
    <div class="p-3 bg-<?= $flash['type']==='danger'?'red':'green' ?>-100 
                border border-<?= $flash['type']==='danger'?'red':'green' ?>-400 
                text-<?= $flash['type']==='danger'?'red':'green' ?>-700 
                rounded">
      <?= htmlspecialchars($flash['msg']) ?>
    </div>
  <?php endif; ?>

  <!-- Add New Centre Form -->
  <form method="post" 
        class="flex flex-col md:flex-row items-start md:items-center gap-4">
    <input type="hidden" name="id" value="">
    <input type="text" name="name" placeholder="New Centre Name"
           class="w-full md:w-1/2 border p-2 rounded focus:outline-none"/>
    <button type="submit"
            class="w-full md:w-auto bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
      Add Centre
    </button>
  </form>

  <!-- Responsive Table Wrapper -->
  <div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200">
      <thead class="bg-gray-50">
        <tr>
          <th class="px-4 py-2 text-left text-sm font-medium text-gray-500 uppercase tracking-wider">
            Name
          </th>
          <th class="px-4 py-2 text-right text-sm font-medium text-gray-500 uppercase tracking-wider">
            Actions
          </th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
        <?php foreach ($centres as $c): ?>
        <tr class="hover:bg-gray-50">
          <!-- Name -->
          <td class="px-4 py-3 text-sm font-medium text-gray-800">
            <?= htmlspecialchars($c['name']) ?>
          </td>

          <!-- Actions cell -->
          <td class="px-4 py-3 text-sm text-right space-x-2">
            <form method="post" class="inline-flex flex-col sm:flex-row items-start sm:items-center gap-2">
              <input type="hidden" name="id" value="<?= $c['id'] ?>">
              <input type="text" name="name" value="<?= htmlspecialchars($c['name']) ?>"
                     class="border p-1 rounded w-full sm:w-48 focus:outline-none"/>
              <button type="submit" class="text-blue-600 hover:underline">Update</button>
            </form>
            <a href="?page=centres&delete=<?= $c['id'] ?>"
               class="text-red-600 hover:underline"
               onclick="return confirm('Delete this centre?')">
              Delete
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
