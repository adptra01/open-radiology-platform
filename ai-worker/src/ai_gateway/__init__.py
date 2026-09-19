"""ORP AI Gateway — generic, model-agnostic inference framework (M10+).

Framework-nya generik, implementasi AI nyata saat ini hanya TB Screening:

    ai_gateway/
    ├── server.py            FastAPI app (mounts api/ routers)
    ├── api/                 health / capabilities / runs routers
    ├── core/                registry + dispatcher + envelope + model registry
    ├── dicom/               reader + validator + shared intensity pipeline
    ├── tasks/tb/            satu-satunya adapter nyata: tb-screening
    └── models/              versioned model packages (metadata + preprocessing)

DICOM tetap source of truth — tidak ada jalur DICOM→JPEG→model.
Model menerima tensor; preprocessing production mengunci transform training.
"""

__version__ = "0.2.0"
