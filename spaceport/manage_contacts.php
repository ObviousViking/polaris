<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once('../db.php');
require_once '../includes/audit.php';
require_once '../includes/permissions.php';
require_permission($conn, 'manage_lookups');
require_once('../header.php');

$forces = [];
$r = $conn->query("SELECT id, force_name FROM forces ORDER BY force_name");
while ($row = $r->fetch_assoc()) { $forces[] = $row; }

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $role_title = trim($_POST['role_title']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $force_id = !empty($_POST['force_id']) ? intval($_POST['force_id']) : null;
    $contact_id = isset($_POST['contact_id']) ? intval($_POST['contact_id']) : 0;

    if ($name === '') {
        $message = "Name is required.";
    } elseif ($contact_id > 0) {
        $stmt = $conn->prepare("UPDATE external_contacts SET name=?, role_title=?, phone=?, email=?, force_id=? WHERE contact_id=?");
        $stmt->bind_param("ssssii", $name, $role_title, $phone, $email, $force_id, $contact_id);
        $stmt->execute();
        $stmt->close();
        log_audit_event($conn, 'external_contact', $contact_id, 'UPDATE', (int) $_SESSION['user_id'], json_encode(['name' => $name]));
        $message = "Contact updated.";
    } else {
        $stmt = $conn->prepare("INSERT INTO external_contacts (name, role_title, phone, email, force_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssi", $name, $role_title, $phone, $email, $force_id);
        $stmt->execute();
        $newId = $conn->insert_id;
        $stmt->close();
        log_audit_event($conn, 'external_contact', $newId, 'CREATE', (int) $_SESSION['user_id'], json_encode(['name' => $name]));
        $message = "New contact added.";
    }
} elseif (isset($_GET['deactivate'])) {
    $id = intval($_GET['deactivate']);
    $conn->query("UPDATE external_contacts SET is_active = 0 WHERE contact_id = " . $id);
    log_audit_event($conn, 'external_contact', $id, 'DEACTIVATE', (int) $_SESSION['user_id'], null);
    $message = "Contact deactivated.";
} elseif (isset($_GET['reactivate'])) {
    $id = intval($_GET['reactivate']);
    $conn->query("UPDATE external_contacts SET is_active = 1 WHERE contact_id = " . $id);
    log_audit_event($conn, 'external_contact', $id, 'REACTIVATE', (int) $_SESSION['user_id'], null);
    $message = "Contact reactivated.";
}

$contacts = [];
$res = $conn->query("SELECT ec.*, f.force_name FROM external_contacts ec LEFT JOIN forces f ON ec.force_id = f.id ORDER BY ec.is_active DESC, ec.name");
while ($row = $res->fetch_assoc()) { $contacts[] = $row; }
?>
<style>
    body { margin: 0; font-family: Arial, sans-serif; background: var(--polaris-bg); color: var(--polaris-text); padding-top: 120px; }
    .container { max-width: 900px; margin: 20px auto; background: var(--polaris-surface); padding: 20px; border-radius: 5px; box-shadow: 0 2px 10px rgba(255,255,255,0.1); }
    h2 { text-align: center; margin-bottom: 20px; }
    input[type="text"], input[type="email"], select { width: 100%; padding: 8px; margin-bottom: 10px; background: var(--polaris-bg); border: 1px solid var(--polaris-border); color: var(--polaris-text); box-sizing: border-box; }
    table { width: 100%; border-collapse: collapse; margin-top: 15px; }
    th, td { border: 1px solid var(--polaris-border); padding: 8px; text-align: left; }
    th { background: var(--polaris-divider); }
    .action-btn { background: var(--polaris-accent); color: var(--polaris-text); border: none; padding: 5px 10px; cursor: pointer; border-radius: 3px; text-decoration: none; display: inline-block; }
    .action-btn:hover { background: var(--polaris-accent-hover); }
    .message { margin-bottom: 10px; padding: 10px; background: var(--polaris-divider); border-left: 4px solid var(--polaris-accent); }
    .back-btn { display: inline-block; padding: 5px 10px; background: var(--polaris-accent); color: var(--polaris-text); border-radius: 3px; text-decoration: none; font-size: 14px; margin-bottom: 20px; }
</style>
<script>
function populateForm(id, name, role_title, phone, email, force_id) {
    document.getElementById("contact_id").value = id;
    document.getElementById("name").value = name;
    document.getElementById("role_title").value = role_title;
    document.getElementById("phone").value = phone;
    document.getElementById("email").value = email;
    document.getElementById("force_id").value = force_id;
}
</script>
<div class="container">
    <h2>Manage External Contacts</h2>
    <?php if (!empty($message)): ?>
    <div class="message"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="contact_id" id="contact_id">
        <label>Name</label>
        <input type="text" name="name" id="name" required>
        <label>Role / Title</label>
        <input type="text" name="role_title" id="role_title">
        <label>Phone</label>
        <input type="text" name="phone" id="phone">
        <label>Email</label>
        <input type="email" name="email" id="email">
        <label>Force</label>
        <select name="force_id" id="force_id">
            <option value="">None</option>
            <?php foreach ($forces as $f): ?>
            <option value="<?php echo $f['id']; ?>"><?php echo htmlspecialchars($f['force_name']); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="action-btn">Save</button>
    </form>

    <table>
        <thead><tr><th>Name</th><th>Role</th><th>Phone</th><th>Email</th><th>Force</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
            <?php foreach ($contacts as $c): ?>
            <tr>
                <td><?php echo htmlspecialchars($c['name']); ?></td>
                <td><?php echo htmlspecialchars($c['role_title'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($c['phone'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($c['email'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($c['force_name'] ?? ''); ?></td>
                <td><?php echo $c['is_active'] ? 'Active' : 'Inactive'; ?></td>
                <td>
                    <button class="action-btn" onclick="populateForm('<?php echo $c['contact_id']; ?>', '<?php echo htmlspecialchars($c['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($c['role_title'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($c['phone'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($c['email'], ENT_QUOTES); ?>', '<?php echo $c['force_id']; ?>')">Edit</button>
                    <?php if ($c['is_active']): ?>
                    <a class="action-btn" href="?deactivate=<?php echo $c['contact_id']; ?>">Deactivate</a>
                    <?php else: ?>
                    <a class="action-btn" href="?reactivate=<?php echo $c['contact_id']; ?>">Reactivate</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <br>
    <a href="/cargo_hold/manage_system_details.php" class="back-btn">Go Back</a>
</div>
