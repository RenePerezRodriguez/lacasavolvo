<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Devenvio extends Model
{
    protected $table = 'devenvios';
    protected $fillable = ['sucursal_id','envio_id','registro','producto_id','codigo','descripcion','marca','cantidad','estado','user_id'];

    public function sucursal() { return $this->belongsTo(Sucursal::class); }
    public function envio() { return $this->belongsTo(Envio::class); }
    public function producto() { return $this->belongsTo(Producto::class); }
}
