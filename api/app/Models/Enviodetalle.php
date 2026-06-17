<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Enviodetalle extends Model
{
    protected $table = 'enviodetalles';
    protected $fillable = ['envio_id','producto_id','codigo','descripcion','marca','cantidad','estado'];

    public function envio() { return $this->belongsTo(Envio::class); }
    public function producto() { return $this->belongsTo(Producto::class); }
}
