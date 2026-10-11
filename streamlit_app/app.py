from __future__ import annotations

import hashlib
import os
import shutil
import time
import urllib.request
from collections import Counter
from pathlib import Path
from urllib.parse import urlparse

import numpy as np
import pandas as pd
import streamlit as st
from PIL import Image
from ultralytics import YOLO


APP_DIR = Path(__file__).resolve().parent
PROJECT_ROOT = APP_DIR.parent
DEFAULT_MODEL_PATH = PROJECT_ROOT / "inference_service" / "models" / "best.pt"
CLASS_ALIASES = {
    "hardhat": "helm",
    "helmet": "helm",
    "mask": "masker",
    "person": "people",
}


def setting(name: str, default: str = "") -> str:
    value = os.getenv(name)
    if value:
        return value

    try:
        return str(st.secrets.get(name, default))
    except Exception:
        return default


def verify_checksum(path: Path, expected: str) -> None:
    if not expected:
        return

    digest = hashlib.sha256(path.read_bytes()).hexdigest()
    if digest.lower() != expected.lower():
        path.unlink(missing_ok=True)
        raise RuntimeError("Checksum model tidak sesuai. Periksa kembali YOLO_MODEL_URL atau YOLO_MODEL_SHA256.")


@st.cache_resource(show_spinner=False)
def resolve_model_path(local_path: str, model_url: str, expected_checksum: str) -> Path:
    path = Path(local_path)
    if path.is_file():
        verify_checksum(path, expected_checksum)
        return path

    if not model_url:
        raise FileNotFoundError(
            "best.pt tidak ditemukan. Sertakan model di repository atau isi secret YOLO_MODEL_URL."
        )

    parsed = urlparse(model_url)
    if parsed.scheme != "https":
        raise ValueError("YOLO_MODEL_URL wajib menggunakan HTTPS.")

    cache_dir = Path("/tmp/ppe-vision")
    cache_dir.mkdir(parents=True, exist_ok=True)
    cached_model = cache_dir / "best.pt"
    temporary_model = cache_dir / "best.pt.download"

    if not cached_model.is_file():
        with urllib.request.urlopen(model_url, timeout=180) as response, temporary_model.open("wb") as output:
            shutil.copyfileobj(response, output)
        temporary_model.replace(cached_model)

    verify_checksum(cached_model, expected_checksum)
    return cached_model


@st.cache_resource(show_spinner=False)
def load_model(model_path: str) -> YOLO:
    return YOLO(model_path)


def normalized_label(raw_label: str) -> str:
    lowered = raw_label.lower()
    return CLASS_ALIASES.get(lowered, lowered)


st.set_page_config(
    page_title="PPE Vision · Deteksi PPE",
    page_icon="🦺",
    layout="wide",
    initial_sidebar_state="collapsed",
)

is_embedded = bool(getattr(st.context, "is_embedded", False))

st.markdown(
    """
    <style>
    :root { --green:#1f6b4f; --deep:#183e31; --mint:#dff4e8; --paper:#f6f8f5; }
    .stApp { background:var(--paper); }
    .demo-kicker { color:var(--green); font-size:.72rem; font-weight:800; letter-spacing:.14em; text-transform:uppercase; }
    .demo-title { margin:.25rem 0 .35rem; font-size:2rem; line-height:1.15; }
    .class-list { display:flex; gap:.45rem; flex-wrap:wrap; margin:.6rem 0 1rem; }
    .class-list span { padding:.3rem .65rem; border-radius:999px; background:var(--mint); color:var(--green); font-size:.75rem; font-weight:700; }
    div[data-testid="stMetric"] { padding:1rem; border:1px solid #dfe7e2; border-radius:12px; background:white; }
    </style>
    """,
    unsafe_allow_html=True,
)

if is_embedded:
    st.markdown(
        """
        <style>
        [data-testid="stSidebar"], [data-testid="collapsedControl"],
        [data-testid="stHeader"], [data-testid="stToolbar"] { display:none !important; }
        .block-container { padding-top:1.5rem; }
        </style>
        """,
        unsafe_allow_html=True,
    )

model_path_setting = setting("YOLO_MODEL_PATH", str(DEFAULT_MODEL_PATH))
model_url = setting("YOLO_MODEL_URL")
model_checksum = setting("YOLO_MODEL_SHA256")

