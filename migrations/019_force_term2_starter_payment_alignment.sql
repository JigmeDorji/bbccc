-- Follow-up to migration 018: older records may be marked Verified without
-- an amount, reference, or proof. For Term 2 starters, an Unpaid Term 2 plus
-- a Verified Term 3 is the known shifted-enrolment pattern.

INSERT INTO pcm_fee_payment_audit
    (payment_id, action, old_status, new_status, old_due_amount, new_due_amount,
     old_paid_amount, new_paid_amount, reason, changed_by)
SELECT term2.id, 'align_term2_starter_payment', term2.status, 'Verified',
       term2.due_amount, term2.due_amount, term2.paid_amount,
       CASE WHEN term3.paid_amount > 0 THEN term3.paid_amount ELSE term2.due_amount END,
       CONCAT('Moved Verified status from shifted Term 3 row #', term3.id),
       'migration-019'
FROM pcm_enrolments enrolment
INNER JOIN pcm_fee_payments term2
    ON term2.enrolment_id = enrolment.id AND term2.instalment_label = 'Term 2'
INNER JOIN pcm_fee_payments term3
    ON term3.enrolment_id = enrolment.id AND term3.instalment_label = 'Term 3'
WHERE enrolment.fee_plan = 'Term-wise'
  AND enrolment.start_term = 2
  AND term2.status = 'Unpaid'
  AND term3.status = 'Verified';

INSERT INTO pcm_fee_payment_audit
    (payment_id, action, old_status, new_status, old_due_amount, new_due_amount,
     old_paid_amount, new_paid_amount, reason, changed_by)
SELECT term3.id, 'clear_shifted_term3_payment', term3.status, 'Unpaid',
       term3.due_amount, term3.due_amount, term3.paid_amount, 0,
       CONCAT('Verified status moved to starting Term 2 row #', term2.id),
       'migration-019'
FROM pcm_enrolments enrolment
INNER JOIN pcm_fee_payments term2
    ON term2.enrolment_id = enrolment.id AND term2.instalment_label = 'Term 2'
INNER JOIN pcm_fee_payments term3
    ON term3.enrolment_id = enrolment.id AND term3.instalment_label = 'Term 3'
WHERE enrolment.fee_plan = 'Term-wise'
  AND enrolment.start_term = 2
  AND term2.status = 'Unpaid'
  AND term3.status = 'Verified';

UPDATE pcm_enrolments enrolment
INNER JOIN pcm_fee_payments term2
    ON term2.enrolment_id = enrolment.id AND term2.instalment_label = 'Term 2'
INNER JOIN pcm_fee_payments term3
    ON term3.enrolment_id = enrolment.id AND term3.instalment_label = 'Term 3'
SET term2.paid_amount = CASE
        WHEN term3.paid_amount > 0 THEN term3.paid_amount
        WHEN term2.paid_amount > 0 THEN term2.paid_amount
        ELSE term2.due_amount
    END,
    term2.payment_ref = COALESCE(NULLIF(term2.payment_ref, ''), term3.payment_ref),
    term2.proof_path = COALESCE(NULLIF(term2.proof_path, ''), term3.proof_path),
    term2.status = 'Verified',
    term2.reject_reason = NULL,
    term2.verified_by = COALESCE(term3.verified_by, term2.verified_by, 'migration-019'),
    term2.verified_at = COALESCE(term3.verified_at, term2.verified_at, NOW()),
    term2.submitted_at = COALESCE(term3.submitted_at, term2.submitted_at, NOW()),
    term3.paid_amount = 0,
    term3.payment_ref = NULL,
    term3.proof_path = NULL,
    term3.status = 'Unpaid',
    term3.reject_reason = NULL,
    term3.verified_by = NULL,
    term3.verified_at = NULL,
    term3.submitted_at = NULL
WHERE enrolment.fee_plan = 'Term-wise'
  AND enrolment.start_term = 2
  AND term2.status = 'Unpaid'
  AND term3.status = 'Verified';
