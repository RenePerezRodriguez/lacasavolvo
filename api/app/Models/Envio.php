<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Envio extends Model
{
    use HasFactory;
    protected $table = 'envios';
    protected $fillable = ['sucursal_id','fecha','cuenta_id','medio_id','monto','pagado','n_dev','observacion','estado'];

    protected $casts = ['fecha' => 'date'];

    public function sucursal() { return $this->belongsTo(Sucursal::class); }
    public function cuenta() { return $this->belongsTo(Cuenta::class); }
    public function medio() { return $this->belongsTo(Medio::class); }
    public function detalles() { return $this->hasMany(Enviodetalle::class); }
}

