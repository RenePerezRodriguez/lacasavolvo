<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cotizaciondetalle extends Model
{
    protected $table = 'cotizaciondetalles';
    protected $fillable = ['cotizacion_id','producto_id','codigo','descripcion','marca','p_comp','p_norm','p_fact','costo','cantidad','monto','descuento','subtotal','estado'];

    public function cotizacion() { return $this->belongsTo(Cotizacion::class); }
    public function producto() { return $this->belongsTo(Producto::class); }
}
