<?php
use Phalcon\Mvc\Model;

class Usuariosentidades extends Model
{
    public $id;
    
    public function initialize()
    {
        //Se define relacion de N a 1 con Usuarios
        $this->belongsTo(
            'usuario',
            'Usuarios',
            'id'
        );
        
    } 
}