st.markdown('<div class="demo-kicker">Pengujian model</div>', unsafe_allow_html=True)
st.markdown('<h1 class="demo-title">Deteksi PPE dengan YOLO</h1>', unsafe_allow_html=True)
st.write("Unggah gambar untuk mendeteksi perlengkapan keselamatan menggunakan checkpoint hasil training.")
st.markdown(
    '<div class="class-list"><span>Helm</span><span>Masker</span><span>People</span></div>',
    unsafe_allow_html=True,
)

try:
    with st.spinner("Menyiapkan model YOLO..."):
        resolved_path = resolve_model_path(model_path_setting, model_url, model_checksum)
        model = load_model(str(resolved_path))
except Exception as exc:
    st.error(f"Model belum dapat dimuat: {exc}")
    st.info("Untuk Streamlit Cloud, sertakan best.pt di repository atau isi YOLO_MODEL_URL pada menu Secrets.")
    st.stop()

source_classes = [str(name) for name in model.names.values()]
display_classes = [normalized_label(name).title() for name in source_classes]
st.success(f"Model siap · kelas: {', '.join(display_classes)}")

control_col, preview_col = st.columns([0.42, 0.58], gap="large")

with control_col:
    st.subheader("1. Pilih pengujian")
    uploaded_file = st.file_uploader(
        "Gambar PPE",
        type=["jpg", "jpeg", "png", "webp"],
        help="Gunakan gambar pekerja dengan pencahayaan normal dan objek yang cukup jelas.",
    )
    confidence = st.slider("Confidence threshold", 0.05, 0.95, 0.20, 0.05)
    image_size = st.select_slider("Resolusi inferensi", options=[640, 960, 1280], value=960)
    run_detection = st.button(
        "Jalankan deteksi",
        type="primary",
        width="stretch",
        disabled=uploaded_file is None,
    )
    st.caption("Threshold 0.20 dan resolusi 960 direkomendasikan untuk checkpoint saat ini.")

with preview_col:
    st.subheader("2. Preview gambar")
    if uploaded_file is None:
        st.info("Gambar yang dipilih akan tampil di sini.")
        image = None
    else:
        try:
            image = Image.open(uploaded_file).convert("RGB")
            st.image(image, caption=uploaded_file.name, width="stretch")
        except Exception:
            image = None
            st.error("File tidak dapat dibaca sebagai gambar.")

if run_detection and image is not None:
    started_at = time.perf_counter()
    with st.spinner("Model sedang melakukan inferensi..."):
        result = model.predict(
            source=np.asarray(image)[:, :, ::-1],
            conf=confidence,
            imgsz=image_size,
            verbose=False,
        )[0]
    elapsed_ms = (time.perf_counter() - started_at) * 1000

    rows = []
    for box in result.boxes:
        class_id = int(box.cls.item())
        source_class = str(result.names[class_id])
        coordinates = [round(float(value), 1) for value in box.xyxy[0].tolist()]
        rows.append(
            {
                "Kelas": normalized_label(source_class).title(),
                "Confidence": round(float(box.conf.item()), 4),
                "Bounding box": coordinates,
            }
        )

    st.markdown("---")
    st.subheader("3. Hasil deteksi")
    metric_a, metric_b, metric_c = st.columns(3)
    metric_a.metric("Objek terdeteksi", len(rows))
    metric_b.metric("Waktu inferensi model", f"{float(result.speed.get('inference', 0)):.1f} ms")
    metric_c.metric("Total proses", f"{elapsed_ms:.1f} ms")

    result_col, detail_col = st.columns([0.62, 0.38], gap="large")
    with result_col:
        annotated = result.plot()
        st.image(annotated[:, :, ::-1], caption="Hasil bounding box", width="stretch")

    with detail_col:
        if not rows:
            st.warning(
                "Tidak ada objek yang melewati threshold. Coba turunkan confidence atau gunakan gambar yang lebih mirip dengan dataset training."
            )
        else:
            counts = Counter(row["Kelas"] for row in rows)
            st.markdown("**Ringkasan kelas**")
            for label, count in sorted(counts.items()):
                st.write(f"{label}: **{count}**")

            table = pd.DataFrame(rows)
            table["Confidence"] = table["Confidence"].map(lambda value: f"{value * 100:.1f}%")
            st.dataframe(table, hide_index=True, width="stretch")

st.markdown("---")
st.caption("PPE Vision · Deteksi Helm, Masker, dan People")
