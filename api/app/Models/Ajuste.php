<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ajuste extends Model
{
    protected $table = 'ajustes';
    protected $fillable = ['sucursal_id','tipo','producto_id','codigo','descripcion','marca','cantidad','observacion','user_id','estado'];

    public function sucursal() { return $this->belongsTo(Sucursal::class); }
    public function producto() { return $this->belongsTo(Producto::class); }
    public function user() { return $this->belongsTo(User::class); }
}
