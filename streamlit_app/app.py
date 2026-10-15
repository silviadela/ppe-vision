from __future__ import annotations

import hashlib
import json
import os
import shutil
import subprocess
import tempfile
import threading
import time
import urllib.request
from collections import Counter
from pathlib import Path
from urllib.parse import urlparse

import cv2
import numpy as np
import pandas as pd
import streamlit as st
from PIL import Image
from ultralytics import YOLO


APP_DIR = Path(__file__).resolve().parent
PROJECT_ROOT = APP_DIR.parent
DEFAULT_MODEL_PATH = PROJECT_ROOT / "inference_service" / "models" / "best.pt"
MODEL_INFO_PATH = PROJECT_ROOT / "inference_service" / "models" / "model-info.json"
CLASS_ALIASES = {
    "hardhat": "helm",
    "helmet": "helm",
    "mask": "masker",
    "person": "people",
}
CLASS_COLORS = {
    "helm": (46, 204, 113),
    "masker": (235, 190, 52),
    "people": (245, 245, 245),
}
MODEL_LOCK = threading.Lock()


def setting(name: str, default: str = "") -> str:
    value = os.getenv(name)
    if value:
        return value

    try:
        return str(st.secrets.get(name, default))
    except Exception:
        return default


def bundled_model_checksum() -> str:
    try:
        model_info = json.loads(MODEL_INFO_PATH.read_text(encoding="utf-8"))
        return str(model_info.get("sha256", ""))
    except (OSError, ValueError, TypeError):
        return ""


def verify_checksum(path: Path, expected: str) -> None:
    if not expected:
        return

    digest = hashlib.sha256(path.read_bytes()).hexdigest()
    if digest.lower() != expected.lower():
        path.unlink(missing_ok=True)
        raise RuntimeError("Checksum model tidak sesuai. Periksa YOLO_MODEL_URL atau YOLO_MODEL_SHA256.")


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


def predict_frame(model: YOLO, frame: np.ndarray, confidence: float, image_size: int):
    with MODEL_LOCK:
        return model.predict(
            source=frame,
            conf=confidence,
            imgsz=image_size,
            verbose=False,
        )[0]


def result_rows(result, frame_number: int | None = None, timestamp: float | None = None) -> list[dict]:
    rows: list[dict] = []
    for box in result.boxes:
        class_id = int(box.cls.item())
        source_class = str(result.names[class_id])
        coordinates = [round(float(value), 1) for value in box.xyxy[0].tolist()]
        row = {
            "Kelas": normalized_label(source_class).title(),
            "Confidence": round(float(box.conf.item()), 4),
            "Bounding box": coordinates,
        }
        if frame_number is not None:
            row = {
                "Frame": frame_number,
                "Waktu (detik)": round(timestamp or 0.0, 2),
                **row,
            }
        rows.append(row)
    return rows


def rectangles_intersect(first: tuple[int, int, int, int], second: tuple[int, int, int, int]) -> bool:
    return not (
        first[2] <= second[0]
        or first[0] >= second[2]
        or first[3] <= second[1]
        or first[1] >= second[3]
    )


