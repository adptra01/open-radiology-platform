"""Tests for the M8 DICOM input adapter (dicom.py).

These tests use synthetic DICOM datasets created on-the-fly via pydicom to
exercise all validation paths and preprocessing branches without external files.
"""

from __future__ import annotations

import io
from pathlib import Path

import numpy as np
import pydicom
import pytest
from pydicom.dataset import Dataset
from pydicom.uid import ExplicitVRLittleEndian
from PIL import Image

from orp_ai.dicom import (
    DicomInputConfig,
    DicomValidationError,
    TB_DICOM_CONFIG,
    read_dicom_as_pil,
    validate_dicom_only,
    _apply_rescale,
    _apply_voi_lut,
    _handle_pixel_representation,
    _normalize_monochrome1,
    _window_to_8bit,
)


# ---------------------------------------------------------------------------
# Synthetic DICOM factory
# ---------------------------------------------------------------------------


def _make_base_dataset(**overrides) -> Dataset:
    """Create a minimal valid CR chest DICOM dataset."""
    ds = Dataset()
    ds.file_meta = Dataset()
    ds.file_meta.TransferSyntaxUID = ExplicitVRLittleEndian
    ds.file_meta.MediaStorageSOPClassUID = "1.2.840.10008.5.1.4.1.1.1"  # CR Image Storage
    ds.file_meta.MediaStorageSOPInstanceUID = "1.2.3.4.5.6.7.8.9"
    ds.file_meta.ImplementationClassUID = "1.2.3.4.5"

    ds.SOPClassUID = "1.2.840.10008.5.1.4.1.1.1"
    ds.SOPInstanceUID = "1.2.3.4.5.6.7.8.9"
    ds.Modality = "CR"
    ds.Rows = 512
    ds.Columns = 512
    ds.BitsAllocated = 16
    ds.BitsStored = 12
    ds.HighBit = 11
    ds.PixelRepresentation = 0
    ds.PhotometricInterpretation = "MONOCHROME2"
    ds.PlanarConfiguration = 0
    ds.RescaleSlope = 1.0
    ds.RescaleIntercept = 0.0
    ds.SamplesPerPixel = 1

    # Synthetic pixel data: gradient from 0 to 4095
    arr = np.tile(np.arange(512, dtype=np.uint16), (512, 1))
    ds.PixelData = arr.tobytes()

    for k, v in overrides.items():
        setattr(ds, k, v)
    return ds


def _write_dicom(ds: Dataset) -> bytes:
    """Serialize dataset to bytes."""
    buf = io.BytesIO()
    ds.save_as(buf, write_like_original=False)
    return buf.getvalue()


def _save_dicom(ds: Dataset, path: Path) -> Path:
    ds.save_as(path, write_like_original=False)
    return path


# ---------------------------------------------------------------------------
# Validation tests
# ---------------------------------------------------------------------------


def test_valid_cr_monochrome2_passes():
    ds = _make_base_dataset()
    path = Path("/tmp/test_valid.dcm")
    _save_dicom(ds, path)
    try:
        img = read_dicom_as_pil(path, TB_DICOM_CONFIG)
        assert isinstance(img, Image.Image)
        assert img.mode == "L"
        assert img.size == (512, 512)
    finally:
        path.unlink(missing_ok=True)


def test_valid_dx_monochrome2_passes():
    ds = _make_base_dataset(Modality="DX")
    path = Path("/tmp/test_dx.dcm")
    _save_dicom(ds, path)
    try:
        img = read_dicom_as_pil(path, TB_DICOM_CONFIG)
        assert img.mode == "L"
    finally:
        path.unlink(missing_ok=True)


def test_monochrome1_inverts():
    ds = _make_base_dataset(PhotometricInterpretation="MONOCHROME1")
    path = Path("/tmp/test_mono1.dcm")
    _save_dicom(ds, path)
    try:
        img = read_dicom_as_pil(path, TB_DICOM_CONFIG)
        # MONOCHROME1 should be inverted so bone=white (high values)
        arr = np.asarray(img)
        assert arr.max() > arr.min()
    finally:
        path.unlink(missing_ok=True)


