<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SolicitudPassword extends Model
{
    protected $table = 'solicitudes_password';

    protected $fillable = [
        'user_id',
        'atendida_en',
    ];

    protected function casts(): array
    {
        return [
            'atendida_en' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