def draw_result(frame: np.ndarray, result) -> np.ndarray:
    annotated = frame.copy()
    placed_labels: list[tuple[int, int, int, int]] = []
    detections = []

    for box in result.boxes:
        class_id = int(box.cls.item())
        raw_label = str(result.names[class_id])
        label = normalized_label(raw_label)
        confidence = float(box.conf.item())
        x1, y1, x2, y2 = [int(round(value)) for value in box.xyxy[0].tolist()]
        detections.append((x1, y1, x2, y2, label, confidence))

    # Kotak besar digambar lebih dahulu; label kecil kemudian mencari ruang kosong.
    detections.sort(key=lambda item: (item[2] - item[0]) * (item[3] - item[1]), reverse=True)
    height, width = annotated.shape[:2]

    for x1, y1, x2, y2, label, confidence in detections:
        color = CLASS_COLORS.get(label, (80, 200, 255))
        cv2.rectangle(annotated, (x1, y1), (x2, y2), color, 2)

        caption = f"{label.title()} {confidence:.2f}"
        (text_width, text_height), baseline = cv2.getTextSize(
            caption, cv2.FONT_HERSHEY_SIMPLEX, 0.55, 2
        )
        label_width = text_width + 10
        label_height = text_height + baseline + 8
        candidate_x = min(max(0, x1), max(0, width - label_width))
        candidate_ys = [
            y1 - label_height,
            y1,
            y2,
            y1 + label_height,
            y2 - label_height,
        ]

        label_rect = None
        for candidate_y in candidate_ys:
            candidate_y = min(max(0, candidate_y), max(0, height - label_height))
            proposed = (
                candidate_x,
                candidate_y,
                candidate_x + label_width,
                candidate_y + label_height,
            )
            if not any(rectangles_intersect(proposed, placed) for placed in placed_labels):
                label_rect = proposed
                break

        if label_rect is None:
            candidate_y = min(max(0, y1), max(0, height - label_height))
            label_rect = (
                candidate_x,
                candidate_y,
                candidate_x + label_width,
                candidate_y + label_height,
            )

        placed_labels.append(label_rect)
        lx1, ly1, lx2, ly2 = label_rect
        cv2.rectangle(annotated, (lx1, ly1), (lx2, ly2), color, -1)
        text_color = (25, 35, 30) if label != "masker" else (15, 25, 25)
        cv2.putText(
            annotated,
            caption,
            (lx1 + 5, ly2 - baseline - 4),
            cv2.FONT_HERSHEY_SIMPLEX,
            0.55,
            text_color,
            2,
            cv2.LINE_AA,
        )

    return annotated


def render_summary(rows: list[dict], title: str = "Ringkasan kelas") -> None:
    counts = Counter(row["Kelas"] for row in rows)
    st.markdown(f"**{title}**")
    columns = st.columns(3)
    for column, label in zip(columns, ("Helm", "Masker", "People")):
        column.metric(label, counts.get(label, 0))

    if not rows:
        st.warning("Tidak ada objek yang melewati confidence threshold.")
    elif any(counts.get(label, 0) == 0 for label in ("Helm", "Masker", "People")):
        st.caption(
            "Nilai 0 berarti kelas tersebut tidak terdeteksi pada input ini, "
            "bukan berarti kelasnya tidak tersedia pada model."
        )


def dataframe_for_display(rows: list[dict]) -> pd.DataFrame:
    table = pd.DataFrame(rows)
    if not table.empty:
        table["Confidence"] = table["Confidence"].map(lambda value: f"{value * 100:.1f}%")
    return table


