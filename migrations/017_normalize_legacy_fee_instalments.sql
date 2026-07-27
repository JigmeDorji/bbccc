-- Merge legacy plan-labelled rows into the canonical first instalment.
-- GREATEST is intentional: adding the amounts would count one payment twice.

INSERT INTO pcm_fee_payment_audit
    (payment_id, action, old_status, new_status, old_due_amount, new_due_amount,
     old_paid_amount, new_paid_amount, reason, changed_by)
SELECT target.id, 'normalize_legacy_instalment', target.status,
    CASE
        WHEN target.status = 'Verified' OR legacy.status = 'Verified' THEN 'Verified'
        WHEN target.status = 'Pending' OR legacy.status = 'Pending' THEN 'Pending'
        WHEN target.status = 'Rejected' OR legacy.status = 'Rejected' THEN 'Rejected'
        ELSE 'Unpaid'
    END,
    target.due_amount, target.due_amount, target.paid_amount,
    GREATEST(target.paid_amount, legacy.paid_amount),
    CONCAT('Merged legacy payment row #', legacy.id, ' labelled ', legacy.instalment_label),
    'migration-017'
FROM pcm_fee_payments legacy
INNER JOIN pcm_fee_payments target
    ON target.enrolment_id = legacy.enrolment_id
   AND target.instalment_label = CASE
       WHEN legacy.plan_type = 'Term-wise' THEN 'Term 1'
       WHEN legacy.plan_type = 'Half-yearly' THEN 'Half 1'
   END
WHERE (legacy.plan_type = 'Term-wise' AND legacy.instalment_label = 'Term-wise')
   OR (legacy.plan_type = 'Half-yearly' AND legacy.instalment_label = 'Half-yearly');

UPDATE pcm_fee_payments legacy
INNER JOIN pcm_fee_payments target
    ON target.enrolment_id = legacy.enrolment_id
   AND target.instalment_label = CASE
       WHEN legacy.plan_type = 'Term-wise' THEN 'Term 1'
       WHEN legacy.plan_type = 'Half-yearly' THEN 'Half 1'
   END
SET target.paid_amount = GREATEST(target.paid_amount, legacy.paid_amount),
    target.payment_ref = COALESCE(NULLIF(target.payment_ref, ''), legacy.payment_ref),
    target.proof_path = COALESCE(NULLIF(target.proof_path, ''), legacy.proof_path),
    target.status = CASE
        WHEN target.status = 'Verified' OR legacy.status = 'Verified' THEN 'Verified'
        WHEN target.status = 'Pending' OR legacy.status = 'Pending' THEN 'Pending'
        WHEN target.status = 'Rejected' OR legacy.status = 'Rejected' THEN 'Rejected'
        ELSE 'Unpaid'
    END,
    target.reject_reason = CASE
        WHEN target.status = 'Rejected' OR legacy.status = 'Rejected'
            THEN COALESCE(target.reject_reason, legacy.reject_reason)
        ELSE NULL
    END,
    target.verified_by = COALESCE(target.verified_by, legacy.verified_by),
    target.verified_at = COALESCE(target.verified_at, legacy.verified_at),
    target.submitted_at = COALESCE(target.submitted_at, legacy.submitted_at)
WHERE (legacy.plan_type = 'Term-wise' AND legacy.instalment_label = 'Term-wise')
   OR (legacy.plan_type = 'Half-yearly' AND legacy.instalment_label = 'Half-yearly');

DELETE legacy
FROM pcm_fee_payments legacy
INNER JOIN pcm_fee_payments target
    ON target.enrolment_id = legacy.enrolment_id
   AND target.instalment_label = CASE
       WHEN legacy.plan_type = 'Term-wise' THEN 'Term 1'
       WHEN legacy.plan_type = 'Half-yearly' THEN 'Half 1'
   END
WHERE (legacy.plan_type = 'Term-wise' AND legacy.instalment_label = 'Term-wise')
   OR (legacy.plan_type = 'Half-yearly' AND legacy.instalment_label = 'Half-yearly');

-- Preserve an unmatched legacy payment by renaming it rather than deleting it.
UPDATE pcm_fee_payments
SET instalment_label = 'Term 1'
WHERE plan_type = 'Term-wise' AND instalment_label = 'Term-wise';

UPDATE pcm_fee_payments
SET instalment_label = 'Half 1'
WHERE plan_type = 'Half-yearly' AND instalment_label = 'Half-yearly';
