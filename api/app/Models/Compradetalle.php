<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Compradetalle extends Model
{
    protected $table = 'compradetalles';
    protected $fillable = ['compra_id','producto_id','codigo','descripcion','marca','costo','p_comp','p_norm','p_fact','cantidad','monto','descuento','subtotal','user_id','estado'];

    public function compra() { return $this->belongsTo(Compra::class); }
    public function producto() { return $this->belongsTo(Producto::class); }
}