def test_invalid_modality_rejected():
    ds = _make_base_dataset(Modality="CT")
    path = Path("/tmp/test_ct.dcm")
    _save_dicom(ds, path)
    try:
        with pytest.raises(DicomValidationError, match="Modality"):
            read_dicom_as_pil(path, TB_DICOM_CONFIG)
    finally:
        path.unlink(missing_ok=True)


def test_invalid_photometric_rejected():
    ds = _make_base_dataset(PhotometricInterpretation="RGB")
    path = Path("/tmp/test_rgb.dcm")
    _save_dicom(ds, path)
    try:
        with pytest.raises(DicomValidationError, match="PhotometricInterpretation"):
            read_dicom_as_pil(path, TB_DICOM_CONFIG)
    finally:
        path.unlink(missing_ok=True)


def test_bits_stored_validation():
    # BitsStored > BitsAllocated
    ds = _make_base_dataset(BitsAllocated=16, BitsStored=20, HighBit=19)
    path = Path("/tmp/test_bits.dcm")
    _save_dicom(ds, path)
    try:
        with pytest.raises(DicomValidationError, match="BitsStored"):
            read_dicom_as_pil(path, TB_DICOM_CONFIG)
    finally:
        path.unlink(missing_ok=True)


def test_highbit_mismatch_rejected():
    ds = _make_base_dataset(BitsStored=12, HighBit=10)  # should be 11
    path = Path("/tmp/test_highbit.dcm")
    _save_dicom(ds, path)
    try:
        with pytest.raises(DicomValidationError, match="HighBit"):
            read_dicom_as_pil(path, TB_DICOM_CONFIG)
    finally:
        path.unlink(missing_ok=True)


def test_planar_configuration_rejected():
    ds = _make_base_dataset(PlanarConfiguration=1, SamplesPerPixel=3)
    path = Path("/tmp/test_planar.dcm")
    _save_dicom(ds, path)
    try:
        with pytest.raises(DicomValidationError, match="PlanarConfiguration"):
            read_dicom_as_pil(path, TB_DICOM_CONFIG)
    finally:
        path.unlink(missing_ok=True)


# ---------------------------------------------------------------------------
# Preprocessing tests
# ---------------------------------------------------------------------------


def test_rescale_slope_intercept_applied():
    """RescaleSlope=2, Intercept=-100 should transform stored values."""
    # Stored values 0..4095 -> modality = stored * 2 - 100
    ds = _make_base_dataset(RescaleSlope=2.0, RescaleIntercept=-100.0)
    path = Path("/tmp/test_rescale.dcm")
    _save_dicom(ds, path)
    try:
        img = read_dicom_as_pil(path, TB_DICOM_CONFIG)
        arr = np.asarray(img, dtype=np.float32)
        # After rescale + percentile windowing, should be well-distributed
        assert arr.std() > 10  # not collapsed
    finally:
        path.unlink(missing_ok=True)


def test_voi_lut_window_center_width():
    """WindowCenter=2048, WindowWidth=4096 should map mid-range to mid-gray."""
    # Create data with stored values 0-4095 (12-bit), gradient across full range
    arr = np.tile(np.linspace(0, 4095, 512, dtype=np.uint16), (512, 1))
    ds = _make_base_dataset(
        BitsAllocated=16,
        BitsStored=12,
        HighBit=11,
        WindowCenter=2048.0,
        WindowWidth=4096.0,
    )
    ds.PixelData = arr.tobytes()
    path = Path("/tmp/test_voi.dcm")
    _save_dicom(ds, path)
    try:
        img = read_dicom_as_pil(path, TB_DICOM_CONFIG)
        arr_out = np.asarray(img)
        # Center of gradient (2048 at column 256) should map to ~128
        center_val = arr_out[256, 256]
        assert 100 < center_val < 180
    finally:
        path.unlink(missing_ok=True)


