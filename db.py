import asyncpg
from typing import Optional, List, Dict, Any
from datetime import datetime
from typing import List, Optional


PAGE_SIZE_DEFAULT = 30

class DB:
    def __init__(self, pool: asyncpg.Pool):
        self.pool = pool

async def create_pool(dsn: str) -> DB:
    pool = await asyncpg.create_pool(dsn=dsn, min_size=1, max_size=10)
    return DB(pool=pool)


async def get_draft_batch_info(db: DB, batch_id: int):
    q = """
    SELECT
        b.kind,
        b.company_id,
        b.company_ruc,
        b.invoice_number,
        c.name AS company_name
    FROM batches b
    LEFT JOIN companies c ON c.id = b.company_id
    WHERE b.id=$1
    """
    async with db.pool.acquire() as conn:
        return await conn.fetchrow(q, batch_id)



async def set_batch_kind(db: DB, batch_id: int, user_id: int, kind: str) -> bool:
    q = """
    UPDATE batches
    SET kind = $1
    WHERE id = $2 AND user_id = $3
    """
    async with db.pool.acquire() as conn:
        r = await conn.execute(q, kind, batch_id, user_id)
    return r.startswith("UPDATE") and not r.endswith(" 0")



async def upsert_user(db: DB, telegram_user_id: int, first_name: Optional[str], username: Optional[str]) -> int:
    async with db.pool.acquire() as conn:
        row = await conn.fetchrow(
            """
            INSERT INTO users (telegram_user_id, first_name, username, mode)
            VALUES ($1, $2, $3, 'personal')
            ON CONFLICT (telegram_user_id)
            DO UPDATE SET first_name = EXCLUDED.first_name, username = EXCLUDED.username
            RETURNING id;
            """,
            telegram_user_id, first_name, username
        )
        return int(row["id"])

async def list_companies(db: DB):
    async with db.pool.acquire() as conn:
        return await conn.fetch(
            """
            SELECT id, name
            FROM companies
            WHERE is_active = true
            ORDER BY id ASC
            """
        )

async def get_user_active_company_id(db: DB, user_id: int) -> Optional[int]:
    async with db.pool.acquire() as conn:
        row = await conn.fetchrow(
            "SELECT active_company_id FROM users WHERE id=$1;",
            user_id
        )
        return int(row["active_company_id"]) if row and row["active_company_id"] else None

async def set_user_active_company_id(db: DB, user_id: int, company_id: int) -> None:
    async with db.pool.acquire() as conn:
        await conn.execute(
            "UPDATE users SET active_company_id=$1 WHERE id=$2;",
            company_id, user_id
        )

async def get_or_create_draft_batch(db: DB, user_id: int) -> int:
    async with db.pool.acquire() as conn:
        row = await conn.fetchrow(
            "SELECT id FROM batches WHERE user_id=$1 AND status='draft' ORDER BY created_at DESC LIMIT 1;",
            user_id
        )
        if row:
            return int(row["id"])
        urow = await conn.fetchrow("SELECT active_company_id FROM users WHERE id=$1;", user_id)
        active_company_id = int(urow["active_company_id"]) if urow and urow["active_company_id"] else None
        row2 = await conn.fetchrow(
            "INSERT INTO batches (user_id, status, company_id) VALUES ($1, 'draft', $2) RETURNING id;",
            user_id, active_company_id
        )
        return int(row2["id"])

async def set_batch_company(db: DB, batch_id: int, user_id: int, company_id: int) -> bool:
    q = """
    UPDATE batches
    SET company_id = $1
    WHERE id = $2 AND user_id = $3 AND status='draft'
    """
    async with db.pool.acquire() as conn:
        r = await conn.execute(q, company_id, batch_id, user_id)
    return r.startswith("UPDATE") and not r.endswith(" 0")

async def set_batch_company_ruc(db: DB, batch_id: int, user_id: int, company_ruc: Optional[str]) -> bool:
    q = """
    UPDATE batches
    SET company_ruc = $1
    WHERE id = $2 AND user_id = $3 AND status='draft'
    """
    async with db.pool.acquire() as conn:
        r = await conn.execute(q, company_ruc, batch_id, user_id)
    return r.startswith("UPDATE") and not r.endswith(" 0")

