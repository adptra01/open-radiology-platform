/**
 * Label & warna status domain ORP (dipakai Badge/tabel).
 */

export const ORDER_STATUS_LABEL: Record<string, string> = {
    REQUESTED: 'Diminta',
    SCHEDULED: 'Terjadwal',
    ARRIVED: 'Tiba',
    IN_PROGRESS: 'Berjalan',
    ACQUIRED: 'Sudah Diakuisisi',
    COMPLETED: 'Selesai',
    CANCELLED: 'Dibatalkan',
    NO_SHOW: 'Tidak Hadir',
    REJECTED: 'Ditolak',
};

export const ORDER_STATUS_COLOR: Record<string, string> = {
    REQUESTED: 'bg-neutral-500',
    SCHEDULED: 'bg-indigo-500',
    ARRIVED: 'bg-blue-500',
    IN_PROGRESS: 'bg-amber-500',
    ACQUIRED: 'bg-cyan-500',
    COMPLETED: 'bg-emerald-500',
    CANCELLED: 'bg-red-500',
    NO_SHOW: 'bg-red-400',
    REJECTED: 'bg-red-600',
};

export const ORDER_PRIORITY_LABEL: Record<string, string> = {
    STAT: 'STAT',
    URGENT: 'Urgent',
    ROUTINE: 'Rutin',
};

export const ORDER_PRIORITY_COLOR: Record<string, string> = {
    STAT: 'bg-red-600',
    URGENT: 'bg-amber-500',
    ROUTINE: 'bg-slate-500',
};

export const REPORT_STATUS_LABEL: Record<string, string> = {
    DRAFT: 'Draft',
    DICTATED: 'Didikte',
    VERIFIED: 'Terverifikasi',
    FINAL: 'Final',
    CANCELLED: 'Dibatalkan',
};

export const REPORT_STATUS_COLOR: Record<string, string> = {
    DRAFT: 'bg-neutral-500',
    DICTATED: 'bg-amber-500',
    VERIFIED: 'bg-blue-500',
    FINAL: 'bg-emerald-500',
    CANCELLED: 'bg-red-500',
};

export const APPOINTMENT_STATUS_LABEL: Record<string, string> = {
    SCHEDULED: 'Terjadwal',
    CONFIRMED: 'Dikonfirmasi',
    CHECKED_IN: 'Check-in',
    COMPLETED: 'Selesai',
    NO_SHOW: 'Tidak Hadir',
    CANCELLED: 'Dibatalkan',
};

export const APPOINTMENT_STATUS_COLOR: Record<string, string> = {
    SCHEDULED: 'bg-neutral-500',
    CONFIRMED: 'bg-blue-500',
    CHECKED_IN: 'bg-amber-500',
    COMPLETED: 'bg-emerald-500',
    NO_SHOW: 'bg-red-400',
    CANCELLED: 'bg-red-600',
};

export const TRANSMISSION_STATUS_LABEL: Record<string, string> = {
    PENDING: 'Menunggu',
    SENDING: 'Mengirim',
    SENT: 'Terkirim',
    FAILED: 'Gagal',
    CANCELLED: 'Dibatalkan',
};

export const TRANSMISSION_STATUS_COLOR: Record<string, string> = {
    PENDING: 'bg-neutral-500',
    SENDING: 'bg-amber-500',
    SENT: 'bg-emerald-500',
    FAILED: 'bg-red-600',
    CANCELLED: 'bg-slate-500',
};

/** Transisi status appointment yang diizinkan (mirror AppointmentController). */
export const APPOINTMENT_TRANSITIONS: Record<
    string,
    { target: string; label: string }[]
> = {
    SCHEDULED: [
        { target: 'CONFIRMED', label: 'Konfirmasi' },
        { target: 'CHECKED_IN', label: 'Check-in' },
        { target: 'NO_SHOW', label: 'Tidak Hadir' },
        { target: 'CANCELLED', label: 'Batalkan' },
    ],
    CONFIRMED: [
        { target: 'CHECKED_IN', label: 'Check-in' },
        { target: 'NO_SHOW', label: 'Tidak Hadir' },
        { target: 'CANCELLED', label: 'Batalkan' },
    ],
    CHECKED_IN: [
        { target: 'COMPLETED', label: 'Selesai' },
        { target: 'CANCELLED', label: 'Batalkan' },
    ],
};

export const GENDER_LABEL: Record<string, string> = {
    male: 'Laki-laki',
    female: 'Perempuan',
    other: 'Lainnya',
    unknown: 'Tidak diketahui',
};

export function formatDateTime(value?: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleString('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export function formatDate(value?: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleDateString('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

/** URL viewer OHIF untuk satu study (dibuka di tab baru). */
export function ohifStudyUrl(ohifUrl: string | null, studyUid: string): string {
    const base = (ohifUrl ?? 'http://localhost:3000').replace(/\/$/, '');

    return `${base}/viewer?StudyInstanceUIDs=${encodeURIComponent(studyUid)}`;
}

/**
 * Ubah nilai `<input type="datetime-local">` (YYYY-MM-DDTHH:mm) menjadi ISO UTC
 * agar aman dikirim ke API (hindari parsing ambigu di server/Safari).
 */
export function toIsoFromLocal(
    value: string | null | undefined,
): string | null {
    if (!value) {
        return null;
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? null : date.toISOString();
}
