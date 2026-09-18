<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Peta MPPS SOPInstanceUID → AccessionNumber/Order (M7).
 *
 * Modalitas selalu mengirim SOPInstanceUID pada N-CREATE (AffectedSOPInstanceUID)
 * dan N-SET/N-ACTION (RequestedSOPInstanceUID), tetapi AccessionNumber hanya
 * dijamin ada pada N-CREATE. Tabel ini menyimpan pemetaan tersebut agar N-SET
 * yang datang tanpa accession (mis. setelah adapter/worker restart) tetap bisa
 * ditautkan ke order — menggantikan state in-memory milik adapter.
 */
class MppsRecord extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'sop_instance_uid',
        'accession_number',
        'order_id',
        'status',
        'modality',
        'performed_started_at',
        'performed_ended_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'performed_started_at' => 'datetime',
            'performed_ended_at'   => 'datetime',
            'last_seen_at'         => 'datetime',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /** AccessionNumber efektif: dari kolom sendiri atau dari order yang ditautkan. */
    public function resolvedAccession(): ?string
    {
        return $this->accession_number ?: $this->order?->accession_number;
    }
}
