import base64
import io
import os
from functools import lru_cache
from pathlib import Path

import cv2
import numpy as np
from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from PIL import Image
from ultralytics import YOLO

app = FastAPI(title="PPE Vision Inference API", version="1.0.0")

MODEL_PATH = Path(os.getenv("YOLO_MODEL_PATH", Path(__file__).parent / "models" / "best.pt"))
ALLOWED_CLASSES = {"helm", "masker", "people"}
CLASS_ALIASES = {
    "hardhat": "helm",
    "helmet": "helm",
    "mask": "masker",
    "person": "people",
}


@lru_cache(maxsize=1)
def get_model() -> YOLO:
    if not MODEL_PATH.is_file():
        raise FileNotFoundError(f"Model tidak ditemukan: {MODEL_PATH.resolve()}")
    return YOLO(str(MODEL_PATH))


@app.get("/health")
def health() -> dict:
    if not MODEL_PATH.is_file():
        return {
            "status": "waiting_for_model",
            "model_path": str(MODEL_PATH),
            "classes": sorted(ALLOWED_CLASSES),
        }

    try:
        model = get_model()
        source_classes = [str(name) for name in model.names.values()]
    except Exception as exc:
        raise HTTPException(status_code=503, detail=f"Model gagal dimuat: {exc}") from exc

    return {
        "status": "ready",
        "model_path": str(MODEL_PATH),
        "source_classes": source_classes,
        "classes": [CLASS_ALIASES.get(name.lower(), name.lower()) for name in source_classes],
    }


@app.post("/predict")
async def predict(
    file: UploadFile = File(...),
    confidence: float = Form(0.20),
) -> dict:
    if not 0.05 <= confidence <= 0.95:
        raise HTTPException(status_code=422, detail="Confidence harus 0.05–0.95")
    if file.content_type not in {"image/jpeg", "image/png", "image/webp"}:
        raise HTTPException(status_code=415, detail="Gunakan gambar JPG, PNG, atau WEBP")

    try:
        model = get_model()
    except FileNotFoundError as exc:
        raise HTTPException(status_code=503, detail=str(exc)) from exc

    try:
        image = Image.open(io.BytesIO(await file.read())).convert("RGB")
    except Exception as exc:
        raise HTTPException(status_code=422, detail="File gambar tidak valid") from exc

    bgr_image = cv2.cvtColor(np.asarray(image), cv2.COLOR_RGB2BGR)
    result = model.predict(source=bgr_image, conf=confidence, verbose=False)[0]
    detections = []
    for box in result.boxes:
        class_id = int(box.cls.item())
        raw_label = str(result.names[class_id]).lower()
        label = CLASS_ALIASES.get(raw_label, raw_label)
        detections.append({
            "class": label,
            "source_class": raw_label,
            "confidence": round(float(box.conf.item()), 4),
            "bbox": [round(float(value), 2) for value in box.xyxy[0].tolist()],
        })

    success, encoded = cv2.imencode(".jpg", result.plot())
    if not success:
        raise HTTPException(status_code=500, detail="Gagal membuat gambar hasil")

    return {
        "detections": detections,
        "inference_ms": round(float(result.speed.get("inference", 0)), 2),
        "annotated_image": base64.b64encode(encoded.tobytes()).decode("ascii"),
    }
