<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cierre extends Model
{
    protected $table = 'cierres';
    protected $fillable = ['sucursal_id','apertura_id','fecha','apertura','ingresos','egresos','cierre','user_id','estado'];

    public function user() { return $this->belongsTo(\App\Models\User::class); }
    public function apertura() { return $this->belongsTo(Apertura::class); }
}
