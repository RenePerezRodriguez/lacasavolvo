<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cotizacion extends Model
{
    use HasFactory;
    protected $table = 'cotizacions';
    protected $fillable = ['sucursal_id','fecha','cuenta_id','monto','descuento','total','observacion','estado','user_id'];

    protected $casts = ['fecha' => 'date'];

    public function sucursal() { return $this->belongsTo(Sucursal::class); }
    public function cuenta() { return $this->belongsTo(Cuenta::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function detalles() { return $this->hasMany(Cotizaciondetalle::class); }
}

