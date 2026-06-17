<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pedidodetalle extends Model
{
    protected $table = 'pedidodetalles';
    protected $fillable = ['pedido_id','producto_id','codigo','descripcion','marca','cantidad','estado'];

    public function pedido() { return $this->belongsTo(Pedido::class); }
    public function producto() { return $this->belongsTo(Producto::class); }
}
