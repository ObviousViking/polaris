<?php
// includes/spaceport.php
//
// Shared logic for the Spaceport submissions portal: display refs,
// notifications (routed through one function per event so email can be
// added later with no data-model change), and the submission -> job
// approval copy.

require_once __DIR__ . '/integrity.php';
require_once __DIR__ . '/settings.php';

function submission_ref(int $submissionId): string
{
    return 'SUB-' . str_pad((string) $submissionId, 6, '0', STR_PAD_LEFT);
}

// v1 visibility is deliberately simple: an officer sees only the
// submissions they filed themselves (submitted_by = them). Broader access -
// e.g. a case_access allow-list so more people can be granted visibility
// into a restricted case - is noted future work, not built yet.
function user_can_view_submission(array $submission, int $userId, bool $canReviewAll): bool
{
    return $canReviewAll || (int) $submission['submitted_by'] === $userId;
}

// Renders a submission_history `changes` JSON blob as readable HTML instead
// of a raw JSON dump - old/new pairs as a diff, plain values as a line,
// nulls/empties skipped. Same rendering shape as
// cargo_hold/view_case_item_history.php.
function render_history_changes_html(?string $json): string
{
    $changes = json_decode((string) $json, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($changes)) {
        return '';
    }

    $html = '';
    foreach ($changes as $field => $value) {
        $label = htmlspecialchars(ucwords(str_replace('_', ' ', (string) $field)));
        if (is_array($value) && array_key_exists('old', $value) && array_key_exists('new', $value)) {
            $old = $value['old'] === null || $value['old'] === '' ? '(none)' : (string) $value['old'];
            $new = $value['new'] === null || $value['new'] === '' ? '(none)' : (string) $value['new'];
            $html .= "<div class='change-row'><strong>$label:</strong> <span class='change-old'>" . htmlspecialchars($old) . "</span> &rarr; <span class='change-new'>" . htmlspecialchars($new) . "</span></div>";
        } else {
            if ($value === null || $value === '') {
                continue;
            }
            $html .= "<div class='change-row'><strong>$label:</strong> " . htmlspecialchars((string) $value) . "</div>";
        }
    }
    return $html !== '' ? $html : '<span class="change-none">&mdash;</span>';
}

// Every version of a submission's subject photo, newest first - the
// current one is simply the highest `version`.
function get_subject_photos(mysqli $conn, int $submissionId): array
{
    $photos = [];
    $stmt = $conn->prepare("
        SELECT sp.photo_id, sp.version, sp.original_filename, sp.uploaded_at,
               CONCAT(u.first_name, ' ', u.last_name) AS uploaded_by_name
        FROM subject_photos sp
        LEFT JOIN users u ON sp.uploaded_by = u.id
        WHERE sp.submission_id = ?
        ORDER BY sp.version DESC
    ");
    $stmt->bind_param("i", $submissionId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $photos[] = $row;
    }
    $stmt->close();
    return $photos;
}

// Every status-change notification goes through here - adding email later
// is one additive call inside this function, not a hunt across handlers.
function notify_submission_status_changed(mysqli $conn, int $submissionId, string $event, ?string $note = null): void
{
    $stmt = $conn->prepare("SELECT submitted_by FROM submissions WHERE submission_id = ?");
    $stmt->bind_param("i", $submissionId);
    $stmt->execute();
    $stmt->bind_result($submittedBy);
    $stmt->fetch();
    $stmt->close();
    if (!$submittedBy) {
        return;
    }

    $ref = submission_ref($submissionId);
    $messages = [
        'approved' => "Submission $ref was approved.",
        'rejected' => "Submission $ref was rejected." . ($note ? " Reason: $note" : ''),
        'more_info_requested' => "More information was requested on submission $ref." . ($note ? " $note" : ''),
    ];
    $message = $messages[$event] ?? "Submission $ref was updated.";

    $stmt = $conn->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?, 'submission_status', ?)");
    $stmt->bind_param("is", $submittedBy, $message);
    $stmt->execute();
    $stmt->close();
}

// Notifies everyone who can review submissions (explicit submission_review
// permission, or the super role which bypasses per-permission rows) that a
// new/resubmitted submission needs triage.
function notify_new_submission(mysqli $conn, int $submissionId): void
{
    $ref = submission_ref($submissionId);
    $message = "Submission $ref is awaiting review.";

    $result = $conn->query("
        SELECT DISTINCT u.id FROM users u
        LEFT JOIN user_permissions up ON up.user_id = u.id AND up.permission_key = 'submission_review'
        WHERE u.role = 'super' OR up.user_id IS NOT NULL
    ");
    if (!$result) {
        return;
    }
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?, 'submission_new', ?)");
    while ($row = $result->fetch_assoc()) {
        $uid = (int) $row['id'];
        $stmt->bind_param("is", $uid, $message);
        $stmt->execute();
    }
    $stmt->close();
}

