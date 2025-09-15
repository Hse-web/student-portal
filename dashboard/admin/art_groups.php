<?php
// File: dashboard/admin/art_groups.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../../config/db.php';

// ─── Handle POST (Add / Update / Delete) ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF
    if (! verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('Session expired. Please reload and try again.', 'danger');
        header('Location:?page=art_groups');
        exit;
    }

    $action = $_POST['action'] ?? '';

    // Add new group
    if ($action === 'add') {
        $slug      = trim($_POST['slug']);
        $label     = trim($_POST['label']);
        $sortOrder = (int)$_POST['sort_order'];

        $stmt = $conn->prepare("
          INSERT INTO art_groups (slug, label, sort_order)
          VALUES (?, ?, ?)
        ");
        $stmt->bind_param('ssi', $slug, $label, $sortOrder);
        $stmt->execute();
        $stmt->close();

        set_flash('Art group added.', 'success');
        header('Location:?page=art_groups');
        exit;
    }

    // Update existing
    if ($action === 'update' && !empty($_POST['id'])) {
        $id        = (int)$_POST['id'];
        $slug      = trim($_POST['slug']);
        $label     = trim($_POST['label']);
        $sortOrder = (int)$_POST['sort_order'];

        $stmt = $conn->prepare("
          UPDATE art_groups 
             SET slug = ?, label = ?, sort_order = ?
           WHERE id = ?
        ");
        $stmt->bind_param('ssii', $slug, $label, $sortOrder, $id);
        $stmt->execute();
        $stmt->close();

        set_flash('Art group updated.', 'success');
        header('Location:?page=art_groups');
        exit;
    }

    // Delete
    if ($action === 'delete' && !empty($_POST['id'])) {
        $id = (int)$_POST['id'];

        $stmt = $conn->prepare("DELETE FROM art_groups WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        set_flash('Art group deleted.', 'success');
        header('Location:?page=art_groups');
        exit;
    }
}

// ─── Fetch all groups ─────────────────────────────────────────────────────
$groups = $conn
    ->query("SELECT * FROM art_groups ORDER BY sort_order")
    ->fetch_all(MYSQLI_ASSOC);

// ─── New CSRF Token & Flash ────────────────────────────────────────────────
$csrf  = generate_csrf_token();
$flash = get_flash();
?>
<div class="bg-white p-6 rounded-lg shadow">
  <h2 class="text-2xl font-semibold mb-4">Manage Art Groups</h2>

  <?php if ($flash): ?>
    <div class="mb-4 p-3 bg-<?= $flash['type']==='danger'?'red':'green' ?>-100 
                border border-<?= $flash['type']==='danger'?'red':'green' ?>-400 
                text-<?= $flash['type']==='danger'?'red':'green' ?>-700 rounded">
      <?= htmlspecialchars($flash['msg']) ?>
    </div>
  <?php endif; ?>

  <!-- Add Form -->
  <form method="post" class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="action"     value="add">

    <input name="slug"      placeholder="Slug (e.g. beginners)" class="border p-2 rounded" required>
    <input name="label"     placeholder="Label (e.g. Beginners Group)" class="border p-2 rounded" required>
    <input name="sort_order" type="number" placeholder="Sort Order" class="border p-2 rounded" required>
    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:opacity-90">
      Add Group
    </button>
  </form>

  <!-- List & Edit -->
  <table class="w-full table-auto text-left">
    <thead class="bg-gray-100">
      <tr>
        <th class="px-4 py-2">#</th>
        <th class="px-4 py-2">Slug</th>
        <th class="px-4 py-2">Label</th>
        <th class="px-4 py-2">Order</th>
        <th class="px-4 py-2">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($groups as $g): ?>
        <tr class="border-t">
          <form method="post" class="flex items-center w-full">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action"     value="update">
            <input type="hidden" name="id"         value="<?= $g['id'] ?>">

            <td class="px-4 py-2"><?= $g['id'] ?></td>
            <td class="px-4 py-2">
              <input name="slug" value="<?= htmlspecialchars($g['slug']) ?>"
                     class="border p-1 rounded w-full" required>
            </td>
            <td class="px-4 py-2">
              <input name="label" value="<?= htmlspecialchars($g['label']) ?>"
                     class="border p-1 rounded w-full" required>
            </td>
            <td class="px-4 py-2">
              <input name="sort_order" type="number"
                     value="<?= $g['sort_order'] ?>"
                     class="border p-1 rounded w-16" required>
            </td>
            <td class="px-4 py-2 flex space-x-2">
              <button type="submit"
                      class="bg-green-600 text-white px-3 py-1 rounded hover:opacity-90">
                Update
              </button>
          </form>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action"     value="delete">
                <input type="hidden" name="id"         value="<?= $g['id'] ?>">
                <button type="submit"
                        class="bg-red-600 text-white px-3 py-1 rounded hover:opacity-90"
                        onclick="return confirm('Delete this group?')">
                  Delete
                </button>
              </form>
            </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