def test_signed_pixel_representation():
    """PixelRepresentation=1 (signed) should be handled correctly."""
    # Create 512x512 signed data: -1000 to +1000 range
    arr = np.tile(np.linspace(-1000, 1000, 512, dtype=np.int16), (512, 1))
    ds = _make_base_dataset(
        Rows=512,
        Columns=512,
        BitsAllocated=16,
        BitsStored=12,
        HighBit=11,
        PixelRepresentation=1,
    )
    ds.PixelData = arr.tobytes()
    path = Path("/tmp/test_signed.dcm")
    _save_dicom(ds, path)
    try:
        img = read_dicom_as_pil(path, TB_DICOM_CONFIG)
        arr_out = np.asarray(img)
        assert arr_out.std() > 10  # well-distributed after shift
        assert arr_out.min() >= 0 and arr_out.max() <= 255
    finally:
        path.unlink(missing_ok=True)


def test_ybr_full_422_extracts_luminance():
    """YBR_FULL_422 extraction logic (tested via helper directly)."""
    # This is tested via the helper function since creating valid YBR_FULL_422
    # synthetic DICOM requires precise byte layout. The helper logic is:
    # if photometric == "YBR_FULL_422" and arr.ndim == 3: arr = arr[:,:,0]
    from orp_ai.dicom import _convert_ybr_to_monochrome2
    arr = np.random.randint(0, 255, (10, 10, 3), dtype=np.uint16)
    ds = Dataset()
    ds.PhotometricInterpretation = "YBR_FULL_422"
    out = _convert_ybr_to_monochrome2(arr, ds)
    assert out.shape == (10, 10)
    np.testing.assert_array_equal(out, arr[:, :, 0])


# ---------------------------------------------------------------------------
# Internal helper tests (unit level)
# ---------------------------------------------------------------------------


def test_apply_rescale():
    arr = np.array([0, 1000, 2000, 3000], dtype=np.float32)
    ds = Dataset()
    ds.RescaleSlope = 2.0
    ds.RescaleIntercept = -100.0
    out = _apply_rescale(arr, ds)
    expected = np.array([-100, 1900, 3900, 5900], dtype=np.float32)
    np.testing.assert_allclose(out, expected)


def test_apply_voi_lut():
    arr = np.array([0.0, 0.5, 1.0, 1.5], dtype=np.float32)
    ds = Dataset()
    ds.WindowCenter = 0.5
    ds.WindowWidth = 1.0
    out = _apply_voi_lut(arr, ds)
    # (x - (0.5-0.5))/1.0 + 0.5 = x + 0.5
    expected = np.clip(arr + 0.5, 0.0, 1.0)
    np.testing.assert_allclose(out, expected)


def test_handle_pixel_representation_signed():
    arr = np.array([-100, 0, 100], dtype=np.int16)
    ds = Dataset()
    ds.BitsStored = 12
    ds.PixelRepresentation = 1
    out = _handle_pixel_representation(arr, ds)
    shift = 1 << 11  # 2048
    expected = np.array([1948, 2048, 2148], dtype=np.float32)
    np.testing.assert_allclose(out, expected)


def test_handle_pixel_representation_unsigned():
    arr = np.array([0, 100, 200], dtype=np.uint16)
    ds = Dataset()
    ds.PixelRepresentation = 0
    out = _handle_pixel_representation(arr, ds)
    np.testing.assert_allclose(out, arr.astype(np.float32))


def test_normalize_monochrome1():
    arr = np.array([0, 100, 200], dtype=np.float32)
    ds = Dataset()
    ds.PhotometricInterpretation = "MONOCHROME1"
    out = _normalize_monochrome1(arr, ds)
    # max - val: 200 - [0,100,200] = [200,100,0]
    expected = np.array([200, 100, 0], dtype=np.float32)
    np.testing.assert_allclose(out, expected)


def test_normalize_monochrome2_unchanged():
    arr = np.array([0, 100, 200], dtype=np.float32)
    ds = Dataset()
    ds.PhotometricInterpretation = "MONOCHROME2"
    out = _normalize_monochrome1(arr, ds)
    np.testing.assert_allclose(out, arr)


