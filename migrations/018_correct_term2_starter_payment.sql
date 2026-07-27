-- Some migrated Term 2 starters had their enrolment payment attached to Term 3.
-- Move it only when Term 2 is completely unpaid/empty and Term 3 is verified
-- with actual payment evidence. This avoids touching normal Term 3 payments.

INSERT INTO pcm_fee_payment_audit
    (payment_id, action, old_status, new_status, old_due_amount, new_due_amount,
     old_paid_amount, new_paid_amount, reason, changed_by)
SELECT term2.id, 'move_payment_to_start_term', term2.status, 'Verified',
       term2.due_amount, term2.due_amount, term2.paid_amount, term3.paid_amount,
       CONCAT('Moved migrated payment from Term 3 row #', term3.id, ' to starting Term 2'),
       'migration-018'
FROM pcm_enrolments enrolment
INNER JOIN pcm_fee_payments term2
    ON term2.enrolment_id = enrolment.id AND term2.instalment_label = 'Term 2'
INNER JOIN pcm_fee_payments term3
    ON term3.enrolment_id = enrolment.id AND term3.instalment_label = 'Term 3'
WHERE enrolment.fee_plan = 'Term-wise'
  AND enrolment.start_term = 2
  AND term2.status = 'Unpaid'
  AND term2.paid_amount = 0
  AND COALESCE(term2.payment_ref, '') = ''
  AND COALESCE(term2.proof_path, '') = ''
  AND term3.status = 'Verified'
  AND (term3.paid_amount > 0 OR COALESCE(term3.payment_ref, '') <> '' OR COALESCE(term3.proof_path, '') <> '');

INSERT INTO pcm_fee_payment_audit
    (payment_id, action, old_status, new_status, old_due_amount, new_due_amount,
     old_paid_amount, new_paid_amount, reason, changed_by)
SELECT term3.id, 'clear_shifted_payment', term3.status, 'Unpaid',
       term3.due_amount, term3.due_amount, term3.paid_amount, 0,
       CONCAT('Payment moved to starting Term 2 row #', term2.id),
       'migration-018'
FROM pcm_enrolments enrolment
INNER JOIN pcm_fee_payments term2
    ON term2.enrolment_id = enrolment.id AND term2.instalment_label = 'Term 2'
INNER JOIN pcm_fee_payments term3
    ON term3.enrolment_id = enrolment.id AND term3.instalment_label = 'Term 3'
WHERE enrolment.fee_plan = 'Term-wise'
  AND enrolment.start_term = 2
  AND term2.status = 'Unpaid'
  AND term2.paid_amount = 0
  AND COALESCE(term2.payment_ref, '') = ''
  AND COALESCE(term2.proof_path, '') = ''
  AND term3.status = 'Verified'
  AND (term3.paid_amount > 0 OR COALESCE(term3.payment_ref, '') <> '' OR COALESCE(term3.proof_path, '') <> '');

UPDATE pcm_enrolments enrolment
INNER JOIN pcm_fee_payments term2
    ON term2.enrolment_id = enrolment.id AND term2.instalment_label = 'Term 2'
INNER JOIN pcm_fee_payments term3
    ON term3.enrolment_id = enrolment.id AND term3.instalment_label = 'Term 3'
SET term2.paid_amount = term3.paid_amount,
    term2.payment_ref = term3.payment_ref,
    term2.proof_path = term3.proof_path,
    term2.status = 'Verified',
    term2.reject_reason = NULL,
    term2.verified_by = term3.verified_by,
    term2.verified_at = term3.verified_at,
    term2.submitted_at = term3.submitted_at,
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
  AND term2.paid_amount = 0
  AND COALESCE(term2.payment_ref, '') = ''
  AND COALESCE(term2.proof_path, '') = ''
  AND term3.status = 'Verified'
  AND (term3.paid_amount > 0 OR COALESCE(term3.payment_ref, '') <> '' OR COALESCE(term3.proof_path, '') <> '');