async def set_batch_invoice_number(db: DB, batch_id: int, user_id: int, invoice_number: Optional[str]) -> bool:
    q = """
    UPDATE batches
    SET invoice_number = $1
    WHERE id = $2 AND user_id = $3 AND status='draft'
    """
    async with db.pool.acquire() as conn:
        r = await conn.execute(q, invoice_number, batch_id, user_id)
    return r.startswith("UPDATE") and not r.endswith(" 0")

async def add_raw_input(db: DB, batch_id: int, input_type: str, content_text: Optional[str]) -> int:
    async with db.pool.acquire() as conn:
        row = await conn.fetchrow(
            "INSERT INTO raw_inputs (batch_id, input_type, content_text) VALUES ($1,$2,$3) RETURNING id;",
            batch_id, input_type, content_text
        )
        return int(row["id"])

async def set_raw_input_file(db: DB, raw_input_id: int, file_path: str,
                             mime_type: Optional[str], original_file_name: Optional[str]) -> bool:
    q = """
    UPDATE raw_inputs
    SET file_path = $1, mime_type = $2, original_file_name = $3
    WHERE id = $4
    """
    async with db.pool.acquire() as conn:
        r = await conn.execute(q, file_path, mime_type, original_file_name, raw_input_id)
    return r.startswith("UPDATE") and not r.endswith(" 0")

async def get_last_raw_input_type(db: DB, batch_id: int) -> Optional[str]:
    async with db.pool.acquire() as conn:
        row = await conn.fetchrow(
            "SELECT input_type FROM raw_inputs WHERE batch_id=$1 ORDER BY id DESC LIMIT 1;",
            batch_id
        )
        return (row["input_type"] if row and row["input_type"] else None)

async def insert_items(db: DB, batch_id: int, items: List[Dict[str, Any]]) -> List[int]:
    ids: List[int] = []
    async with db.pool.acquire() as conn:
        async with conn.transaction():
            for it in items:
                row = await conn.fetchrow(
                    """
                    INSERT INTO items (batch_id, description, price, item_datetime, payment_method)
                    VALUES ($1, $2, $3, $4, $5)
                    RETURNING id;
                    """,
                    batch_id,
                    it["description"]+" ("+it.get("payment_method", "cash")+")",
                    float(it.get("price", 0)),
                    it["item_datetime"],
                    it.get("payment_method", "cash")
                )
                ids.append(int(row["id"]))
    return ids

async def fetch_batch_items(db: DB, batch_id: int):
    async with db.pool.acquire() as conn:
        return await conn.fetch(
            "SELECT id, description, price, item_datetime, payment_method FROM items WHERE batch_id=$1 ORDER BY id;",
            batch_id
        )

async def update_item_for_user(db: DB, telegram_user_id: int, item_id: int,
                               description: Optional[str], price: Optional[float],
                               item_datetime: Optional[datetime]) -> bool:
    """
    Actualiza un item SOLO si pertenece al usuario (vía batches->users).
    Devuelve True si actualizó 1 fila.
    """
    sets = []
    args = []
    idx = 1

    if description is not None:
        sets.append(f"description=${idx}")
        args.append(description); idx += 1
    if price is not None:
        sets.append(f"price=${idx}")
        args.append(price); idx += 1
    if item_datetime is not None:
        sets.append(f"item_datetime=${idx}")
        args.append(item_datetime); idx += 1

    if not sets:
        return False

    # item_id y telegram_user_id al final
    args.append(item_id); idx_item = idx; idx += 1
    args.append(telegram_user_id); idx_user = idx

    q = f"""
    UPDATE items i
    SET {', '.join(sets)}
    FROM batches b
    JOIN users u ON u.id = b.user_id
    WHERE i.batch_id = b.id
      AND i.id = ${idx_item}
      AND u.telegram_user_id = ${idx_user};
    """

    async with db.pool.acquire() as conn:
        res = await conn.execute(q, *args)
        return res.split()[-1] == "1"

async def confirm_batch(db: DB, batch_id: int, friendly_name: str) -> None:
    async with db.pool.acquire() as conn:
        await conn.execute(
            """
            UPDATE batches
            SET status = 'confirmed',
                friendly_name = $2,
                confirmed_at = NOW()
            WHERE id = $1;
            """,
            batch_id, friendly_name
        )

