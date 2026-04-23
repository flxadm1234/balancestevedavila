import base64
import json
import re
from datetime import datetime
from typing import Optional, Dict, Any
from openai import OpenAI

from prompts import EXTRACTION_INSTRUCTIONS, EDIT_INSTRUCTIONS

def _data_url(mime: str, content_bytes: bytes) -> str:
    b64 = base64.b64encode(content_bytes).decode("utf-8")
    return f"data:{mime};base64,{b64}"

def _safe_json(s: str) -> Dict[str, Any]:
    s2 = s.strip()
    if s2.startswith("```"):
        s2 = s2.strip("`")
        s2 = s2.split("\n", 1)[-1].strip()
    return json.loads(s2)

def _coerce_price(val) -> Optional[float]:
    if val is None:
        return None
    if isinstance(val, (int, float)):
        return float(val)
    if isinstance(val, str):
        # Extraer número tipo 80 o 80.50
        m = re.search(r"([0-9]+(?:\.[0-9]+)?)", val.replace(",", "."))
        return float(m.group(1)) if m else None
    return None

class AI:
    def __init__(self, api_key: str, extract_model: str, transcribe_model: str):
        self.client = OpenAI(api_key=api_key)
        self.extract_model = extract_model
        self.transcribe_model = transcribe_model

    def transcribe_audio(self, audio_path: str) -> str:
        with open(audio_path, "rb") as f:
            tx = self.client.audio.transcriptions.create(
                model=self.transcribe_model,
                file=f,
                response_format="text",
            )
        return tx if isinstance(tx, str) else str(tx)

    def extract_from_text(self, text: str, message_datetime: datetime) -> Dict[str, Any]:
        resp = self.client.responses.create(
            model=self.extract_model,
            instructions=EXTRACTION_INSTRUCTIONS,
            input=[{
                "role": "user",
                "content": [
                    {"type": "input_text", "text": f"message_datetime: {message_datetime.isoformat()}"},
                    {"type": "input_text", "text": text},
                ],
            }],
        )
        return _safe_json(resp.output_text)

    def extract_from_image(self, image_bytes: bytes, mime: str, message_datetime: datetime, extra_text: Optional[str] = None) -> Dict[str, Any]:
        img_url = _data_url(mime, image_bytes)
        content = [
            {"type": "input_text", "text": f"message_datetime: {message_datetime.isoformat()}"},
            {"type": "input_image", "image_url": img_url},
        ]
        if extra_text:
            content.append({"type": "input_text", "text": extra_text})

        resp = self.client.responses.create(
            model=self.extract_model,
            instructions=EXTRACTION_INSTRUCTIONS,
            input=[{"role": "user", "content": content}],
        )
        return _safe_json(resp.output_text)

    def parse_edit(self, user_text: str) -> Dict[str, Any]:
        """
        Convierte texto libre en {description, price, datetime, notes}.
        """
        resp = self.client.responses.create(
            model=self.extract_model,
            instructions=EDIT_INSTRUCTIONS,
            input=[{
                "role": "user",
                "content": [
                    {"type": "input_text", "text": user_text},
                ],
            }],
        )
        data = _safe_json(resp.output_text)

        # Normalización defensiva
        out = {
            "description": data.get("description", None),
            "price": _coerce_price(data.get("price", None)),
            "datetime": data.get("datetime", None),
            "notes": data.get("notes", "") or ""
        }
        if isinstance(out["description"], str):
            out["description"] = out["description"].strip()
            if out["description"] == "":
                out["description"] = None
        if isinstance(out["datetime"], str):
            out["datetime"] = out["datetime"].strip()
            if out["datetime"] == "":
                out["datetime"] = None
        return out
