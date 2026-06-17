<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Devventa extends Model
{
    protected $table = 'devventas';
    protected $fillable = ['sucursal_id','venta_id','registro','tranza_id','producto_id','codigo','descripcion','marca','costo','cantidad','total','estado','user_id'];

    public function sucursal() { return $this->belongsTo(Sucursal::class); }
    public function venta() { return $this->belongsTo(Venta::class); }
    public function tranza() { return $this->belongsTo(Tranza::class); }
    public function producto() { return $this->belongsTo(Producto::class); }
}