async def query_items_by_datetime(db: DB, user_id: int, start_dt: datetime, end_dt: datetime, company_id: Optional[int] = None):
    async with db.pool.acquire() as conn:
        return await conn.fetch(
            """
            SELECT b.friendly_name, i.id, i.description, i.price, i.item_datetime
            FROM items i
            JOIN batches b ON b.id = i.batch_id
            WHERE b.user_id=$1
              AND b.status='confirmed'
              AND ($4::bigint IS NULL OR b.company_id = $4)
              AND i.item_datetime >= $2 AND i.item_datetime < $3
            ORDER BY i.item_datetime ASC;
            """,
            user_id, start_dt, end_dt, company_id
        )

async def list_confirmed_batches(db: DB, user_id: int, limit: int = 30, company_id: Optional[int] = None):
    """
    Lista registros confirmados (batches) del usuario con total acumulado.
    """
    async with db.pool.acquire() as conn:
        return await conn.fetch(
            """
            SELECT
              b.id,
              COALESCE(b.friendly_name, '(sin nombre)') AS friendly_name,
              COALESCE(SUM(i.price), 0) AS total,
              MIN(b.confirmed_at) AS confirmed_at
            FROM batches b
            LEFT JOIN items i ON i.batch_id = b.id
            WHERE b.user_id=$1
              AND b.status='confirmed'
              AND ($3::bigint IS NULL OR b.company_id = $3)
            GROUP BY b.id, b.friendly_name
            ORDER BY b.confirmed_at DESC NULLS LAST, b.id DESC
            LIMIT $2;
            """,
            user_id, limit, company_id
        )

async def get_confirmed_batch_items(db: DB, user_id: int, batch_id: int, company_id: Optional[int] = None):
    """
    Devuelve items de un batch confirmado, solo si pertenece al usuario.
    """
    async with db.pool.acquire() as conn:
        return await conn.fetch(
            """
            SELECT i.id, i.description, i.price, i.item_datetime, i.payment_method
            FROM items i
            JOIN batches b ON b.id=i.batch_id
            WHERE b.user_id=$1
              AND b.status='confirmed'
              AND b.id=$2
              AND ($3::bigint IS NULL OR b.company_id = $3)
            ORDER BY i.id ASC;
            """,
            user_id, batch_id, company_id
        )

async def delete_confirmed_batch(db: DB, user_id: int, batch_id: int, company_id: Optional[int] = None) -> bool:
    """
    Borra un registro confirmado del usuario. Por FK ON DELETE CASCADE,
    se borran items y raw_inputs.
    """
    async with db.pool.acquire() as conn:
        res = await conn.execute(
            """
            DELETE FROM batches
            WHERE id=$1
              AND user_id=$2
              AND status='confirmed'
              AND ($3::bigint IS NULL OR company_id = $3)
            """,
            batch_id, user_id, company_id
        )
        return res.split()[-1] == "1"

async def get_user_mode(db: DB, telegram_user_id: int) -> str:
    async with db.pool.acquire() as conn:
        row = await conn.fetchrow(
            "SELECT mode FROM users WHERE telegram_user_id=$1;",
            telegram_user_id
        )
        return (row["mode"] if row and row["mode"] else "personal")

async def set_user_mode(db: DB, telegram_user_id: int, mode: str) -> None:
    async with db.pool.acquire() as conn:
        await conn.execute(
            "UPDATE users SET mode=$1 WHERE telegram_user_id=$2;",
            mode, telegram_user_id
        )

async def delete_draft_batch(db: DB, user_id: int) -> bool:
    """
    Borra el borrador actual del usuario (si existe).
    """
    async with db.pool.acquire() as conn:
        row = await conn.fetchrow(
            "SELECT id FROM batches WHERE user_id=$1 AND status='draft' ORDER BY created_at DESC LIMIT 1;",
            user_id
        )
        if not row:
            return False
        batch_id = int(row["id"])
        res = await conn.execute(
            "DELETE FROM batches WHERE id=$1 AND user_id=$2 AND status='draft';",
            batch_id, user_id
        )
        return res.split()[-1] == "1"



