<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ventadetalle extends Model
{
    use HasFactory;
    protected $table = 'ventadetalles';
    protected $fillable = ['venta_id','producto_id','codigo','descripcion','marca','p_comp','costo','p_norm','p_fact','cantidad','monto','descuento','subtotal','estado'];

    public function venta() { return $this->belongsTo(Venta::class); }
    public function producto() { return $this->belongsTo(Producto::class); }
}