def process_uploaded_video(
    uploaded_bytes: bytes,
    suffix: str,
    model: YOLO,
    confidence: float,
    image_size: int,
    frame_stride: int,
    maximum_seconds: int,
    progress_bar,
    status_text,
) -> tuple[bytes, str, list[dict], dict]:
    with tempfile.TemporaryDirectory(prefix="ppe-video-") as temp_dir_name:
        temp_dir = Path(temp_dir_name)
        input_path = temp_dir / f"input{suffix}"
        raw_output_path = temp_dir / "annotated.avi"
        mp4_output_path = temp_dir / "annotated.mp4"
        input_path.write_bytes(uploaded_bytes)

        capture = cv2.VideoCapture(str(input_path))
        if not capture.isOpened():
            raise ValueError("Video tidak dapat dibaca. Gunakan MP4, MOV, AVI, MKV, atau WEBM.")

        fps = float(capture.get(cv2.CAP_PROP_FPS))
        if not np.isfinite(fps) or fps <= 0:
            fps = 25.0
        total_frames = int(capture.get(cv2.CAP_PROP_FRAME_COUNT))
        width = int(capture.get(cv2.CAP_PROP_FRAME_WIDTH))
        height = int(capture.get(cv2.CAP_PROP_FRAME_HEIGHT))
        width = max(2, width - (width % 2))
        height = max(2, height - (height % 2))
        max_source_frames = max(1, int(maximum_seconds * fps))
        source_frames_to_read = min(total_frames, max_source_frames) if total_frames > 0 else max_source_frames
        processed_frames_expected = max(1, (source_frames_to_read + frame_stride - 1) // frame_stride)

        writer = cv2.VideoWriter(
            str(raw_output_path),
            cv2.VideoWriter_fourcc(*"MJPG"),
            max(1.0, fps / frame_stride),
            (width, height),
        )
        if not writer.isOpened():
            capture.release()
            raise RuntimeError("Encoder video tidak tersedia pada server.")

        all_rows: list[dict] = []
        source_frame = 0
        processed_frame = 0
        inference_total_ms = 0.0

        try:
            while source_frame < source_frames_to_read:
                success, frame = capture.read()
                if not success:
                    break

                if source_frame % frame_stride == 0:
                    if frame.shape[1] != width or frame.shape[0] != height:
                        frame = cv2.resize(frame, (width, height))
                    result = predict_frame(model, frame, confidence, image_size)
                    inference_total_ms += float(result.speed.get("inference", 0))
                    rows = result_rows(
                        result,
                        frame_number=source_frame + 1,
                        timestamp=source_frame / fps,
                    )
                    all_rows.extend(rows)
                    writer.write(draw_result(frame, result))
                    processed_frame += 1

                    if processed_frame == 1 or processed_frame % 5 == 0:
                        progress_bar.progress(
                            min(1.0, processed_frame / processed_frames_expected),
                            text=f"Memproses frame {processed_frame} dari sekitar {processed_frames_expected}",
                        )
                        status_text.caption(
                            f"{len(all_rows)} deteksi tercatat · audio tidak disertakan pada hasil."
                        )
                source_frame += 1
        finally:
            capture.release()
            writer.release()

        if processed_frame == 0:
            raise ValueError("Tidak ada frame yang berhasil diproses dari video.")

        progress_bar.progress(1.0, text="Menyelesaikan video hasil deteksi...")
        ffmpeg = shutil.which("ffmpeg")
        output_path = raw_output_path
        output_mime = "video/x-msvideo"
        if ffmpeg:
            completed = subprocess.run(
                [
                    ffmpeg,
                    "-y",
                    "-loglevel",
                    "error",
                    "-i",
                    str(raw_output_path),
                    "-an",
                    "-c:v",
                    "libx264",
                    "-preset",
                    "veryfast",
                    "-crf",
                    "23",
                    "-pix_fmt",
                    "yuv420p",
                    "-movflags",
                    "+faststart",
                    str(mp4_output_path),
                ],
                capture_output=True,
                text=True,
                timeout=180,
                check=False,
            )
            if completed.returncode == 0 and mp4_output_path.is_file():
                output_path = mp4_output_path
                output_mime = "video/mp4"

        metadata = {
            "processed_frames": processed_frame,
            "source_frames": source_frame,
            "duration_seconds": source_frame / fps,
            "average_inference_ms": inference_total_ms / processed_frame,
            "output_mime": output_mime,
        }
        return output_path.read_bytes(), output_path.suffix, all_rows, metadata


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
    div[data-testid="stTabs"] button { font-weight:700; }
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
model_checksum = setting("YOLO_MODEL_SHA256", bundled_model_checksum())

st.markdown('<div class="demo-kicker">Pengujian model</div>', unsafe_allow_html=True)
st.markdown('<h1 class="demo-title">Deteksi PPE dengan YOLO</h1>', unsafe_allow_html=True)
st.write("Pilih gambar, video, atau foto dari kamera untuk mendeteksi perlengkapan keselamatan.")
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

image_tab, video_tab, camera_tab = st.tabs(["📷 Gambar", "🎬 Video", "📸 Kamera"])

with image_tab:
    control_col, preview_col = st.columns([0.42, 0.58], gap="large")
    with control_col:
        st.subheader("1. Pilih gambar")
        uploaded_image = st.file_uploader(
            "Gambar PPE",
            type=["jpg", "jpeg", "png", "webp"],
            key="image-upload",
            help="Gunakan gambar pekerja dengan pencahayaan normal dan objek yang cukup jelas.",
        )
        image_confidence = st.slider(
            "Confidence threshold", 0.05, 0.95, 0.20, 0.05, key="image-confidence"
        )
        image_size = st.select_slider(
            "Resolusi inferensi", options=[640, 960, 1280], value=640, key="image-size"
        )
        run_image = st.button(
            "Jalankan deteksi gambar",
            type="primary",
            width="stretch",
            disabled=uploaded_image is None,
        )
        st.caption("Resolusi 640 sesuai dengan ukuran training checkpoint saat ini.")

    with preview_col:
        st.subheader("2. Preview gambar")
        if uploaded_image is None:
            st.info("Gambar yang dipilih akan tampil di sini.")
            image = None
        else:
            try:
                image = Image.open(uploaded_image).convert("RGB")
                st.image(image, caption=uploaded_image.name, width="stretch")
            except Exception:
                image = None
                st.error("File tidak dapat dibaca sebagai gambar.")

    if run_image and image is not None:
        started_at = time.perf_counter()
        with st.spinner("Model sedang melakukan inferensi..."):
            bgr_image = np.asarray(image)[:, :, ::-1]
            result = predict_frame(model, bgr_image, image_confidence, image_size)
        elapsed_ms = (time.perf_counter() - started_at) * 1000
        rows = result_rows(result)

        st.markdown("---")
        st.subheader("3. Hasil deteksi gambar")
        metric_a, metric_b, metric_c = st.columns(3)
        metric_a.metric("Objek terdeteksi", len(rows))
        metric_b.metric("Waktu inferensi model", f"{float(result.speed.get('inference', 0)):.1f} ms")
        metric_c.metric("Total proses", f"{elapsed_ms:.1f} ms")

        result_col, detail_col = st.columns([0.62, 0.38], gap="large")
        with result_col:
            annotated = draw_result(bgr_image, result)
            st.image(annotated[:, :, ::-1], caption="Hasil bounding box", width="stretch")
        with detail_col:
            render_summary(rows)
            if rows:
                st.dataframe(dataframe_for_display(rows), hide_index=True, width="stretch")

with video_tab:
    st.subheader("1. Unggah video")
    video_control_col, video_info_col = st.columns([0.55, 0.45], gap="large")
    with video_control_col:
        uploaded_video = st.file_uploader(
            "Video PPE",
            type=["mp4", "mov", "avi", "mkv", "webm"],
            key="video-upload",
            help="Video maksimum mengikuti batas upload server. Video hasil tidak menyertakan audio.",
        )
        if uploaded_video is not None:
            st.video(uploaded_video)
    with video_info_col:
        video_confidence = st.slider(
            "Confidence threshold", 0.05, 0.95, 0.20, 0.05, key="video-confidence"
        )
        video_image_size = st.select_slider(
            "Resolusi inferensi", options=[640, 960], value=640, key="video-size"
        )
        frame_stride = st.select_slider(
            "Proses setiap N frame",
            options=[1, 2, 3, 5],
            value=2,
            key="video-stride",
            help="Nilai lebih besar mempercepat proses dengan melewati sebagian frame.",
        )
        maximum_seconds = st.select_slider(
            "Durasi maksimum yang diproses",
            options=[15, 30, 60],
            value=30,
            key="video-duration",
        )
        run_video = st.button(
            "Jalankan deteksi video",
            type="primary",
            width="stretch",
            disabled=uploaded_video is None,
        )
        st.caption("Pada hosting gratis, gunakan video pendek dan proses setiap 2–5 frame.")

    if run_video and uploaded_video is not None:
        progress_bar = st.progress(0.0, text="Menyiapkan video...")
        status_text = st.empty()
        try:
            started_at = time.perf_counter()
            video_bytes, output_suffix, video_rows, metadata = process_uploaded_video(
                uploaded_video.getvalue(),
                Path(uploaded_video.name).suffix.lower() or ".mp4",
                model,
                video_confidence,
                video_image_size,
                frame_stride,
                maximum_seconds,
                progress_bar,
                status_text,
            )
            elapsed_seconds = time.perf_counter() - started_at
        except Exception as exc:
            progress_bar.empty()
            status_text.empty()
            st.error(f"Video gagal diproses: {exc}")
        else:
            progress_bar.empty()
            status_text.empty()
            st.markdown("---")
            st.subheader("2. Hasil deteksi video")
            metric_a, metric_b, metric_c = st.columns(3)
            metric_a.metric("Frame diproses", metadata["processed_frames"])
            metric_b.metric("Deteksi pada frame", len(video_rows))
            metric_c.metric("Total proses", f"{elapsed_seconds:.1f} detik")

            result_col, detail_col = st.columns([0.62, 0.38], gap="large")
            with result_col:
                if metadata["output_mime"] == "video/mp4":
                    st.video(video_bytes)
                else:
                    st.info("Preview tidak tersedia, tetapi video hasil dapat diunduh.")
                st.download_button(
                    "Unduh video hasil",
                    data=video_bytes,
                    file_name=f"ppe-detection{output_suffix}",
                    mime=metadata["output_mime"],
                    width="stretch",
                )
            with detail_col:
                render_summary(video_rows, "Jumlah deteksi seluruh frame")
                st.caption(
                    "Jumlah di atas adalah akumulasi per frame, bukan jumlah orang unik dalam video."
                )
                if video_rows:
                    csv_bytes = pd.DataFrame(video_rows).to_csv(index=False).encode("utf-8")
                    st.download_button(
                        "Unduh detail CSV",
                        data=csv_bytes,
                        file_name="ppe-video-detections.csv",
                        mime="text/csv",
                        width="stretch",
                    )
                    st.dataframe(
                        dataframe_for_display(video_rows[:200]),
                        hide_index=True,
                        width="stretch",
                    )

with camera_tab:
    st.subheader("Ambil foto dari kamera")
    camera_control_col, camera_capture_col = st.columns([0.32, 0.68], gap="large")
    with camera_control_col:
        camera_confidence = st.slider(
            "Confidence threshold", 0.05, 0.95, 0.20, 0.05, key="camera-confidence"
        )
        camera_image_size = st.select_slider(
            "Resolusi inferensi",
            options=[320, 640],
            value=640,
            key="camera-size",
            help="Gunakan 320 jika proses terasa lambat pada CPU.",
        )
        st.info(
            "Izinkan akses kamera, arahkan ke objek PPE, lalu tekan Take Photo satu kali."
        )
        st.caption(
            "Foto langsung diproses oleh model dan tidak disimpan permanen oleh aplikasi."
        )

    with camera_capture_col:
        camera_photo = st.camera_input(
            "Kamera PPE",
            key="ppe-camera-photo",
        )

    if camera_photo is not None:
        try:
            captured_image = Image.open(camera_photo).convert("RGB")
            captured_bgr = np.asarray(captured_image)[:, :, ::-1]
            started_at = time.perf_counter()
            with st.spinner("Model sedang mendeteksi foto kamera..."):
                camera_result = predict_frame(
                    model,
                    captured_bgr,
                    camera_confidence,
                    camera_image_size,
                )
            elapsed_ms = (time.perf_counter() - started_at) * 1000
            camera_rows = result_rows(camera_result)
        except Exception as exc:
            st.error(f"Foto kamera gagal diproses: {exc}")
        else:
            st.markdown("---")
            st.subheader("Hasil deteksi kamera")
            metric_a, metric_b, metric_c = st.columns(3)
            metric_a.metric("Objek terdeteksi", len(camera_rows))
            metric_b.metric(
                "Waktu inferensi model",
                f"{float(camera_result.speed.get('inference', 0)):.1f} ms",
            )
            metric_c.metric("Total proses", f"{elapsed_ms:.1f} ms")

            camera_result_col, camera_detail_col = st.columns([0.62, 0.38], gap="large")
            with camera_result_col:
                annotated = draw_result(captured_bgr, camera_result)
                st.image(
                    annotated[:, :, ::-1],
                    caption="Hasil deteksi dari kamera",
                    width="stretch",
                )
            with camera_detail_col:
                render_summary(camera_rows)
                if camera_rows:
                    st.dataframe(
                        dataframe_for_display(camera_rows),
                        hide_index=True,
                        width="stretch",
                    )

st.markdown("---")
st.caption("PPE Vision · Deteksi Helm, Masker, dan People")
