<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Precio extends Model
{
    protected $table = 'precios';
    protected $fillable = ['tipo','registro','producto_id','p_comp_orig','p_comp','p_norm_orig','p_norm','p_fact_orig','p_fact','user_id'];

    public function producto() { return $this->belongsTo(Producto::class); }
    public function user() { return $this->belongsTo(User::class); }
}
