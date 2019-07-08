<?php
use Phalcon\Mvc\Model;

class Paises extends Model
{
    public $id;
    
    public function initialize()
    {
        //Se define la relación con 1 a N con Departamentos
        $this->hasMany(
            'id',
            'Departamentos',
            'pais'
        );                        
    }  
    
}