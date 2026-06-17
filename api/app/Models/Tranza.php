<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tranza extends Model
{
    protected $table = 'tranzas';
    protected $fillable = ['sucursal_id','cuenta_id','fecha','tipo','clase','registro','descripcion','monto_ingreso','monto_egreso','user_id','estado'];
    public function sucursal() { return $this->belongsTo(Sucursal::class); }
    public function cuenta() { return $this->belongsTo(Cuenta::class); }
}

