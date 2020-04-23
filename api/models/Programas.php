<?php
use Phalcon\Mvc\Model;

class Programas extends Model
{
    public $id;
    
    public function initialize()
    {
        //Se define la relación con 1 a N con Modalidades
        $this->hasMany(
            'id',
            'Modalidades',
            'programa'
        );
        
        //Se define la relación con 1 a N con Tiposeventos
        $this->hasMany(
            'id',
            'Tiposeventos',
            'programa'
        );
        
        //Se define la relación con 1 a N con Convocatorias
        $this->hasMany(
            'id',
            'Convocatorias',
            'programa'
        );   
        
        //Se define la relación con 1 a N con Encuestas
        $this->hasMany(
            'id',
            'Encuestas',
            'programa'
        );
    }  
    
    
}