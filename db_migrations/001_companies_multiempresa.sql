BEGIN;

CREATE TABLE IF NOT EXISTS public.companies (
  id bigint PRIMARY KEY,
  name character varying(190) NOT NULL UNIQUE,
  is_active boolean DEFAULT true NOT NULL,
  created_at timestamptz DEFAULT now() NOT NULL,
  updated_at timestamptz DEFAULT now() NOT NULL
);

INSERT INTO public.companies (id, name)
VALUES
  (1, 'OVBRA NORESTE SELVA'),
  (2, 'STEVEDAVILA & ABOGADOS'),
  (3, 'STEVE DAVILA RUIZ (RUC 10)')
ON CONFLICT (id) DO UPDATE
SET name = EXCLUDED.name;

ALTER TABLE public.users
  ADD COLUMN IF NOT EXISTS active_company_id bigint;

ALTER TABLE public.batches
  ADD COLUMN IF NOT EXISTS company_id bigint,
  ADD COLUMN IF NOT EXISTS company_ruc text,
  ADD COLUMN IF NOT EXISTS invoice_number text;

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM pg_constraint
    WHERE conname = 'batches_company_id_fkey'
  ) THEN
    ALTER TABLE public.batches
      ADD CONSTRAINT batches_company_id_fkey
      FOREIGN KEY (company_id) REFERENCES public.companies(id);
  END IF;

  IF NOT EXISTS (
    SELECT 1
    FROM pg_constraint
    WHERE conname = 'users_active_company_id_fkey'
  ) THEN
    ALTER TABLE public.users
      ADD CONSTRAINT users_active_company_id_fkey
      FOREIGN KEY (active_company_id) REFERENCES public.companies(id);
  END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_batches_company_status_confirmed
  ON public.batches (company_id, status, confirmed_at);

CREATE TABLE IF NOT EXISTS public.web_user_companies (
  web_user_id bigint NOT NULL,
  company_id bigint NOT NULL,
  created_at timestamptz DEFAULT now() NOT NULL,
  PRIMARY KEY (web_user_id, company_id),
  CONSTRAINT web_user_companies_web_user_fkey FOREIGN KEY (web_user_id) REFERENCES public.web_users(id) ON DELETE CASCADE,
  CONSTRAINT web_user_companies_company_fkey FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE
);

INSERT INTO public.web_user_companies (web_user_id, company_id)
SELECT wu.id, c.id
FROM public.web_users wu
CROSS JOIN public.companies c
ON CONFLICT DO NOTHING;

COMMIT;

