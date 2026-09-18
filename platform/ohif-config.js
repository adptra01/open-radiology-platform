/**
 * OHIF Viewer Configuration — ORP RIS
 *
 * Point ke Orthanc DICOMweb (QIDO-RS / WADO-RS / STOW-RS).
 * Akses browser: http://localhost:3000  →  Orthanc: http://localhost:8042/dicom-web
 *
 * CATATAN VERSI: image `ohif/viewer` adalah lini OHIF **v2** (v3 memakai image
 * `ohif/app`). Schema config v2 memakai `servers.dicomWeb` sebagai ARRAY
 * (bukan `servers.<nama>` / `dataSources` ala v3). Bentuk yang salah membuat
 * viewer tampil tanpa data source (study list kosong).
 *
 * CATATAN URL: root DICOMweb diarahkan ke `/pacs/dicom-web` — yaitu proxy
 * nginx di dalam container OHIF (platform/ohif-nginx.conf) yang meneruskan ke
 * `orthanc:8042`. Ini membuat request viewer SAME-ORIGIN sehingga tidak
 * memerlukan CORS (Orthanc memang tidak mendukung CORS).
 *
 * Orthanc dev berjalan tanpa auth (lihat platform/orthanc.json). Bila auth
 * diaktifkan, tambahkan `requestOptions.headers.Authorization` di bawah.
 */
window.config = {
  routerBasename: '/',
  showStudyList: true,
  servers: {
    dicomWeb: [
      {
        name: 'ORP Orthanc',
        wadoUriRoot: 'http://localhost:3000/pacs/dicom-web',
        qidoRoot: 'http://localhost:3000/pacs/dicom-web',
        wadoRoot: 'http://localhost:3000/pacs/dicom-web',
        qidoSupportsIncludeField: false,
        imageRendering: 'wadors',
        thumbnailRendering: 'wadors',
        enableStudyLazyLoad: true,
        requestOptions: {},
      },
    ],
  },
  enableStudyMgmt: false,
  disableStudyRecapture: false,
  maxN_STUDIES: 0,
  whiteLabeling: {},
  extensions: [],
  modes: [],
  defaultViewport: {
    viewportType: '2d',
    background: [0, 0, 0],
  },
};
