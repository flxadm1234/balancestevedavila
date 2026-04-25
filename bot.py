import faulthandler, sys
faulthandler.enable()
sys.excepthook = lambda t, v, tb: (__import__("traceback").print_exception(t, v, tb), sys.stderr.flush())

import os

import math
from tempfile import NamedTemporaryFile
from aiogram.types import FSInputFile
from openpyxl import Workbook

from datetime import datetime, timedelta
from zoneinfo import ZoneInfo


import logging




logging.basicConfig(level=logging.INFO)
log = logging.getLogger("bot-smart-data")

from dotenv import load_dotenv

from aiogram.client.default import DefaultBotProperties
from aiogram import Bot, Dispatcher, F
from aiogram.filters import Command
from aiogram.types import Message, InlineKeyboardMarkup, InlineKeyboardButton, CallbackQuery
from aiogram.fsm.context import FSMContext
from aiogram.fsm.state import State, StatesGroup
from aiogram.fsm.storage.memory import MemoryStorage

from db import (
    create_pool, upsert_user, get_or_create_draft_batch, add_raw_input,
    insert_items, fetch_batch_items, update_item_for_user, confirm_batch,
    query_items_by_datetime,get_draft_batch_info,
    set_batch_kind, list_confirmed_batches, get_confirmed_batch_items, delete_confirmed_batch,
    get_user_mode, set_user_mode, delete_draft_batch, count_confirmed_batches,
    list_confirmed_batches_page,
    export_confirmed_rows,
    list_companies,
    get_user_active_company_id,
    set_user_active_company_id,
    set_batch_company,
    set_batch_company_ruc,
    set_batch_invoice_number,
    get_last_raw_input_type,
    set_raw_input_file
)

from ai import AI



TZ = ZoneInfo(os.getenv("TZ", "America/Lima"))

MEDIA_DIR = os.getenv("MEDIA_DIR", os.path.join("finweb", "public", "uploads", "raw_inputs"))
MEDIA_URL_PREFIX = os.getenv("MEDIA_URL_PREFIX", "/uploads/raw_inputs")


class ConfirmFlow(StatesGroup):
    waiting_friendly_name = State()
    waiting_company = State()
    waiting_company_ruc = State()
    waiting_invoice_number = State()

class DraftMetaFlow(StatesGroup):
    waiting_company_ruc = State()
    waiting_invoice_number = State()


def parse_dt_range(args: str):
    parts = args.strip().split()
    if len(parts) == 1:
        day = datetime.fromisoformat(parts[0]).replace(tzinfo=TZ)
        return day, day + timedelta(days=1)
    if len(parts) == 2:
        day = datetime.fromisoformat(parts[0]).replace(tzinfo=TZ)
        hh, mm = parts[1].split(":")
        start = day.replace(hour=int(hh), minute=int(mm), second=0, microsecond=0)
        return start, start + timedelta(hours=1)
    raise ValueError("Formato inválido")


def _fmt_dt_local(dt) -> str:
    """
    Convierte cualquier datetime (naive o aware) a TZ local y lo muestra amigable.
    """
    if not dt:
        return ""
    # Si viene naive, asumimos que ya es hora local
    if getattr(dt, "tzinfo", None) is None:
        dt = dt.replace(tzinfo=TZ)
    # Si viene con tz (ej UTC), lo pasamos a Lima
    dt_local = dt.astimezone(TZ)
    return dt_local.strftime("%Y-%m-%d %H:%M")  # bonito y corto


def format_items(items):
    if not items:
        return "No hay items aún."

    lines = []
    total = 0.0

    for r in items:
        total += float(r["price"])
        dt_txt = _fmt_dt_local(r["item_datetime"])
        lines.append(
            f"- ID {r['id']}: {r['description']} | S/ {float(r['price']):.2f} | {dt_txt}"
        )

    lines.append(f"\nTotal: S/ {total:.2f}")
    return "\n".join(lines)



PAGE_SIZE = 30

def kb_confirmed_pager(page: int, total_pages: int):
    """
    Botones solo para la sección Confirmados.
    """
    buttons = []
    nav = []
    if page > 1:
        nav.append(InlineKeyboardButton(text="⬅️ Anteriores", callback_data=f"confirmed_page:{page-1}"))
    if page < total_pages:
        nav.append(InlineKeyboardButton(text="Siguientes ➡️", callback_data=f"confirmed_page:{page+1}"))
    if nav:
        buttons.append(nav)

    buttons.append([
        InlineKeyboardButton(text="🔄 Refrescar", callback_data=f"confirmed_page:{page}"),
        InlineKeyboardButton(text="⬅️ Volver", callback_data="draft_view"),
    ])

    return InlineKeyboardMarkup(inline_keyboard=buttons)


async def send_confirmed_page(target_message: Message, *, db, telegram_user, page: int):
    user_id = await upsert_user(db, telegram_user.id, telegram_user.first_name, telegram_user.username)

    company_id = await get_user_active_company_id(db, user_id)
    if company_id is None:
        await target_message.answer("🏢 Primero selecciona tu empresa activa:", reply_markup=await kb_company_select(db, "active_company"))
        return

    total = await count_confirmed_batches(db, user_id, company_id=company_id)
    if total == 0:
        await target_message.answer("Aún no tienes registros confirmados. Guarda uno con ✅ *Guardar*.")
        return

    total_pages = max(1, math.ceil(total / PAGE_SIZE))
    page = max(1, min(page, total_pages))
    offset = (page - 1) * PAGE_SIZE

    rows = await list_confirmed_batches_page(db, user_id, limit=PAGE_SIZE, offset=offset, company_id=company_id)

    lines = [f"📚 *Registros confirmados* (página {page}/{total_pages})\n"]
    for idx, r in enumerate(rows, start=offset + 1):
        dt_txt = _fmt_dt_local(r["confirmed_at"])
        lines.append(f"{idx}. ID {r['id']} — {r['friendly_name']} — S/ {float(r['total']):.2f} — {dt_txt}")

    lines.append("\n🔎 Ver detalle: `/registro <id_registro>`")
    lines.append("🗑️ Borrar: `/borrar_registro <id_registro>`")
    lines.append("📤 Exportar: `/exportar_registros 3,4`  o  `/exportar_registros all`")

    await target_message.answer("\n".join(lines), reply_markup=kb_confirmed_pager(page, total_pages))

