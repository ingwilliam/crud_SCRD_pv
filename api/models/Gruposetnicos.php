<?php
use Phalcon\Mvc\Model;

class Gruposetnicos extends Model
{
    public $id;
    
    public function initialize()
    {        
        //Se define la relación con 1 a N con participantes
        $this->hasMany(
            'id',
            'Participantes',
            'grupo_etnico'
        );  
        
        
    }
}