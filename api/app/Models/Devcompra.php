<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Devcompra extends Model
{
    protected $table = 'devcompras';
    protected $fillable = ['sucursal_id','compra_id','registro','tranza_id','producto_id','codigo','descripcion','marca','costo','cantidad','total','estado','user_id'];

    public function sucursal() { return $this->belongsTo(Sucursal::class); }
    public function compra() { return $this->belongsTo(Compra::class); }
    public function tranza() { return $this->belongsTo(Tranza::class); }
    public function producto() { return $this->belongsTo(Producto::class); }
}