# -----------------------------
# KEYBOARDS (UX mejorado)
# -----------------------------
def kb_actions(kind: str = "expense"):
    kind_text = "🟢 Ingreso" if kind == "income" else "🔴 Egreso"
    return InlineKeyboardMarkup(inline_keyboard=[
        [
            InlineKeyboardButton(text="✅ Guardar", callback_data="draft_save"),
            InlineKeyboardButton(text="✏️ Editar", callback_data="draft_edit_help"),
        ],
        [
            InlineKeyboardButton(text=kind_text, callback_data="kind_toggle"),
            InlineKeyboardButton(text="📚 Registros", callback_data="confirmed_list"),
        ],
        [
            InlineKeyboardButton(text="🏢 Empresa", callback_data="company_menu"),
            InlineKeyboardButton(text="🧾 Factura", callback_data="draft_set_invoice"),
            InlineKeyboardButton(text="🪪 RUC", callback_data="draft_set_ruc"),
        ],
        [
            InlineKeyboardButton(text="❌ Cancelar", callback_data="draft_cancel"),
            InlineKeyboardButton(text="⚙️ Modo", callback_data="mode_menu"),
        ]
    ])


def kb_start_main():
    """Teclado para /start (accesos generales)."""
    return InlineKeyboardMarkup(inline_keyboard=[
        [
            InlineKeyboardButton(text="👀 Ver borrador", callback_data="draft_view"),
            InlineKeyboardButton(text="📚 Registros", callback_data="confirmed_list"),
        ],
        [
            InlineKeyboardButton(text="🏢 Empresa", callback_data="company_menu"),
            InlineKeyboardButton(text="⚙️ Modo", callback_data="mode_menu"),
        ]
    ])


def kb_mode_menu(current: str):
    return InlineKeyboardMarkup(inline_keyboard=[
        [
            InlineKeyboardButton(
                text=("✅ Contable" if current == "contable" else "📘 Contable"),
                callback_data="mode_set:contable"
            ),
            InlineKeyboardButton(
                text=("✅ Personal" if current == "personal" else "🙂 Personal"),
                callback_data="mode_set:personal"
            ),
        ],
        [InlineKeyboardButton(text="⬅️ Volver", callback_data="draft_view")]
    ])

async def kb_company_select(db, prefix: str):
    rows = await list_companies(db)
    buttons = []
    for r in rows:
        cid = int(r["id"])
        name = str(r["name"])
        buttons.append([InlineKeyboardButton(text=f"{cid}) {name}", callback_data=f"{prefix}:{cid}")])
    buttons.append([InlineKeyboardButton(text="⬅️ Volver", callback_data="draft_view")])
    return InlineKeyboardMarkup(inline_keyboard=buttons)

async def _apply_extracted_batch_meta(db, *, user_id: int, batch_id: int, data: dict):
    company_ruc = data.get("company_ruc") or data.get("ruc") or None
    invoice_number = data.get("invoice_number") or data.get("nro_factura") or data.get("factura") or None

    if isinstance(company_ruc, str):
        company_ruc = company_ruc.strip()
        if company_ruc == "":
            company_ruc = None
    if isinstance(invoice_number, str):
        invoice_number = invoice_number.strip()
        if invoice_number == "":
            invoice_number = None

    if company_ruc is not None:
        await set_batch_company_ruc(db, batch_id, user_id, company_ruc)
    if invoice_number is not None:
        await set_batch_invoice_number(db, batch_id, user_id, invoice_number)


def _try_parse_structured(rest: str):
    """
    Intenta parsear formato viejo:
    descripcion="..." precio=.. fecha="YYYY-MM-DD HH:MM"
    """
    import re
    desc = None
    price = None
    dt_str = None

    m = re.search(r'descripcion="([^"]+)"', rest, re.IGNORECASE)
    if m:
        desc = m.group(1).strip()

    m = re.search(r'precio=([0-9]+(\.[0-9]+)?)', rest, re.IGNORECASE)
    if m:
        price = float(m.group(1))

    m = re.search(r'fecha="([^"]+)"', rest, re.IGNORECASE)
    if m:
        dt_str = m.group(1).strip()

    return desc, price, dt_str


def _parse_datetime_local(dt_str: str) -> datetime:
    """
    Acepta:
    - YYYY-MM-DD
    - YYYY-MM-DD HH:MM
    """
    if len(dt_str) == 10:
        d = datetime.fromisoformat(dt_str)
        return d.replace(tzinfo=TZ)
    d = datetime.fromisoformat(dt_str)
    return d.replace(tzinfo=TZ)


async def _send_draft(message: Message, db, user_id: int, title: str, mode: str):
    """Helper para mostrar borrador directamente + botones."""
    batch_id = await get_or_create_draft_batch(db, user_id)
    items = await fetch_batch_items(db, batch_id)
    
    info = await get_draft_batch_info(db, batch_id)
    kind = (info["kind"] if info and info["kind"] else "expense")
    company_name = (info["company_name"] if info and info["company_name"] else None)
    company_ruc = (info["company_ruc"] if info and info["company_ruc"] else None)
    invoice_number = (info["invoice_number"] if info and info["invoice_number"] else None)

    kind_label = "Ingreso" if kind == "income" else "Egreso"
    kind_emoji = "🟢" if kind == "income" else "🔴"
    company_txt = company_name or "No seleccionada"
    ruc_txt = company_ruc or "-"
    inv_txt = invoice_number or "-"

    await message.answer(
        f"{title}\n\n"
        f"🏢 *Empresa:* {company_txt}\n"
        f"🪪 *RUC:* {ruc_txt}\n"
        f"🧾 *Factura:* {inv_txt}\n\n"
        f"📌 *Borrador ({kind_emoji} {kind_label}):*\n{format_items(items)}\n\n"
        f"⚙️ Modo: *{mode.upper()}*",
        reply_markup=kb_actions(kind)
    )


def _parse_export_arg(arg: str):
    """
    /exportar_registros all
    /exportar_registros 3,4
    /exportar_registros 3, 4, 10
    """
    a = (arg or "").strip().lower()
    if a == "all":
        return None  # None = exportar todo

    # permitir espacios
    a = a.replace(" ", "")
    parts = [p for p in a.split(",") if p]

    ids = []
    for p in parts:
        if not p.isdigit():
            raise ValueError("IDs inválidos")
        ids.append(int(p))

    if not ids:
        raise ValueError("IDs vacíos")

    # quitar duplicados preservando orden
    seen = set()
    clean = []
    for i in ids:
        if i not in seen:
            seen.add(i)
            clean.append(i)
    return clean


