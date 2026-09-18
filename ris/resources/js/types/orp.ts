/**
 * Tipe resource API ORP RIS (lihat `app/Http/Controllers/Api/*`).
 *
 * Catatan penting: relasi Eloquent diserialisasi **snake_case** oleh Laravel
 * (mis. `aiResults` → `ai_results`, `referringDoctor` → `referring_doctor`).
 */

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

export type Patient = {
    id: number;
    mrn: string | null;
    name: string;
    birth_date: string | null;
    gender: string | null;
    phone: string | null;
    address: string | null;
    identity_type: string | null;
    identity_number: string | null;
    facility_id: string | null;
    orders_count?: number;
    orders?: Order[];
    created_at?: string;
};

export type Doctor = {
    id: number;
    name: string;
    specialty: string | null;
    license_number: string | null;
    phone: string | null;
    email: string | null;
    is_radiologist: boolean;
    facility_id: string | null;
};

export type Procedure = {
    id: number;
    code: string;
    name: string;
    modality: string | null;
    body_part: string | null;
    description: string | null;
    is_active: boolean;
};

export type Modality = {
    id: number;
    name: string;
    ae_title: string;
    host: string | null;
    port: number | null;
    modality_type: string | null;
    location: string | null;
    is_online: boolean;
};

export type AppointmentStatus =
    | 'SCHEDULED'
    | 'CONFIRMED'
    | 'CHECKED_IN'
    | 'COMPLETED'
    | 'NO_SHOW'
    | 'CANCELLED';

export type Appointment = {
    id: number;
    order_id: number;
    modality_id: number | null;
    scheduled_at: string;
    status: AppointmentStatus;
    notes: string | null;
    order?: Order;
    modality?: Modality | null;
};

export type OrderStatus =
    | 'REQUESTED'
    | 'SCHEDULED'
    | 'ARRIVED'
    | 'IN_PROGRESS'
    | 'ACQUIRED'
    | 'COMPLETED'
    | 'CANCELLED'
    | 'NO_SHOW'
    | 'REJECTED';

export type OrderPriority = 'STAT' | 'URGENT' | 'ROUTINE';

export type Order = {
    id: number;
    order_number: string;
    accession_number: string;
    patient_id: number;
    referring_doctor_id: number | null;
    procedure_id: number;
    modality_id: number | null;
    priority: OrderPriority;
    status: OrderStatus;
    status_note: string | null;
    requested_at: string | null;
    scheduled_at: string | null;
    completed_at: string | null;
    patient?: Patient | null;
    procedure?: Procedure | null;
    modality?: Modality | null;
    referring_doctor?: Doctor | null;
    studies_count?: number;
    reports_count?: number;
    studies?: Study[];
    reports?: Report[];
    appointments?: Appointment[];
};

export type Study = {
    id: number;
    accession_number: string | null;
    study_instance_uid: string;
    series_instance_uid: string | null;
    sop_instance_uid: string;
    sop_class_uid: string | null;
    modality: string | null;
    study_description: string | null;
    study_date: string | null;
    study_time: string | null;
    patient_id: number | null;
    order_id: number | null;
    file_path: string | null;
    matched: boolean;
    matched_at: string | null;
    order?: Order | null;
    reports?: Report[];
    reports_count?: number;
    ai_results?: AiResult[];
    transmissions?: Transmission[];
    transmissions_count?: number;
    ai_results_count?: number;
};

export type AiResult = {
    id: number;
    study_id: number;
    status: string;
    source: string | null;
    model: string | null;
    threshold: number | null;
    inference_ms: number | null;
    findings: { name: string; probability: number }[] | null;
    tb: Record<string, unknown> | null;
    error: string | null;
    created_at?: string;
};

export type ReportStatus =
    | 'DRAFT'
    | 'DICTATED'
    | 'VERIFIED'
    | 'FINAL'
    | 'CANCELLED';

export type Report = {
    id: number;
    order_id: number;
    study_id: number | null;
    radiologist_id: number | null;
    report_number: string;
    status: ReportStatus;
    findings: string | null;
    impression: string | null;
    addendum: string | null;
    dictated_at: string | null;
    verified_at: string | null;
    finalized_at: string | null;
    radiologist?: { id: number; name: string } | null;
    order?: Order | null;
};

export type TransmissionStatus =
    | 'PENDING'
    | 'SENDING'
    | 'SENT'
    | 'FAILED'
    | 'CANCELLED';

export type Transmission = {
    id: number;
    pacs_source_id: number;
    study_id: number | null;
    order_id: number | null;
    transmission_type: string;
    status: TransmissionStatus;
    attempts: number;
    max_attempts: number | null;
    error: string | null;
    sent_at: string | null;
    next_attempt_at: string | null;
    completed_at: string | null;
    study?: Study | null;
    pacs_source?: PacsSource | null;
};

export type PacsSource = {
    id: number;
    name: string;
    ae_title: string;
    host: string | null;
    port: number | null;
    base_url: string | null;
    qido_url: string | null;
    wado_url: string | null;
    stow_url: string | null;
    username: string | null;
    has_credentials?: boolean;
    is_active: boolean;
    deleted_at: string | null;
    reachable?: boolean | null;
};

export type AuditLog = {
    id: number;
    user_id: number | null;
    action: string;
    auditable_type: string | null;
    auditable_id: string | number | null;
    changes: Record<string, unknown> | null;
    ip_address: string | null;
    created_at: string;
    user?: { id: number; name: string; email: string } | null;
};

export type RoleInfo = {
    name: string;
    permissions: string[];
    is_system: boolean;
};

export type DashboardSections = {
    workflow?: {
        orders_by_status: Record<string, number>;
        awaiting_report: number;
        studies_today: number;
        unmatched_studies: number;
        recent_orders: Order[];
    };
    scheduling?: {
        appointments_today: Record<string, number>;
        next_appointment: Appointment | null;
    };
    reports?: {
        reports_by_status: Record<string, number>;
        my_draft_reports: number;
    };
    pacs?: {
        transmissions_by_status: Record<string, number>;
    };
    patients?: {
        total: number;
        registered_today: number;
    };
    ai?: {
        results_by_status: Record<string, number>;
    };
};
