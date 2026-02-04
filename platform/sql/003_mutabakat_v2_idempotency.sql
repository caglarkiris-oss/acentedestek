-- Mutabakat v2 idempotent CSV uploads (concurrency-safe)
-- Run this after 001_mutabakat_v2.sql (and after your existing schema is in place).

-- 1) Prevent duplicate rows when the same policy is uploaded concurrently
-- Unique per period + source + agency scope + policy + txn type
ALTER TABLE mutabakat_v2_rows
  ADD UNIQUE KEY uq_v2_row_scope_policy (period_id, source_type, ana_acente_id, tali_acente_id, policy_no_norm, txn_type);

-- Helpful composite index for matching performance
ALTER TABLE mutabakat_v2_rows
  ADD KEY idx_v2_match_lookup (period_id, policy_no_norm, source_type, ana_acente_id, tali_acente_id);

-- 2) Prevent same file being uploaded twice (best effort)
-- Note: requires file_hash to be filled (we compute sha1)
ALTER TABLE mutabakat_v2_import_batches
  ADD UNIQUE KEY uq_v2_batch_filehash (period_id, ana_acente_id, tali_acente_id, source_type, file_hash);