def build_excel_from_rows(rows):
    """
    rows: lista de asyncpg.Record con campos:
    batch_id, friendly_name, confirmed_at, company_name, company_ruc, invoice_number,
    item_id, description, price, item_datetime
    """
    wb = Workbook()

    ws_sum = wb.active
    ws_sum.title = "Resumen"
    ws_sum.append(["Empresa", "RUC", "Factura", "ID Registro", "Nombre", "Fecha confirmación", "Total (S/)"])

    ws_items = wb.create_sheet("Items")
    ws_items.append(["Empresa", "RUC", "Factura", "ID Registro", "Nombre", "Fecha confirmación", "ID Item", "Descripción", "Precio (S/)", "Fecha item"])

    # Agrupar totales por batch
    totals = {}
    meta = {}
    for r in rows:
        bid = int(r["batch_id"])
        totals[bid] = totals.get(bid, 0.0) + float(r["price"])
        meta[bid] = (r["friendly_name"], r["confirmed_at"], r["company_name"], r["company_ruc"], r["invoice_number"])

    # Resumen
    for bid, total in totals.items():
        name, confirmed_at, company_name, company_ruc, invoice_number = meta[bid]
        ws_sum.append([company_name, company_ruc, invoice_number, bid, name, _fmt_dt_local(confirmed_at), round(total, 2)])

    # Items
    for r in rows:
        ws_items.append([
            r["company_name"],
            r["company_ruc"],
            r["invoice_number"],
            int(r["batch_id"]),
            r["friendly_name"],
            _fmt_dt_local(r["confirmed_at"]),
            int(r["item_id"]),
            r["description"],
            float(r["price"]),
            _fmt_dt_local(r["item_datetime"]),
        ])

    return wb

