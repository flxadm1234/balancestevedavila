BEGIN;

ALTER TABLE public.raw_inputs
  ADD COLUMN IF NOT EXISTS file_path text,
  ADD COLUMN IF NOT EXISTS mime_type text,
  ADD COLUMN IF NOT EXISTS original_file_name text;

CREATE INDEX IF NOT EXISTS idx_raw_inputs_batch_id_id_desc
  ON public.raw_inputs (batch_id, id DESC);

COMMIT;

