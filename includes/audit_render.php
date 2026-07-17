<?php
// includes/audit_render.php
//
// Shared rendering helpers for audit_log entries.

// Renders CREATE/UPDATE/DELETE etc as small coloured pills.
function action_badge(string $action): string
{
    $classMap = [
        'CREATE' => 'badge-create',
        'UPDATE' => 'badge-update',
        'DELETE' => 'badge-delete',
        'DEACTIVATE' => 'badge-delete',
        'RESTORE' => 'badge-create',
        'REACTIVATE' => 'badge-create',
        'PASSWORD_RESET' => 'badge-update',
        'BOOK_IN' => 'badge-create',
        'BOOK_OUT' => 'badge-update',
    ];
    $class = $classMap[$action] ?? 'badge-default';
    return '<span class="action-badge ' . $class . '">' . htmlspecialchars($action) . '</span>';
}

// Renders audit_log.details JSON as flat "key: value" pairs, field diffs as
// "field: x -> y", and anything more nested as a collapsible raw block.
function render_audit_details(?string $json): string
{
    if ($json === null || $json === '') {
        return '';
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return htmlspecialchars((string) $json);
    }

    $flatParts = [];
    $hasNested = false;
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            if (array_key_exists('from', $value) && array_key_exists('to', $value)) {
                $from = $value['from'] === null || $value['from'] === '' ? '(empty)' : (string) $value['from'];
                $to = $value['to'] === null || $value['to'] === '' ? '(empty)' : (string) $value['to'];
                $flatParts[] = '<strong>' . htmlspecialchars((string) $key) . ':</strong> ' .
                    htmlspecialchars($from) . ' &rarr; ' . htmlspecialchars($to);
            } else {
                $hasNested = true;
            }
            continue;
        }
        if ($value === null || $value === '') {
            continue;
        }
        $flatParts[] = '<strong>' . htmlspecialchars((string) $key) . ':</strong> ' . htmlspecialchars((string) $value);
    }

    $html = implode('<br>', $flatParts);
    if ($hasNested) {
        $pretty = json_encode($data, JSON_PRETTY_PRINT);
        $html .= ($html !== '' ? '<br>' : '') .
            '<details class="details-raw"><summary>Full snapshot</summary><pre>' . htmlspecialchars($pretty) . '</pre></details>';
    }
    return $html !== '' ? $html : '<span style="color:var(--polaris-text-faint-2);">-</span>';
}