async def main():
    log.info("MAIN: inicio")

    load_dotenv()
    log.info("MAIN: dotenv ok")

    # ✅ usa un nombre distinto para evitar colisiones
    dispatcher = Dispatcher(storage=MemoryStorage())

    bot = Bot(
        token=os.environ["TELEGRAM_BOT_TOKEN"],
        default=DefaultBotProperties(parse_mode="Markdown"),
    )

    db = await create_pool(os.environ["DATABASE_URL"])

    ai = AI(
        api_key=os.environ["OPENAI_API_KEY"],
        extract_model=os.getenv("OPENAI_EXTRACT_MODEL", "gpt-5-mini"),
        transcribe_model=os.getenv("OPENAI_TRANSCRIBE_MODEL", "gpt-4o-mini-transcribe"),
    )

    log.info("MAIN: inicialización ok, iniciando polling…")

    # -----------------------------
    # START
    # -----------------------------
    @dispatcher.message(Command("start"))
    async def cmd_start(message: Message):
        mode = await get_user_mode(db, message.from_user.id)
        await message.answer(
            "🤖 *Bot Smart Data*\n"
            "Envíame *texto, foto o audio* y lo convierto en un registro.\n\n"
            "📌 Ejemplo:\n"
            "_pan 2.50, gaseosa 5, pasajes 3_\n\n"
            f"⚙️ Modo: *{mode.upper()}*\n"
            "🏢 Tip: define tu empresa con el botón *Empresa*.",
            reply_markup=kb_start_main()
        )


    @dispatcher.message(Command("exportar_registros"))
    async def cmd_exportar_registros(message: Message):
        parts = message.text.split(maxsplit=1)
        if len(parts) < 2:
            await message.answer(
                "Uso:\n"
                "• `/exportar_registros 3,4`\n"
                "• `/exportar_registros all`",
            )
            return

        try:
            ids = _parse_export_arg(parts[1])
        except Exception:
            await message.answer("Formato inválido. Ej: `/exportar_registros 3,4` o `/exportar_registros all`")
            return

        user_id = await upsert_user(db, message.from_user.id, message.from_user.first_name, message.from_user.username)
        company_id = await get_user_active_company_id(db, user_id)
        if company_id is None:
            await message.answer("🏢 Primero selecciona tu empresa activa:", reply_markup=await kb_company_select(db, "active_company"))
            return

        # rows para export
        if ids is None:
            rows = await export_confirmed_rows(db, user_id, batch_ids=None, company_id=company_id)
            label = "all"
        else:
            rows = await export_confirmed_rows(db, user_id, batch_ids=ids, company_id=company_id)
            label = ",".join(str(i) for i in ids)

        if not rows:
            await message.answer("No encontré registros confirmados para exportar con esos IDs.")
            return

        wb = build_excel_from_rows(rows)

        with NamedTemporaryFile(delete=False, suffix=".xlsx") as tmp:
            path = tmp.name
        wb.save(path)

        filename = f"balancesteve_confirmados_{label}.xlsx"
        doc = FSInputFile(path, filename=filename)

        await message.answer_document(
            doc,
            caption=f"📤 Export listo. Registros: *{label}*"
        )


    async def _needs_invoice_prompt_for_confirm(batch_id: int) -> bool:
        info = await get_draft_batch_info(db, batch_id)
        invoice_number = (info["invoice_number"] if info and info["invoice_number"] else None)
        if invoice_number:
            last_input_type = await get_last_raw_input_type(db, batch_id)
            if last_input_type == "image":
                return False
        return True

    async def _confirm_and_reply(reply_to: Message, telegram_user_id: int, batch_id: int, friendly_name: str, state: FSMContext) -> None:
        mode = await get_user_mode(db, telegram_user_id)
        items = await fetch_batch_items(db, batch_id)
        if not items:
            await reply_to.answer("Tu borrador está vacío. No hay nada que guardar.", reply_markup=kb_actions())
            await state.clear()
            return

        await confirm_batch(db, batch_id, friendly_name)
        await state.clear()

        info = await get_draft_batch_info(db, batch_id)
        company_txt = (info["company_name"] if info and info["company_name"] else "No seleccionada")
        ruc_txt = (info["company_ruc"] if info and info["company_ruc"] else "-")
        inv_txt = (info["invoice_number"] if info and info["invoice_number"] else "-")

        await reply_to.answer(
            "✅ *Registro guardado:* " + friendly_name + "\n"
            f"🏢 *Empresa:* {company_txt}\n"
            f"🪪 *RUC:* {ruc_txt}\n"
            f"🧾 *Factura:* {inv_txt}\n\n"
            + format_items(items)
            + f"\n\n⚙️ Modo: *{mode.upper()}*",
            reply_markup=kb_actions()
        )

    # -----------------------------
    # CALLBACKS BOTONES
    # -----------------------------
    @dispatcher.callback_query(F.data == "draft_view")
    async def cb_draft_view(call: CallbackQuery):
        user_id = await upsert_user(db, call.from_user.id, call.from_user.first_name, call.from_user.username)
        mode = await get_user_mode(db, call.from_user.id)
        batch_id = await get_or_create_draft_batch(db, user_id)
        items = await fetch_batch_items(db, batch_id)
        
        info = await get_draft_batch_info(db, batch_id)
        kind = (info["kind"] if info and info["kind"] else "expense")
        company_name = (info["company_name"] if info and info["company_name"] else None)
        company_ruc = (info["company_ruc"] if info and info["company_ruc"] else None)
        invoice_number = (info["invoice_number"] if info and info["invoice_number"] else None)
        kind_label = "Ingreso" if kind == "income" else "Egreso"
        kind_emoji = "🟢" if kind == "income" else "🔴"
        company_txt = company_name or "No seleccionada"
        ruc_txt = company_ruc or "-"
        inv_txt = invoice_number or "-"

        await call.message.answer(
            f"🏢 *Empresa:* {company_txt}\n"
            f"🪪 *RUC:* {ruc_txt}\n"
            f"🧾 *Factura:* {inv_txt}\n\n"
            f"📌 *Borrador actual ({kind_emoji} {kind_label}):*\n"
            + format_items(items)
            + f"\n\n⚙️ Modo: *{mode.upper()}*",
            reply_markup=kb_actions(kind)
        )
        await call.answer()

    @dispatcher.callback_query(F.data == "draft_edit_help")
    async def cb_draft_edit_help(call: CallbackQuery):
        await call.message.answer(
            "✏️ *Editar*\n\n"
            "Escribe así (español normal):\n"
            "• `/editar 3 precio es 80`\n"
            "• `/editar 3 descripcion es teclado y mouse`\n"
            "• `/editar 3 descripcion es teclado y mouse y su precio es 80`\n\n"
            "Tip: Los IDs están en el borrador que te muestro arriba 😉",
            reply_markup=kb_actions()
        )
        await call.answer()

    @dispatcher.callback_query(F.data == "draft_save")
    async def cb_draft_save(call: CallbackQuery, state: FSMContext):
        user_id = await upsert_user(db, call.from_user.id, call.from_user.first_name, call.from_user.username)
        batch_id = await get_or_create_draft_batch(db, user_id)
        items = await fetch_batch_items(db, batch_id)

        if not items:
            await call.message.answer("Tu borrador está vacío. Envíame texto/foto/audio primero.", reply_markup=kb_actions())
            await call.answer()
            return

        await state.set_state(ConfirmFlow.waiting_friendly_name)
        await call.message.answer(
            "✅ *Guardar registro*\n"
            "Escríbeme el nombre del registro.\n"
            "Ej: `Venta a cliente`, `Pago de servicio`, `Compra de insumos`"
        )
        await call.answer()

    @dispatcher.message(ConfirmFlow.waiting_friendly_name)
    async def on_friendly_name(message: Message, state: FSMContext):
        name = (message.text or "").strip()
        if not name:
            await message.answer("Escríbeme un nombre válido 🙂")
            return

        user_id = await upsert_user(db, message.from_user.id, message.from_user.first_name, message.from_user.username)
        batch_id = await get_or_create_draft_batch(db, user_id)
        items = await fetch_batch_items(db, batch_id)

        if not items:
            await message.answer("Tu borrador está vacío. No hay nada que guardar.", reply_markup=kb_actions())
            await state.clear()
            return

        await state.update_data(friendly_name=name)
        await state.set_state(ConfirmFlow.waiting_company)
        await message.answer("🏢 Selecciona la empresa para este registro:", reply_markup=await kb_company_select(db, "confirm_company"))

    @dispatcher.callback_query(ConfirmFlow.waiting_company, F.data.startswith("confirm_company:"))
    async def cb_confirm_company(call: CallbackQuery, state: FSMContext):
        try:
            company_id = int(call.data.split(":", 1)[1])
        except Exception:
            await call.answer("Empresa inválida", show_alert=True)
            return

        user_id = await upsert_user(db, call.from_user.id, call.from_user.first_name, call.from_user.username)
        batch_id = await get_or_create_draft_batch(db, user_id)
        ok = await set_batch_company(db, batch_id, user_id, company_id)
        if not ok:
            await call.answer("No pude asignar la empresa", show_alert=True)
            return

        await set_user_active_company_id(db, user_id, company_id)

        info = await get_draft_batch_info(db, batch_id)
        if not (info and info["company_ruc"]):
            await state.set_state(ConfirmFlow.waiting_company_ruc)
            await call.message.answer("🪪 Escribe el RUC de la empresa (11 dígitos) o `-` para omitir:")
            await call.answer()
            return

        needs_invoice = await _needs_invoice_prompt_for_confirm(batch_id)
        if needs_invoice:
            await state.set_state(ConfirmFlow.waiting_invoice_number)
            await call.message.answer("🧾 Escribe el número de factura (ej: F001-00001234) o `-` para omitir:")
            await call.answer()
            return

        data = await state.get_data()
        friendly_name = (data.get("friendly_name") or "").strip()
        if not friendly_name:
            await call.message.answer("No encontré el nombre del registro. Intenta guardar nuevamente.", reply_markup=kb_actions())
            await state.clear()
            await call.answer()
            return

        await _confirm_and_reply(call.message, call.from_user.id, batch_id, friendly_name, state)
        await call.answer()

    @dispatcher.message(ConfirmFlow.waiting_company_ruc)
    async def on_confirm_company_ruc(message: Message, state: FSMContext):
        raw = (message.text or "").strip()
        val = None if raw in ("", "-") else raw

        user_id = await upsert_user(db, message.from_user.id, message.from_user.first_name, message.from_user.username)
        batch_id = await get_or_create_draft_batch(db, user_id)
        await set_batch_company_ruc(db, batch_id, user_id, val)

        needs_invoice = await _needs_invoice_prompt_for_confirm(batch_id)
        if needs_invoice:
            await state.set_state(ConfirmFlow.waiting_invoice_number)
            await message.answer("🧾 Escribe el número de factura (ej: F001-00001234) o `-` para omitir:")
            return

        data = await state.get_data()
        friendly_name = (data.get("friendly_name") or "").strip()
        if not friendly_name:
            await message.answer("No encontré el nombre del registro. Intenta guardar nuevamente.", reply_markup=kb_actions())
            await state.clear()
            return

        await _confirm_and_reply(message, message.from_user.id, batch_id, friendly_name, state)

    @dispatcher.message(ConfirmFlow.waiting_invoice_number)
    async def on_confirm_invoice_number(message: Message, state: FSMContext):
        raw = (message.text or "").strip()
        val = None if raw in ("", "-") else raw

        data = await state.get_data()
        friendly_name = (data.get("friendly_name") or "").strip()

        if not friendly_name:
            await message.answer("No encontré el nombre del registro. Intenta guardar nuevamente.", reply_markup=kb_actions())
            await state.clear()
            return

        user_id = await upsert_user(db, message.from_user.id, message.from_user.first_name, message.from_user.username)
        mode = await get_user_mode(db, message.from_user.id)
        batch_id = await get_or_create_draft_batch(db, user_id)
        items = await fetch_batch_items(db, batch_id)

        if not items:
            await message.answer("Tu borrador está vacío. No hay nada que guardar.", reply_markup=kb_actions())
            await state.clear()
            return

        await set_batch_invoice_number(db, batch_id, user_id, val)
        await _confirm_and_reply(message, message.from_user.id, batch_id, friendly_name, state)

    @dispatcher.callback_query(F.data == "draft_cancel")
    async def cb_draft_cancel(call: CallbackQuery, state: FSMContext):
        await state.clear()

        user_id = await upsert_user(db, call.from_user.id, call.from_user.first_name, call.from_user.username)
        ok = await delete_draft_batch(db, user_id)

        if ok:
            await call.message.answer("❌ Borrador cancelado y eliminado. Envíame otra entrada cuando quieras.", reply_markup=kb_start_main())
        else:
            await call.message.answer("No encontré un borrador para cancelar.", reply_markup=kb_start_main())

        await call.answer()

    @dispatcher.callback_query(F.data.startswith("confirmed_page:"))
    async def cb_confirmed_page(call: CallbackQuery):
        try:
            page = int(call.data.split(":")[1])
        except Exception:
            await call.answer("Página inválida", show_alert=True)
            return

        await send_confirmed_page(call.message, db=db, telegram_user=call.from_user, page=page)
        await call.answer()


    @dispatcher.callback_query(F.data == "mode_menu")
    async def cb_mode_menu(call: CallbackQuery):
        current = await get_user_mode(db, call.from_user.id)
        await call.message.answer("⚙️ *Elige tu modo:*", reply_markup=kb_mode_menu(current))
        await call.answer()

    @dispatcher.callback_query(F.data.startswith("mode_set:"))
    async def cb_mode_set(call: CallbackQuery):
        mode = call.data.split(":", 1)[1]
        if mode not in ("contable", "personal"):
            await call.answer("Modo inválido", show_alert=True)
            return

        await set_user_mode(db, call.from_user.id, mode)
        await call.message.answer(f"✅ Modo cambiado a *{mode.upper()}*.", reply_markup=kb_actions())
        await call.answer()

    @dispatcher.callback_query(F.data == "confirmed_list")
    async def cb_confirmed_list(call: CallbackQuery):
        await send_confirmed_page(call.message, db=db, telegram_user=call.from_user, page=1)
        await call.answer()

    @dispatcher.callback_query(F.data == "company_menu")
    async def cb_company_menu(call: CallbackQuery):
        await call.message.answer("🏢 Elige tu empresa activa:", reply_markup=await kb_company_select(db, "active_company"))
        await call.answer()

    @dispatcher.callback_query(F.data.startswith("active_company:"))
    async def cb_active_company(call: CallbackQuery):
        try:
            company_id = int(call.data.split(":", 1)[1])
        except Exception:
            await call.answer("Empresa inválida", show_alert=True)
            return

        user_id = await upsert_user(db, call.from_user.id, call.from_user.first_name, call.from_user.username)
        await set_user_active_company_id(db, user_id, company_id)

        batch_id = await get_or_create_draft_batch(db, user_id)
        await set_batch_company(db, batch_id, user_id, company_id)

        mode = await get_user_mode(db, call.from_user.id)
        await _send_draft(call.message, db, user_id, "✅ *Empresa activa actualizada.*", mode)
        await call.answer()

    @dispatcher.callback_query(F.data == "draft_set_ruc")
    async def cb_draft_set_ruc(call: CallbackQuery, state: FSMContext):
        await state.set_state(DraftMetaFlow.waiting_company_ruc)
        await call.message.answer("🪪 Escribe el RUC de la empresa para este borrador (11 dígitos) o `-` para borrar:")
        await call.answer()

    @dispatcher.message(DraftMetaFlow.waiting_company_ruc)
    async def on_draft_set_ruc(message: Message, state: FSMContext):
        raw = (message.text or "").strip()
        val = None if raw in ("", "-") else raw

        user_id = await upsert_user(db, message.from_user.id, message.from_user.first_name, message.from_user.username)
        batch_id = await get_or_create_draft_batch(db, user_id)
        await set_batch_company_ruc(db, batch_id, user_id, val)
        await state.clear()

        mode = await get_user_mode(db, message.from_user.id)
        await _send_draft(message, db, user_id, "✅ *RUC actualizado.*", mode)

    @dispatcher.callback_query(F.data == "draft_set_invoice")
    async def cb_draft_set_invoice(call: CallbackQuery, state: FSMContext):
        await state.set_state(DraftMetaFlow.waiting_invoice_number)
        await call.message.answer("🧾 Escribe el número de factura para este borrador o `-` para borrar:")
        await call.answer()

    @dispatcher.message(DraftMetaFlow.waiting_invoice_number)
    async def on_draft_set_invoice(message: Message, state: FSMContext):
        raw = (message.text or "").strip()
        val = None if raw in ("", "-") else raw

        user_id = await upsert_user(db, message.from_user.id, message.from_user.first_name, message.from_user.username)
        batch_id = await get_or_create_draft_batch(db, user_id)
        await set_batch_invoice_number(db, batch_id, user_id, val)
        await state.clear()

        mode = await get_user_mode(db, message.from_user.id)
        await _send_draft(message, db, user_id, "✅ *Factura actualizada.*", mode)

    # -----------------------------
    # COMANDOS REGISTROS (LOS TUYOS)
    # -----------------------------

    @dispatcher.message(Command("registros"))
    async def cmd_registros(message: Message):
        await send_confirmed_page(message, db=db, telegram_user=message.from_user, page=1)


    @dispatcher.message(Command("registro"))
    async def cmd_registro(message: Message):
        parts = message.text.split(maxsplit=1)
        if len(parts) < 2:
            await message.answer("Uso: /registro <id_registro>", reply_markup=kb_start_main())
            return

        try:
            batch_id = int(parts[1])
        except ValueError:
            await message.answer("El id_registro debe ser número. Ej: /registro 12", reply_markup=kb_start_main())
            return

        user_id = await upsert_user(db, message.from_user.id, message.from_user.first_name, message.from_user.username)
        company_id = await get_user_active_company_id(db, user_id)
        if company_id is None:
            await message.answer("🏢 Primero selecciona tu empresa activa:", reply_markup=await kb_company_select(db, "active_company"))
            return
        items = await get_confirmed_batch_items(db, user_id, batch_id, company_id=company_id)

        if not items:
            await message.answer("No encontré ese registro confirmado (o no te pertenece). Prueba /registros.", reply_markup=kb_start_main())
            return

        total = sum(float(i["price"]) for i in items)
        lines = [f"🧾 *Detalle del registro ID {batch_id}:*\n"]
        for it in items:
            dt_txt = _fmt_dt_local(it["item_datetime"])
            lines.append(
                f"- Item {it['id']}: {it['description']} | "
                f"S/ {float(it['price']):.2f} | {dt_txt}"
            )

        await message.answer("\n".join(lines), reply_markup=kb_start_main())

    @dispatcher.message(Command("borrar_registro"))
    async def cmd_borrar_registro(message: Message):
        parts = message.text.split(maxsplit=1)
        if len(parts) < 2:
            await message.answer("Uso: /borrar_registro <id_registro>", reply_markup=kb_start_main())
            return

        try:
            batch_id = int(parts[1])
        except ValueError:
            await message.answer("El id_registro debe ser número. Ej: /borrar_registro 12", reply_markup=kb_start_main())
            return

        user_id = await upsert_user(db, message.from_user.id, message.from_user.first_name, message.from_user.username)
        company_id = await get_user_active_company_id(db, user_id)
        if company_id is None:
            await message.answer("🏢 Primero selecciona tu empresa activa:", reply_markup=await kb_company_select(db, "active_company"))
            return
        ok = await delete_confirmed_batch(db, user_id, batch_id, company_id=company_id)

        if not ok:
            await message.answer("No pude borrar: ese registro no existe, no es tuyo, o no está confirmado. Usa /registros.", reply_markup=kb_start_main())
            return

        await message.answer(f"🗑️ Registro ID {batch_id} borrado. (Se eliminaron también sus items).", reply_markup=kb_start_main())

    # -----------------------------
    # BORRADOR + CONFIRMAR (LOS TUYOS)
    # -----------------------------
    @dispatcher.message(Command("ver"))
    async def cmd_ver(message: Message):
        user_id = await upsert_user(db, message.from_user.id, message.from_user.first_name, message.from_user.username)
        mode = await get_user_mode(db, message.from_user.id)
        await _send_draft(message, db, user_id, "📌 *Borrador actual:*", mode)

    @dispatcher.message(Command("confirmar"))
    async def cmd_confirmar(message: Message, state: FSMContext):
        name = message.text.split(" ", 1)[1].strip() if " " in message.text else ""
        if not name:
            await message.answer("Uso: /confirmar Nombre amigable (ej: /confirmar Mercado lunes)", reply_markup=kb_actions())
            return

        user_id = await upsert_user(db, message.from_user.id, message.from_user.first_name, message.from_user.username)
        batch_id = await get_or_create_draft_batch(db, user_id)
        items = await fetch_batch_items(db, batch_id)

        if not items:
            await message.answer("Tu borrador está vacío. Mándame un texto/foto/audio primero.", reply_markup=kb_actions())
            return

        await state.clear()
        await state.set_state(ConfirmFlow.waiting_company)
        await state.update_data(friendly_name=name)
        await message.answer("🏢 Selecciona la empresa para este registro:", reply_markup=await kb_company_select(db, "confirm_company"))

    # -----------------------------
    # EDITAR (TU LÓGICA + IA)
    # -----------------------------
    @dispatcher.message(Command("editar"))
    async def cmd_editar(message: Message):
        parts = message.text.split(maxsplit=2)
        if len(parts) < 2:
            await message.answer(
                "Uso: /editar <idproducto> <instrucción>\n"
                "Ejemplos:\n"
                "/editar 11 precio es 80\n"
                "/editar 11 descripcion es teclado y mouse y su precio es 80\n"
                "/editar 11 descripcion=\"Teclado\" precio=80\n",
                reply_markup=kb_actions()
            )
            return

        try:
            item_id = int(parts[1])
        except ValueError:
            await message.answer("El idproducto debe ser número. Ej: /editar 11 precio es 80", reply_markup=kb_actions())
            return

        rest = parts[2].strip() if len(parts) == 3 else ""
        if not rest:
            await message.answer("Dime qué quieres cambiar. Ej: /editar 11 precio es 80", reply_markup=kb_actions())
            return

        desc, price, dt_str = _try_parse_structured(rest)
        dt = None
        if dt_str:
            try:
                dt = _parse_datetime_local(dt_str)
            except Exception:
                await message.answer("Fecha inválida. Usa: fecha=\"YYYY-MM-DD\" o fecha=\"YYYY-MM-DD HH:MM\"", reply_markup=kb_actions())
                return

        if desc is None and price is None and dt is None:
            parsed = ai.parse_edit(rest)
            desc = parsed.get("description", None)
            price = parsed.get("price", None)
            dt_raw = parsed.get("datetime", None)

            if dt_raw:
                try:
                    dt = _parse_datetime_local(dt_raw)
                except Exception:
                    dt = None

        if desc is None and price is None and dt is None:
            await message.answer(
                "No pude entender qué editar.\n"
                "Prueba así:\n"
                "/editar 11 precio es 80\n"
                "/editar 11 descripcion es teclado y mouse\n"
                "/editar 11 descripcion es teclado y mouse y su precio es 80",
                reply_markup=kb_actions()
            )
            return

        ok = await update_item_for_user(
            db=db,
            telegram_user_id=message.from_user.id,
            item_id=item_id,
            description=desc,
            price=price,
            item_datetime=dt
        )

        if not ok:
            await message.answer(
                "No pude actualizar. Causas típicas:\n"
                "1) El ID no existe\n"
                "2) Ese ID no te pertenece (otro usuario)\n"
                "Haz /ver y usa el ID exacto que aparece en tu borrador.",
                reply_markup=kb_actions()
            )
            return

        user_id = await upsert_user(db, message.from_user.id, message.from_user.first_name, message.from_user.username)
        mode = await get_user_mode(db, message.from_user.id)
        await _send_draft(message, db, user_id, "✏️ *Item actualizado.*", mode)

    # -----------------------------
    # CONSULTAR (TU FUNCIÓN)
    # -----------------------------
    @dispatcher.message(Command("consultar"))
    async def cmd_consultar(message: Message):
        args = message.text.split(" ", 1)[1].strip() if " " in message.text else ""
        if not args:
            await message.answer("Uso: /consultar 2026-01-05   o   /consultar 2026-01-05 18:00", reply_markup=kb_start_main())
            return

        try:
            start_dt, end_dt = parse_dt_range(args)
        except Exception:
            await message.answer("Formato inválido. Ejemplos:\n/consultar 2026-01-05\n/consultar 2026-01-05 18:00", reply_markup=kb_start_main())
            return

        user_id = await upsert_user(db, message.from_user.id, message.from_user.first_name, message.from_user.username)
        company_id = await get_user_active_company_id(db, user_id)
        if company_id is None:
            await message.answer("🏢 Primero selecciona tu empresa activa:", reply_markup=await kb_company_select(db, "active_company"))
            return
        rows = await query_items_by_datetime(db, user_id, start_dt, end_dt, company_id=company_id)
        if not rows:
            await message.answer("No hay registros confirmados en ese rango.", reply_markup=kb_start_main())
            return

        lines = [f"📅 Consulta {start_dt} → {end_dt}\n"]
        for r in rows:
            fname = r["friendly_name"] or "(sin nombre)"
            lines.append(
                f"[{fname}] ID {r['id']}: {r['description']} | "
                f"S/ {float(r['price']):.2f} | {r['item_datetime']}"
            )
        await message.answer("\n".join(lines), reply_markup=kb_start_main())

    # -----------------------------
    # HANDLERS: TEXTO / FOTO / AUDIO
    # ✅ Ahora muestran el BORRADOR directo
    # -----------------------------
    @dispatcher.message(F.text & ~F.text.startswith("/"))
    async def handle_text(message: Message):
        user_id = await upsert_user(db, message.from_user.id, message.from_user.first_name, message.from_user.username)
        batch_id = await get_or_create_draft_batch(db, user_id)

        msg_dt = datetime.fromtimestamp(message.date.timestamp(), tz=TZ)
        await add_raw_input(db, batch_id, "text", message.text)

        mode = await get_user_mode(db, message.from_user.id)
        data = ai.extract_from_text(f"mode={mode}\n\n{message.text}", msg_dt)
        await _apply_extracted_batch_meta(db, user_id=user_id, batch_id=batch_id, data=data)

        items = []
        for it in data.get("items", []):
            dt = datetime.fromisoformat(it["datetime"])
            if dt.tzinfo is None:
                dt = dt.replace(tzinfo=TZ)
            else:
                dt = dt.astimezone(TZ)

            pm = (it.get("payment_method") or "cash").strip().lower()

            # normalización por si la IA devuelve "efectivo" o "transferencia"
            map_pm = {
                "efectivo": "cash",
                "cash": "cash",
                "yape": "yape",
                "plin": "plin",
                "transferencia": "transfer",
                "transfer": "transfer",
                "transferencia_bancaria": "transfer",
                "otro": "other",
                "otros": "other",
                "other": "other",
            }
            pm = map_pm.get(pm, "cash")

            items.append({
                "description": it["description"],
                "price": float(it.get("price", 0)),
                "item_datetime": dt,
                "payment_method": pm
            })


        await insert_items(db, batch_id, items)
        await _send_draft(message, db, user_id, "🧾 *Listo. Registré tu entrada.*", mode)


    @dispatcher.callback_query(F.data == "kind_toggle")
    async def cb_kind_toggle(call: CallbackQuery):
        user_id = await upsert_user(db, call.from_user.id, call.from_user.first_name, call.from_user.username)
        batch_id = await get_or_create_draft_batch(db, user_id)

        info = await get_draft_batch_info(db, batch_id)
        current = (info["kind"] if info and info["kind"] else "expense")
        new_kind = "income" if current == "expense" else "expense"

        ok = await set_batch_kind(db, batch_id, user_id, new_kind)
        if not ok:
            await call.answer("No pude cambiar el tipo 😕", show_alert=True)
            return

        mode = await get_user_mode(db, call.from_user.id)
        items = await fetch_batch_items(db, batch_id)

        kind_label = "Ingreso" if new_kind == "income" else "Egreso"
        kind_emoji = "🟢" if new_kind == "income" else "🔴"

        await call.message.answer(
            f"✅ Tipo cambiado a {kind_emoji} *{kind_label}*\n\n"
            f"📌 *Borrador:*\n{format_items(items)}\n\n"
            f"⚙️ Modo: *{mode.upper()}*",
            reply_markup=kb_actions(new_kind)
        )
        await call.answer()



    @dispatcher.message(F.photo)
    async def handle_photo(message: Message):
        user_id = await upsert_user(db, message.from_user.id, message.from_user.first_name, message.from_user.username)
        batch_id = await get_or_create_draft_batch(db, user_id)
        msg_dt = datetime.fromtimestamp(message.date.timestamp(), tz=TZ)

        photo = message.photo[-1]
        file = await bot.get_file(photo.file_id)
        content = await bot.download_file(file.file_path)

        image_bytes = content.read()
        remote_path = (file.file_path or "")
        ext = os.path.splitext(remote_path)[1].lower()
        if ext not in (".jpg", ".jpeg", ".png", ".webp"):
            ext = ".jpg"
        mime_map = {
            ".jpg": "image/jpeg",
            ".jpeg": "image/jpeg",
            ".png": "image/png",
            ".webp": "image/webp",
        }
        mime = mime_map.get(ext, "image/jpeg")

        raw_input_id = await add_raw_input(db, batch_id, "image", None)
        try:
            batch_dir = os.path.join(MEDIA_DIR, str(batch_id))
            os.makedirs(batch_dir, exist_ok=True)
            fname = f"{raw_input_id}{ext}"
            abs_path = os.path.join(batch_dir, fname)
            with open(abs_path, "wb") as f:
                f.write(image_bytes)
            rel_path = f"uploads/raw_inputs/{batch_id}/{fname}"
            await set_raw_input_file(db, raw_input_id, rel_path, mime, os.path.basename(remote_path) or fname)
        except Exception:
            pass

        mode = await get_user_mode(db, message.from_user.id)

        # Evitamos kwargs tipo extra_text por compatibilidad.
        # Le pasamos el modo como texto "contexto" dentro de la entrada.
        # (Tu AI class ya soporta extract_from_image(image_bytes, mime, msg_dt))
        data = ai.extract_from_image(image_bytes, mime, msg_dt, extra_text=f"mode={mode}")
        await _apply_extracted_batch_meta(db, user_id=user_id, batch_id=batch_id, data=data)

        # Si quieres forzar el modo en visión, lo ideal es hacerlo en ai.py.
        # Aquí nos mantenemos compatibles para evitar crashes.

        items = []
        for it in data.get("items", []):
            dt = datetime.fromisoformat(it["datetime"])
            if dt.tzinfo is None:
                dt = dt.replace(tzinfo=TZ)
            else:
                dt = dt.astimezone(TZ)
                
            pm = (it.get("payment_method") or "cash").strip().lower()

            # normalización por si la IA devuelve "efectivo" o "transferencia"
            map_pm = {
                "efectivo": "cash",
                "cash": "cash",
                "yape": "yape",
                "plin": "plin",
                "transferencia": "transfer",
                "transfer": "transfer",
                "transferencia_bancaria": "transfer",
                "otro": "other",
                "otros": "other",
                "other": "other",
            }
            pm = map_pm.get(pm, "cash")

            items.append({
                "description": it["description"],
                "price": float(it.get("price", 0)),
                "item_datetime": dt,
                "payment_method": pm
            })


        await insert_items(db, batch_id, items)
        await _send_draft(message, db, user_id, "📷 *Listo. Procesé la imagen.*", mode)

    @dispatcher.message(F.voice | F.audio)
    async def handle_audio(message: Message):
        user_id = await upsert_user(db, message.from_user.id, message.from_user.first_name, message.from_user.username)
        batch_id = await get_or_create_draft_batch(db, user_id)
        msg_dt = datetime.fromtimestamp(message.date.timestamp(), tz=TZ)

        file_id = message.voice.file_id if message.voice else message.audio.file_id
        file = await bot.get_file(file_id)
        content = await bot.download_file(file.file_path)
        audio_bytes = content.read()
        remote_path = (file.file_path or "")
        ext = os.path.splitext(remote_path)[1].lower()
        if not ext:
            ext = ".ogg"
        if ext not in (".ogg", ".oga", ".mp3", ".m4a", ".wav"):
            ext = ".ogg"
        mime_map = {
            ".ogg": "audio/ogg",
            ".oga": "audio/ogg",
            ".mp3": "audio/mpeg",
            ".m4a": "audio/mp4",
            ".wav": "audio/wav",
        }
        mime = mime_map.get(ext, "audio/ogg")

        # Guardar audio a archivo temporal, transcribir y limpiar
        with NamedTemporaryFile(delete=False, suffix=ext) as tmp:
            tmp.write(audio_bytes)
            audio_path = tmp.name

        try:
            text = ai.transcribe_audio(audio_path)
        finally:
            try:
                os.remove(audio_path)
            except OSError:
                pass

        raw_input_id = await add_raw_input(db, batch_id, "audio", text)
        try:
            batch_dir = os.path.join(MEDIA_DIR, str(batch_id))
            os.makedirs(batch_dir, exist_ok=True)
            fname = f"{raw_input_id}{ext}"
            abs_path = os.path.join(batch_dir, fname)
            with open(abs_path, "wb") as f:
                f.write(audio_bytes)
            rel_path = f"uploads/raw_inputs/{batch_id}/{fname}"
            original_name = None
            if message.audio and getattr(message.audio, "file_name", None):
                original_name = message.audio.file_name
            if not original_name:
                original_name = os.path.basename(remote_path) or fname
            await set_raw_input_file(db, raw_input_id, rel_path, mime, original_name)
        except Exception:
            pass

        mode = await get_user_mode(db, message.from_user.id)
        data = ai.extract_from_text(f"mode={mode}\n\n{text}", msg_dt)
        await _apply_extracted_batch_meta(db, user_id=user_id, batch_id=batch_id, data=data)

        items = []
        for it in data.get("items", []):
            dt = datetime.fromisoformat(it["datetime"])
            if dt.tzinfo is None:
                dt = dt.replace(tzinfo=TZ)
            else:
                dt = dt.astimezone(TZ)
                
            pm = (it.get("payment_method") or "cash").strip().lower()

            # normalización por si la IA devuelve "efectivo" o "transferencia"
            map_pm = {
                "efectivo": "cash",
                "cash": "cash",
                "yape": "yape",
                "plin": "plin",
                "transferencia": "transfer",
                "transfer": "transfer",
                "transferencia_bancaria": "transfer",
                "otro": "other",
                "otros": "other",
                "other": "other",
            }
            pm = map_pm.get(pm, "cash")
            

            items.append({
                "description": it["description"],
                "price": float(it.get("price", 0)),
                "item_datetime": dt,
                "payment_method": pm
            })

        await insert_items(db, batch_id, items)
        await _send_draft(message, db, user_id, "🎙️ *Listo. Transcribí y registré tu audio.*", mode)


    log.info("MAIN: llamando dispatcher.start_polling(bot)...")
    await dispatcher.start_polling(bot)
    
if __name__ == "__main__":
    import asyncio
    asyncio.run(main())