// Approves $submissionId: creates the real job (same insert shape as
// cargo_hold/create_case.php), copies key dates across, and marks the
// submission Approved. Declared exhibits stay linked via submission_id for
// reconciliation at book-in - see spaceport/book_in_submitted_exhibits.php.
// Returns the new job_id, or null if the submission wasn't found/approvable.
function approve_submission(mysqli $conn, int $submissionId, int $reviewerId): ?int
{
    $stmt = $conn->prepare("SELECT * FROM submissions WHERE submission_id = ? AND status IN ('Pending', 'More Info Requested')");
    $stmt->bind_param("i", $submissionId);
    $stmt->execute();
    $submission = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$submission) {
        return null;
    }

    // Same year+sequence custom_ref generation as create_case.php.
    $yearPrefix = date('y');
    $result = $conn->query("SELECT MAX(CAST(SUBSTRING(custom_ref, 3) AS UNSIGNED)) AS last_ref FROM jobs WHERE custom_ref LIKE '$yearPrefix%'");
    $row = $result->fetch_assoc();
    $customRef = is_null($row['last_ref']) ? $yearPrefix . '000' : $yearPrefix . str_pad($row['last_ref'] + 1, 3, '0', STR_PAD_LEFT);

    $description = $submission['initial_summary'];
    $oic = $submission['oic'];
    $operationId = $submission['operation'] ?: 1;
    $customerId = $submission['customer_id'];
    $leadForceId = $submission['lead_force_id'];
    $suspect = $submission['suspect'];
    $fingerprints = (int) $submission['fingerprints'];
    $dna = (int) $submission['dna'];
    $malware = (int) $submission['malware'];
    $statusId = 1;
    $strategySet = date('Y-m-d H:i:s');
    $slaDays = get_strategy_due_sla_days($conn);
    $strategyDue = date('Y-m-d H:i:s', strtotime("+{$slaDays} days"));
    $caseTypeId = $submission['case_type_id'];

    // Core insert - identical column set/type string to create_case.php.
    $stmt = $conn->prepare("INSERT INTO jobs
        (custom_ref, created_by, initial_summary, oic, operation, customer_id, lead_force_id, suspect, fingerprints, dna, status_id, malware, strategy_set, strategy_due, case_type_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param(
        "sissiiisiiiissi",
        $customRef, $reviewerId, $description, $oic, $operationId, $customerId, $leadForceId,
        $suspect, $fingerprints, $dna, $statusId, $malware, $strategySet, $strategyDue, $caseTypeId
    );
    $stmt->execute();
    $newJobId = $conn->insert_id;
    $stmt->close();

    // Spaceport-only columns, set separately to keep the type string above
    // unchanged/verifiable against create_case.php's own. Subject photos
    // aren't copied - they stay keyed to submission_id (see
    // includes/migrations/023_spaceport.sql).
    $stmt2 = $conn->prepare("UPDATE jobs SET incident_number = ?, external_reference = ? WHERE job_id = ?");
    $stmt2->bind_param("ssi", $submission['incident_number'], $submission['external_reference'], $newJobId);
    $stmt2->execute();
    $stmt2->close();

    $kd = $conn->prepare("SELECT event_date, label FROM submission_key_dates WHERE submission_id = ?");
    $kd->bind_param("i", $submissionId);
    $kd->execute();
    $kdResult = $kd->get_result();
    $insKd = $conn->prepare("INSERT INTO case_key_dates (job_id, event_date, label, created_by) VALUES (?, ?, ?, ?)");
    while ($dateRow = $kdResult->fetch_assoc()) {
        $insKd->bind_param("issi", $newJobId, $dateRow['event_date'], $dateRow['label'], $reviewerId);
        $insKd->execute();
    }
    $kd->close();
    $insKd->close();

    $upd = $conn->prepare("UPDATE submissions SET status = 'Approved', reviewed_by = ?, reviewed_at = NOW(), job_id = ? WHERE submission_id = ?");
    $upd->bind_param("iii", $reviewerId, $newJobId, $submissionId);
    $upd->execute();
    $upd->close();

    insert_history_row($conn, 'submission_history', $submissionId, 'APPROVED', $reviewerId, json_encode(['job_id' => $newJobId, 'custom_ref' => $customRef]));
    notify_submission_status_changed($conn, $submissionId, 'approved');

    return $newJobId;
}

function reject_submission(mysqli $conn, int $submissionId, int $reviewerId, string $reason): bool
{
    $stmt = $conn->prepare("UPDATE submissions SET status = 'Rejected', reviewed_by = ?, reviewed_at = NOW(), decision_reason = ? WHERE submission_id = ? AND status IN ('Pending', 'More Info Requested')");
    $stmt->bind_param("isi", $reviewerId, $reason, $submissionId);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();

    if ($ok) {
        insert_history_row($conn, 'submission_history', $submissionId, 'REJECTED', $reviewerId, json_encode(['reason' => $reason]));
        notify_submission_status_changed($conn, $submissionId, 'rejected', $reason);
    }
    return $ok;
}

function request_submission_info(mysqli $conn, int $submissionId, int $reviewerId, string $note): bool
{
    $stmt = $conn->prepare("UPDATE submissions SET status = 'More Info Requested', reviewed_by = ?, reviewed_at = NOW(), info_requested_note = ? WHERE submission_id = ? AND status = 'Pending'");
    $stmt->bind_param("isi", $reviewerId, $note, $submissionId);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();

    if ($ok) {
        insert_history_row($conn, 'submission_history', $submissionId, 'MORE_INFO_REQUESTED', $reviewerId, json_encode(['note' => $note]));
        notify_submission_status_changed($conn, $submissionId, 'more_info_requested', $note);
    }
    return $ok;
}
