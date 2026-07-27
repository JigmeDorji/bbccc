<?php

function bbcc_fee_payment_snapshot(PDO $pdo, int $paymentId): ?array {
    if ($paymentId <= 0) return null;
    $stmt = $pdo->prepare("
        SELECT id, status, due_amount, paid_amount
        FROM pcm_fee_payments
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $paymentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function bbcc_audit_fee_payment_change(
    PDO $pdo,
    int $paymentId,
    string $action,
    ?array $before,
    ?array $after,
    string $reason = ''
): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO pcm_fee_payment_audit
                (payment_id, action, old_status, new_status,
                 old_due_amount, new_due_amount, old_paid_amount, new_paid_amount,
                 reason, changed_by)
            VALUES
                (:payment_id, :action, :old_status, :new_status,
                 :old_due, :new_due, :old_paid, :new_paid,
                 :reason, :changed_by)
        ");
        $stmt->execute([
            ':payment_id' => $paymentId,
            ':action' => substr(trim($action), 0, 60),
            ':old_status' => $before['status'] ?? null,
            ':new_status' => $after['status'] ?? null,
            ':old_due' => isset($before['due_amount']) ? (float)$before['due_amount'] : null,
            ':new_due' => isset($after['due_amount']) ? (float)$after['due_amount'] : null,
            ':old_paid' => isset($before['paid_amount']) ? (float)$before['paid_amount'] : null,
            ':new_paid' => isset($after['paid_amount']) ? (float)$after['paid_amount'] : null,
            ':reason' => trim($reason) !== '' ? substr(trim($reason), 0, 500) : null,
            ':changed_by' => substr((string)($_SESSION['username'] ?? 'system'), 0, 150),
        ]);
    } catch (Throwable $e) {
        error_log('[Fee Audit] Could not record payment ' . $paymentId . ': ' . $e->getMessage());
    }
}