async def count_confirmed_batches(db: DB, user_id: int, company_id: Optional[int] = None) -> int:
    async with db.pool.acquire() as conn:
        row = await conn.fetchrow(
            """
            SELECT COUNT(*) AS total
            FROM batches
            WHERE user_id = $1
              AND status = 'confirmed'
              AND ($2::bigint IS NULL OR company_id = $2)
            """,
            user_id, company_id
        )
        return int(row["total"] or 0)


async def list_confirmed_batches_page(
    db: DB,
    user_id: int,
    limit: int = PAGE_SIZE_DEFAULT,
    offset: int = 0,
    company_id: Optional[int] = None
):
    async with db.pool.acquire() as conn:
        return await conn.fetch(
            """
            SELECT
                b.id,
                COALESCE(b.friendly_name, '(sin nombre)') AS friendly_name,
                COALESCE(SUM(i.price), 0) AS total,
                b.confirmed_at
            FROM batches b
            LEFT JOIN items i ON i.batch_id = b.id
            WHERE b.user_id = $1
              AND b.status = 'confirmed'
              AND ($4::bigint IS NULL OR b.company_id = $4)
            GROUP BY b.id, b.friendly_name, b.confirmed_at
            ORDER BY b.confirmed_at DESC NULLS LAST, b.id DESC
            LIMIT $2 OFFSET $3
            """,
            user_id, limit, offset, company_id
        )


async def get_confirmed_batch_headers_by_ids(db, user_id: int, batch_ids: List[int]):
    """
    Devuelve cabeceras de batches confirmados para validar pertenencia.
    """
    async with db.pool.acquire() as conn:
        return await conn.fetch(
            """
            SELECT id, friendly_name, confirmed_at
            FROM batches
            WHERE user_id = $1
              AND status = 'confirmed'
              AND id = ANY($2::int[])
            ORDER BY confirmed_at DESC NULLS LAST, id DESC
            """,
            user_id, batch_ids
        )


async def list_all_confirmed_batch_ids(db, user_id: int):
    async with db.pool.acquire() as conn:
        return await conn.fetch(
            """
            SELECT id
            FROM batches
            WHERE user_id = $1 AND status = 'confirmed'
            ORDER BY confirmed_at DESC NULLS LAST, id DESC
            """
            , user_id
        )

async def export_confirmed_rows(
    db: DB,
    user_id: int,
    batch_ids: Optional[List[int]] = None,
    company_id: Optional[int] = None
):
    """
    Devuelve filas listas para exportar a Excel.
    Incluye:
    - batch_id
    - friendly_name
    - confirmed_at
    - item_id
    - description
    - price
    - item_datetime
    """

    async with db.pool.acquire() as conn:

        if batch_ids is None:
            # Exportar TODOS los registros confirmados
            return await conn.fetch(
                """
                SELECT
                    b.id            AS batch_id,
                    b.friendly_name,
                    b.confirmed_at,
                    c.name          AS company_name,
                    b.company_ruc,
                    b.invoice_number,
                    i.id            AS item_id,
                    i.description,
                    i.price,
                    i.item_datetime
                FROM batches b
                LEFT JOIN companies c ON c.id = b.company_id
                JOIN items i ON i.batch_id = b.id
                WHERE b.user_id = $1
                  AND b.status = 'confirmed'
                  AND ($2::bigint IS NULL OR b.company_id = $2)
                ORDER BY b.confirmed_at DESC, b.id DESC, i.id ASC
                """,
                user_id, company_id
            )

        # Exportar solo IDs seleccionados
        return await conn.fetch(
            """
            SELECT
                b.id            AS batch_id,
                b.friendly_name,
                b.confirmed_at,
                c.name          AS company_name,
                b.company_ruc,
                b.invoice_number,
                i.id            AS item_id,
                i.description,
                i.price,
                i.item_datetime
            FROM batches b
            LEFT JOIN companies c ON c.id = b.company_id
            JOIN items i ON i.batch_id = b.id
            WHERE b.user_id = $1
              AND b.status = 'confirmed'
              AND b.id = ANY($2::int[])
              AND ($3::bigint IS NULL OR b.company_id = $3)
            ORDER BY b.confirmed_at DESC, b.id DESC, i.id ASC
            """,
            user_id, batch_ids, company_id
        )