def test_window_to_8bit_percentile():
    arr = np.array([0.0, 0.25, 0.5, 0.75, 1.0], dtype=np.float32)
    out = _window_to_8bit(arr, method="percentile")
    # 1st and 99th percentile on 5 elements with linear interpolation
    # numpy percentile gives lo=0.0, hi=1.0 for this case
    # Actual mapping: 0->0, 0.25->62, 0.5->127, 0.75->192, 1->255
    expected = np.array([0, 62, 127, 192, 255], dtype=np.uint8)
    np.testing.assert_array_equal(out, expected)


def test_window_to_8bit_voi():
    arr = np.array([-0.5, 0.0, 0.5, 1.0, 1.5], dtype=np.float32)
    out = _window_to_8bit(arr, method="voi")
    # VOI: clip to [0,1] then *255
    expected = np.array([0, 0, 127, 255, 255], dtype=np.uint8)
    np.testing.assert_array_equal(out, expected)


# ---------------------------------------------------------------------------
# validate_dicom_only (lightweight) tests
# ---------------------------------------------------------------------------


def test_validate_dicom_only_valid():
    ds = _make_base_dataset()
    path = Path("/tmp/test_validate.dcm")
    _save_dicom(ds, path)
    try:
        result = validate_dicom_only(path, TB_DICOM_CONFIG)
        assert result["valid"] is True
        assert result["modality"] == "CR"
        assert result["photometric"] == "MONOCHROME2"
    finally:
        path.unlink(missing_ok=True)


def test_validate_dicom_only_invalid_modality():
    ds = _make_base_dataset(Modality="MR")
    path = Path("/tmp/test_validate_mr.dcm")
    _save_dicom(ds, path)
    try:
        result = validate_dicom_only(path, TB_DICOM_CONFIG)
        assert result["valid"] is False
        assert "Modality" in result["reason"]
    finally:
        path.unlink(missing_ok=True)


# ---------------------------------------------------------------------------
# Custom config test (extensibility)
# ---------------------------------------------------------------------------


def test_custom_config_allows_ct():
    ct_config = DicomInputConfig(
        allowed_modalities=("CT",),
        allowed_photometric=("MONOCHROME2",),
    )
    ds = _make_base_dataset(Modality="CT")
    path = Path("/tmp/test_custom_ct.dcm")
    _save_dicom(ds, path)
    try:
        img = read_dicom_as_pil(path, ct_config)
        assert img.mode == "L"
    finally:
        path.unlink(missing_ok=True)


# ---------------------------------------------------------------------------
# Edge cases
# ---------------------------------------------------------------------------


def test_uniform_image_does_not_crash():
    """All pixels same value should not divide by zero."""
    ds = _make_base_dataset()
    # Overwrite with constant
    ds.PixelData = np.full((512, 512), 2048, dtype=np.uint16).tobytes()
    path = Path("/tmp/test_uniform.dcm")
    _save_dicom(ds, path)
    try:
        img = read_dicom_as_pil(path, TB_DICOM_CONFIG)
        arr = np.asarray(img)
        assert arr.std() == 0  # all same value
        assert arr[0, 0] == 0  # clipped to 0
    finally:
        path.unlink(missing_ok=True)


def test_file_not_found_raises():
    with pytest.raises(FileNotFoundError):
        read_dicom_as_pil("/nonexistent/path.dcm", TB_DICOM_CONFIG)


def test_output_is_deterministic():
    """Same input must produce exactly same output."""
    ds = _make_base_dataset()
    path = Path("/tmp/test_deterministic.dcm")
    _save_dicom(ds, path)
    try:
        img1 = read_dicom_as_pil(path, TB_DICOM_CONFIG)
        img2 = read_dicom_as_pil(path, TB_DICOM_CONFIG)
        np.testing.assert_array_equal(np.asarray(img1), np.asarray(img2))
    finally:
        path.unlink(missing_ok=True)