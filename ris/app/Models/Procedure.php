<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Procedure extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',              // kode prosedur (mis. R-CHEST-1V)
        'name',              // nama prosedur (mis. Chest X-Ray 1 View)
        'modality',          // CR/CT/MR/US/MG/NM...
        'body_part',
        'description',
        'is_active',
        'facility_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}