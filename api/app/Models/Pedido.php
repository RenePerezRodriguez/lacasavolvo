<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class Pedido extends Model
{
    use HasFactory;
    protected $table = 'pedidos';
    protected $fillable = ['sucursal_id','fecha','observacion','user_id','estado','impresion'];

    protected $casts = ['fecha' => 'date'];

    public function sucursal() { return $this->belongsTo(Sucursal::class); }
    public function user()     { return $this->belongsTo(User::class); }
    public function detalles() { return $this->hasMany(Pedidodetalle::class); }
}

