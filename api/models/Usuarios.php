<?php

use Phalcon\Mvc\Model;

class Usuarios extends Model
{
    public $id;   
    
    public function initialize()
    {
        //Se define la relación con 1 a N con usuariosperfiles
        $this->hasMany(
            'id',
            'Usuariosperfiles',
            'usuario'
        );    
        
        //Se define la relación con 1 a N con usuariosentidades
        $this->hasMany(
            'id',
            'Usuariosentidades',
            'usuario'
        );
        
        //Se define la relación con 1 a N con usuarioareas
        $this->hasMany(
            'id',
            'Usuariosareas',
            'usuario'
        );
    }  
    
}