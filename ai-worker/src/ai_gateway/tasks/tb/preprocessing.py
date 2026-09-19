"""TB model-input transform — LOCKED to the training pipeline.

Must mirror the fine-tune notebook exactly (cell 5)::

    Grayscale(3) -> Resize((224,224)) -> ToTensor -> Normalize(ImageNet stats)

NO CenterCrop (training has none), NO torchxrayvision preprocessing (that
belongs to the 18-pathology model). Documented in
``models/tb-densenet121/1.0/preprocessing.json``. Changing this invalidates
the model — treat as a new model version.
"""

from __future__ import annotations

from PIL import Image
from torchvision import transforms

TB_INPUT_SIZE: tuple[int, int] = (224, 224)
TB_NORMALIZE_MEAN: tuple[float, float, float] = (0.485, 0.456, 0.406)
TB_NORMALIZE_STD: tuple[float, float, float] = (0.229, 0.224, 0.225)

TB_TRANSFORM = transforms.Compose(
    [
        transforms.Grayscale(num_output_channels=3),
        transforms.Resize(TB_INPUT_SIZE),
        transforms.ToTensor(),
        transforms.Normalize(mean=list(TB_NORMALIZE_MEAN), std=list(TB_NORMALIZE_STD)),
    ]
)


def pil_to_tensor(pil_img: Image.Image):
    """Apply the locked TB transform (8-bit L PIL -> normalized 4D tensor)."""
    return TB_TRANSFORM(pil_img).unsqueeze(0)
